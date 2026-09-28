# Project Status — Where We Are Now

Last updated: 2026-09-23
Focus: **the customer-facing app**. Platform admins are deferred (see `docs/admins-roles-permissions-plan.md`) and not built yet.

---

## 1. Stack

| Layer        | Technology                                                                                                        |
| ------------ | ----------------------------------------------------------------------------------------------------------------- |
| Framework    | Laravel 13 (PHP 8.5)                                                                                              |
| Frontend     | Inertia v3 + React 19 + TypeScript, Vite 8 (`vite-plus`), Tailwind 4                                              |
| Routing glue | Laravel Wayfinder (typed route/controller functions)                                                              |
| Auth         | Native Laravel sessions (`web` guard), Laravel Socialite (google, facebook, x, linkedin-openid)                   |
| Email        | Laravel verification flow, queued (`QUEUE_CONNECTION=database`)                                                   |
| Tests        | Pest 5 (feature tests)                                                                                            |
| DB           | MySQL (local). **Note:** `phpunit.xml` targets SQLite `:memory:` — tests can't run in this env (no `pdo_sqlite`). |
| Lint/CI      | Pint, PHPStan (larastan), `tsc --noEmit`, `vp check`                                                              |

---

## 2. What's done (progress)

### Auth & accounts

- Email + password registration (validated via `RegisterRequest`), login, logout.
- Social login with Google / Facebook / X / LinkedIn (OAuth via Socialite), account linking for authenticated users, provider whitelist, placeholder email for providers that don't return one.
- **Email verification** for email registration: registration auto-logs in → `Registered` event → queued `SendEmailVerificationNotification` listener → `VerifyEmail` mail (queue); notice/verify/resend routes + pages; 60s resend cooldown with live countdown (`config/verification.php`); `flash.success`/`flash.error`. `User` implements `MustVerifyEmail` and dashboard is behind `verified`.
- Email verification is **not** (yet) enforced on the dashboard route.

### Workspaces (tenant per user)

- A user owns **exactly one** workspace by default, guaranteed **at registration, at login, and at social sign-in** (`CreateWorkspaceAction::execute` / `::ensure`).
- Ownership is modeled through a **role pivot** `workspace_user` (`role` column, `WorkspaceRole` enum: `owner` / `admin` / `editor` / `viewer`).
- `Workspace` exposes `users()`, `owner()`, `admins()`, `editors()`, `viewers()` relationships. The future invite/join flow (making someone an `admin`/`editor`/`viewer` of _another_ workspace) is **not built** — only personal workspaces exist.
- Dashboard URL is `app/{workspace-slug}/dashboard` (`{workspace:slug}` binding, `EnsureWorkspaceMembership` middleware — 404s non-members) with `verified` enforced.
- The authenticated **Sidebar header is a workspace switcher** (name + chevron → dropdown of your workspaces w/ role), sourcing shared props `auth.workspace`/`auth.workspaces`.
- **Workspace settings** live at `app/{workspace-slug}/settings` (`workspace.settings` / `workspace.settings.update`), linked from the Sidebar so they always target the active workspace. Owners/admins can rename (name + unique slug); the form is a Wayfinder `PUT`. Read-only details (slug, your role, member count, created-on) are also shown.
- Spatie roles/permissions were **completely removed** (package, config, tables migration, seeders, `HasRoles` trait). Workspace roles live only in the pivot.

### Frontend pages (Inertia)

- `Welcome` (marketing) and a Blade `marketing` view for `/`.
- `Application/Auth/*`: `Login`, `Registration`, `VerifyEmail`.
- `Application/Dashboard` (+ `AuthenticatedLayout` + `Navbar` with logout).
- `auth.user`, `auth.workspace`(n/a yet), `flash.error`, `flash.success` shared props.

---

## 3. Database schema (current)

| Table                  | Purpose                                                                        |
| ---------------------- | ------------------------------------------------------------------------------ |
| `users`                | `fullname`, `email`, `password`, `email_verified_at`, remember token           |
| `user_oauth_providers` | OAuth provider → user linkage (`provider_name`, `provider_id`, tokens, avatar) |
| `workspaces`           | `name`, `slug` (unique) — no owner FK anymore                                  |
| `workspace_user`       | membership pivot: `workspace_id`, `user_id`, `role`, unique pair               |
| `jobs` / `cache`       | queue + cache infrastructure                                                   |

Enum: `App\Enums\WorkspaceRole` — `owner`, `admin`, `editor`, `viewer`.

---

## 4. Complete current flows

### 4.1 Email registration

```
POST /register  (RegisterRequest: fullname, unique email, password≥8 + confirmed)
  RegistrationController@store
  └─ RegistrationAction::execute()
      1. User::create(fullname, email, password)      // password hashed via cast
      2. CreateWorkspaceAction::execute($user)        // "X's Workspace", unique slug
         └─ workspace_user: user attached with role=owner
      3. event(Registered($user))
         └─ SendEmailVerificationNotification (queued listener)
            └─ sendEmailVerificationNotification()    // VerifyEmail queued on database queue
  └─ Auth::login($user) + session()->regenerate()     // ★ auto-login
  └─ session: verification_sent_at = now()            // starts the 60s resend cooldown
  └─ redirect → verification.notice (VerifyEmail.tsx) + flash.success
     Unverified access to /app/{workspace}/dashboard is blocked by `verified` middleware
                 → bounced back to verification.notice
```

### 4.2 Social registration / sign-in

```
GET /auth/{provider}/redirect  → provider OAuth
GET /auth/{provider}/callback  → SocialAuthController@callback
  └─ SocialAuthAction::execute(provider, socialiteUser)
      1. Already authenticated?  → link account to current user (no new user)
      2. UserOauthProvider row exists?  → return its user
      3. else findOrCreateUser()
         ├─ found by email        → return existing
└─ new → User::create (+ placeholder email if none, email_verified_at = now()
                        — OAuth proves identity even without a real inbox)
           CreateWorkspaceAction::execute (role=owner)
  └─ Auth::login($user)  + session regenerate
  └─ CreateWorkspaceAction::ensure($user)  // safety net
  └─ redirect → workspace.dashboard (`app/{workspace-slug}/dashboard`)
```

### 4.3 Login

```
POST /login  (AuthRequest: email, password, remember)
  AuthController@auth
  ├─ Auth::attempt fails → back with error flash
  ├─ session()->regenerate()
  ├─ CreateWorkspaceAction::ensure($user)   // guarantees ≥1 workspace (owner)
  └─ redirect()->intended(workspace.dashboard) — first workspace's slug
     └─ unverified user → `verified` middleware → redirected to verification.notice
```

### 4.4 Email verification

```
GET  /email/verify                          → notice page (VerifyEmail.tsx) unless already verified
GET  /email/verify/{id}/{hash}   (signed)   → EmailVerificationController@verify → fulfill → dashboard
POST /email/verification-notification       → resend, throttled + 60s cooldown (session-tracked)
```

Resend cooldown: after a link is sent, resend is locked for `config('verification.resend_cooldown')` (60s)
seconds. The notice page counts down from the shared `verification.resend_available_at` prop and
re-enables the button when the timer ends; the server validates the cooldown independently.

### 4.5 Logout

```
POST /app/logout → Auth::logout, session invalidate + regenerate token → Inertia::location(home)
```

---

## 5. Known gaps & technical debt

1. ~~No auto-login after registration~~ **Resolved** — registration auto-logs the user in and redirects to `verification.notice` (VerifyEmail.tsx) with a 60s resend cooldown.
2. **Tests can't run in this env** — `phpunit.xml` uses SQLite `:memory:` but local PHP has only `pdo_mysql`. **Workaround confirmed**: `DB_CONNECTION=mysql DB_DATABASE=lareact_test php artisan test` runs the whole suite (31/31 passing) against a throwaway MySQL DB. Officially defaulting phpunit.xml to MySQL is still pending user approval.
3. ~~Stale test route~~ **Resolved** — `LoginTest` posts to `login.auth`.
4. ~~`verified` middleware not applied~~ **Resolved** — dashboard is behind `verified`; unverified users land on `verification.notice`. Logout stays reachable for unverified users.
5. **Pending migrations** — `workspaces` + `workspace_user` need `php artisan migrate` (MySQL) and a `queue:work` worker for verification emails; set `MAIL_MAILER=log` locally. (Deferred — §6.)
6. **Dashboard is a stub** — shows the current workspace name + role and the switcher, but no real workspace data. (Deferred — §6.)

---

## 6. Suggested next steps (focus on the app; admin later)

Short-term (unblock basics & make it demoable):

1. ~~Post-registration UX~~ **Done** — auto-login + redirect to `verification.notice` with a 60s resend countdown.
2. ~~Verify enforcement~~ **Done** — `verified` middleware on `workspace.dashboard`.
3. **Fix the test environment** — either `apt/brew` install `pdo_sqlite` or switch `phpunit.xml` to MySQL. (Stale `login.store` already fixed.) Then run `php artisan test --compact` and the CI scripts.
4. ~~Workspace switcher + slug URLs~~ **Done** — `app/{workspace-slug}/dashboard`, Sidebar header switcher, `EnsureWorkspaceMembership`.
5. **Apply pending migrations** — `php artisan migrate` (MySQL) + `php artisan queue:work` + `MAIL_MAILER=log` to see the verification email end-to-end.

Product building blocks (after the above): 6. **Define what lives _inside_ a workspace** — this is the core product decision. Pick the first vertical slice (e.g. projects/customers/files whatever the app domain is), then scaffold models + migration + CRUD with the existing Action/pivot conventions. 7. **Workspace UX** — invite/join flow so a user becomes `admin`/`editor`/`viewer` of someone else's workspace (posts the foundation in `docs/admins-roles-permissions-plan.md` §3, but that's tenant-level; an invite system is separate). Keep scoped to the app. 8. **Payments/plans gating multi-workspaces** — the eventual "own 1, subscribe for more" story. Don't build until the core product slice exists. 9. **Keep the codebase conventions**: Actions in `app/Actions/Application/**`, `execute()` methods, `#[Fillable]` attributes, enum-driven roles, Pest feature tests, Wayfinder typed routes, Pint + PHPStan + `tsc`.

Deferred (explicitly): platform admins, admin auth guard, gates/policies, impersonation — see `docs/admins-roles-permissions-plan.md`.

---

## 7. Reference map

- Routes: `routes/web.php` (auth, social, verify, dashboard/logout)
- Auth actions: `app/Actions/Application/Auth/*`, workspace: `app/Actions/Application/Workspace/CreateWorkspaceAction.php`
- Controllers: `app/Http/Controllers/Application/**`
- Models: `app/Models/{User,Workspace,UserOauthProvider}.php`, enum `app/Enums/WorkspaceRole.php`
- Config: `config/verification.php` (resend cooldown)
- Pages: `resources/js/pages/Application/**`, layouts/components in `resources/js/components/**`
- Tests: `tests/Feature/{RegistrationTest,SocialAuthTest,LoginTest,EmailVerificationTest}.php`
- Docs: this file + `docs/admins-roles-permissions-plan.md`
