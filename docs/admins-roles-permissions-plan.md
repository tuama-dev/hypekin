# Platform Admins — Roles & Permissions Plan

Status: **Design / not yet implemented**
Scope: Platform **staff** (the people who operate the SaaS: manage users, workspaces, billing, support), **not** workspace members.

---

## 1. The three layers (keep them separate)

| Layer                 | Where it lives                | Model                | Purpose                                                                     | State                       |
| --------------------- | ----------------------------- | -------------------- | --------------------------------------------------------------------------- | --------------------------- |
| Per-workspace roles   | `workspace_user.role` (pivot) | `WorkspaceRole` enum | What a member can do _inside a workspace_ (owner / admin / editor / viewer) | ✅ Implemented              |
| App-global user roles | _(removed)_                   | —                    | Former Spatie role system                                                   | ❌ Deleted (Spatie removed) |
| **Platform admins**   | `admins` table _(future)_     | `AdminRole` enum     | Who runs the whole app, across all workspaces                               | 🟡 This plan                |

These three layers must never be merged. A user is an owner of their workspace **and** possibly an admin of the platform **and** possibly a member (editor/viewer) of someone else's workspace — three independent facts.

---

## 2. Key decisions (why no Spatie)

1. **No Spatie.** `spatie/laravel-permission` was removed from the project. Replacing it with a tiny, app-owned model.
2. **`admins` = a separate table with its own auth guard**, not a column/role on `users`. Staff credentials and sessions stay fully isolated from customer accounts.
3. **A single `role` enum column** (`super_admin`, `moderator`) is the authorization primitive for v1. Not a permission matrix.
4. **Laravel-native Gates + Policies** for capability checks, and the `can:` route middleware. No third-party package needed.
5. **A permission catalog is explicitly deferred (v2)** — the design leaves room for it, without building it now.

Keep it simple until a real requirement forces granularity.

---

## 3. Database design

### 3.1 `admins` table (v1)

```php
Schema::create('admins', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->string('email')->unique();
    $table->string('password');
    $table->string('role');              // AdminRole enum value
    $table->timestamp('last_login_at')->nullable();
    $table->rememberToken();
    $table->timestamps();
});
```

Column notes:

| Column                  | Purpose                                                                                                                                                               |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `user_id` (nullable FK) | Optional link so an admin can also exist as a customer (e.g. impersonate / act as a real user). `nullOnDelete` so deleting a customer never deletes the staff record. |
| `email` + `password`    | Staff credentials, hashed with the `hashed` cast — **independent** of the user's account.                                                                             |
| `role`                  | String from the `AdminRole` enum (single source of truth in code).                                                                                                    |
| `last_login_at`         | Audit helpfulness; optional.                                                                                                                                          |

### 3.2 `AdminRole` enum (`app/Enums/AdminRole.php`)

```php
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Moderator  = 'moderator';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
```

### 3.3 Future permission catalog (v2 — not built now)

Only if a requirement demands sub-role capability control (e.g. "moderators can only handle support"):

```php
// catalog of everything an admin can do (human-managed, read from config or a table)
admin_permissions: id, name, slug(unique), guard_name
// pivot: which admins (or role) hold which permissions
admin_permission: id, admin_id, permission_id          // per-admin grants
// or role-based matrix instead of per-admin rows:
admin_role_permission: id, role, permission_id         // role → permissions
```

Resolution order if both exist: **per-admin grants override role-based ones; a super_admin bypasses all checks.** This is the algorithm for that phase (see §7.3).

---

## 4. Authentication design

### 4.1 Separate guard

Add an `admin` guard + provider to `config/auth.php` (mirrors the existing `web` guard pattern):

```php
'guards' => [
    'web'   => ['driver' => 'session', 'provider' => 'users'],
    'admin' => ['driver' => 'session', 'provider' => 'admins'],
],

'providers' => [
    'users'  => ['driver' => 'eloquent', 'model' => App\Models\User::class],
    'admins' => ['driver' => 'eloquent', 'model' => App\Models\Admin::class],
],
```

### 4.2 `Admin` model

`app/Models/Admin.php` extends `Illuminate\Foundation\Auth\User` (Authenticatable), with:

- `#[Fillable(['user_id', 'email', 'password', 'role'])]`, `#[Hidden(['password', 'remember_token'])]`
- `password` cast to `hashed`
- `role()` cast to `AdminRole`
- helpers:

```php
public function isSuperAdmin(): bool;   // $this->role === AdminRole::SuperAdmin
public function canManage(string $capability): bool;  // delegates to Gate
```

### 4.3 Routes & middleware

Admin UI lives under `/admin`, its own session namespace so staff and customer cookies never collide:

```php
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['web', 'auth:admin'])
    ->group(function () {
        // dashboard, users, workspaces, billing, support
    });
```

`web` middleware group must be applied because the guard uses session driver.

### 4.4 Admin login flow (algorithm)

```
POST /admin/login
  1. Validate {email, password} (form request).
  2. Auth::guard('admin')->attempt($credentials, $remember)
     └─ false  → back() with error flash "Invalid admin credentials"
     └─ true   → session regenerate; update admins.last_login_at
  3. redirect()->intended(route('admin.dashboard'))
```

Seed a first super-admin via a `AdminSeeder` (e.g. `devadmin@dev.com`), with a flag like "skip if an admin already exists" so it's idempotent.

---

## 5. Authorization design (v1)

### 5.1 Capabilities

Named capabilities are the vocabulary used everywhere (`Gate` checks, `can:` middleware, later the v2 permission table):

| Capability          | Meaning                                    |
| ------------------- | ------------------------------------------ |
| `view-admin-panel`  | Enter the admin UI at all                  |
| `manage-users`      | Activated/disable/delete customer accounts |
| `manage-workspaces` | Delete/suspend workspaces, review members  |
| `manage-billing`    | Invoices, refunds, plan changes            |
| `manage-support`    | Read tickets / customer context, reply     |

### 5.2 Role → capability mapping (v1)

Hard-coded in one place (e.g. `config/admin.php` or the role-checking service):

```php
SuperAdmin => [view-admin-panel, manage-users, manage-workspaces, manage-billing, manage-support]
Moderator  => [view-admin-panel, manage-support]
```

### 5.3 Gates

Registered in a service provider. Each check is role-based for v1 (`ability` ← role lookup), a single seam that v2 replaces with the permission catalog:

```php
Gate::forUser('admin')  // scope preferred

Gate::define('manage-users', fn (Admin $admin) => AdminPermissions::allows($admin, 'manage-users'));
// resolves via: role capability map (v1) or admin_permission pivot (v2)
```

### 5.4 Enforcement points

- Route-level: `->can('manage-users')` middleware on admin routes.
- Controller-level: `$this->authorize('manage-users')` in admin policies.
- In UI: `@can` / `can()` checks to hide actions the admin cannot perform.

### 5.5 Request authorization algorithm (v1)

```
isAdminRequest($request):
  1. Guard 'admin' authenticated?
       no  → 401 / redirect to /admin/login
  2. Determine capability from route (routing + policy + gate).
  3. resolveAdminCapabilities($admin): AdminRoleSuperAdmin → ALL
                                     else → map[admin.role]
  4. capability ∈ resolved set?
       yes → allow
       no  → 403
```

---

## 6. Primary flows

### 6.1 Creating an admin

```
CLI: php artisan admin:create --email=x --name=... [--role=moderator] (console command)
  Guards: reject if email exists; default role moderator (super_admin only via flag).
  Store: admins { email, password(hashed), role }
```

No UI for creating admins in v1 — staff management stays CLI/raw (small, trusted group).

### 6.2 An admin's day (sequence)

```
login → /admin/dashboard
     → list users          (Gate: manage-users)     → 403 if moderator
     → open user, suspend  (Gate: manage-users)     → 403 if moderator
     → view support queue  (Gate: manage-support)   → ok for moderator
     → billing/refund      (Gate: manage-billing)   → 403 if moderator
```

### 6.3 Impersonation (optional, v1.5)

Super-admin "Log in as user" to reproduce issues:

```
POST /admin/impersonate {user_id}
  Guard: admin has manage-users
  Store original admin in session ('impersonator_admin_id')
  Session::rebind to web guard user
  Every dashboard/navbar shows "Viewing as customer — Return to admin"
POST /admin/impersonate/leave
  Restore admin session from 'impersonator_admin_id', forget key
```

Safeguards: never impersonate a super-admin/infrastructure account; log every impersonation event (`last_login_at`-style audit row).

---

## 7. v2 permission catalog (deferred, design reserved)

When sub-role capability control is required:

1. Introduce `admin_permissions` + `admin_permission` (per-admin) and/or `admin_role_permission` (role-based) tables (§3.3).
2. Replace the role lookup in the Gate seam with the catalog resolver:

```
resolveAdminCapabilities($admin):
  if admin.role == SuperAdmin  → ALL (bypass)
  if per-admin grants exist    → union(roleCapabilities, adminPermissionRows)
  else                         → roleCapabilities(admin.role)
```

3. Build a small permissions management screen (super-admin only) to bind capabilities to roles/admins — or keep it in a seeder and avoid the UI until demanded.

---

## 8. Implementation checklist (when the feature starts)

- [ ] Migration: `admins` table (+ `admin` guard/provider in `config/auth.php`)
- [ ] `App\Enums\AdminRole` + `Admin` model (helpers + casts)
- [ ] Admin login controller/routes + Inertia login page + `admin.*` route group
- [ ] Admin dashboard page
- [ ] Capability map (`config/admin.php`) + Gates/service provider
- [ ] `AdminSeeder` (idempotent super admin) + `admin:create` console command
- [ ] Tests: admin login, 403 enforcement per role, capability mapping unit test
- [ ] (later) impersonation flow

---

## 9. Testing intent

- Login: valid/invalid/missing-role, session regeneration.
- Authorization per capability × per role (moderator blocked from `manage-users`, `manage-billing`; super-admin allowed everywhere).
- Guard isolation: a customer session must not access `/admin/*` and vice-versa.
- Seeder idempotency.
