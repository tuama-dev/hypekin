# Build Plan: AI Caption & Hashtag Generator

Locked plan for the AI-assisted caption/hashtag feature on the post composer, built on the
first-party **Laravel AI SDK** (`laravel/ai`). Status/decisions in this file are authoritative
until the feature ships; refresh `docs/PROJECT_STATUS.md` when done.

---

## Decisions locked in this session

- **Use the Laravel AI SDK, not raw HTTP.** `laravel/ai` is the first-party unified client for
  OpenAI/Anthropic/Gemini/etc. Provider switching is an env/config one-liner and it ships
  first-class **structured output** (`HasStructuredOutput` + `schema()`) and **agent fakes**
  (`CaptionGenerator::fake()`, `assertPrompted()`) for clean tests.
- **Default provider: OpenAI**, model pinned via env-overridable config default
  (`OPENAI_MODEL`, default `gpt-4o-mini`). Provider is swappable at any time by changing the
  `config/ai.php` provider block + env key (`GEMINI_API_KEY`, `ANTHROPIC_API_KEY`, …) — no code
  change; `Lab` enum / per-call `provider:` and failover `provider: [Lab::OpenAI, Lab::Gemini]`
  are available later.
- **Model pinning:** explicit `gpt-4o-mini` (via config default), not `UseCheapestModel` — the
  SDK docs warn the "cheapest" alias can silently change models between package releases; pinned
  keeps cost/predictability stable.
- **Hashtags = per-platform sets, single shared caption.** The composer has ONE caption copied
  to every target on store. The AI returns `hashtags` keyed by platform (IG ≈10+, TikTok 5–8,
  FB 3–5, LinkedIn 2–3); the user picks which platform's set to **insert** into the caption.
  No data-model change. (Per-target hashtag storage deferred.)
- **Text-only v1.** No image/vision context. User describes the post in a brief. Vision is a
  follow-up (SDK attachments already support `Files\Image`).
- **Rate-limit the endpoint:** `Limit::perMinute(6)` keyed `user|workspace` (route throttle
  `throttle:ai-caption`) to control cost/spam.
- **Stateless single-shot agent.** No conversation storage needed; the SDK's published
  `agent_conversations*` tables are framework-owned and simply unused for this feature
  (they power future "continue this caption" memory).
- **Community nota**: the earlier `app/Actions/Ai` contract idea is abandoned in favor of the
  SDK. Agents live in `app/Ai/Agents` per `make:agent` convention.

---

## 1. Dependency + setup

- `composer require laravel/ai`
- `php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"` → `config/ai.php`
    - migrations (`agent_conversations`, `agent_conversation_messages`).
- `php artisan migrate` — creates the two framework-owned conversation tables (unused by this
  stateless agent; safe and future-proof).

## 2. Configuration

- `config/ai.php`: `providers.openai` (`driver` `openai`, `key` `env('OPENAI_API_KEY')`,
  `models.text.default` `env('OPENAI_MODEL', 'gpt-4o-mini')`). Default provider points at
  `openai`.
- `.env.example`: `OPENAI_API_KEY=` and `OPENAI_MODEL=gpt-4o-mini`, plus a commented
  swap block (`GEMINI_API_KEY`, `ANTHROPIC_API_KEY`) showing provider-switch alternatives.

## 3. Agent — `app/Ai/Agents/CaptionGenerator`

- `php artisan make:agent CaptionGenerator --structured`.
- Implements `Agent` + `HasStructuredOutput`; uses `Promptable`.
- `__construct(private ?string $tone, private array $platforms)`.
- `instructions(): string` — system prompt interpolating the tone + per-platform hashtag rules
  (counts per platform, hashtags formatted `#tag` no spaces/in-caption hashtags, TikTok `title`
  ≤22 chars only when TikTok is among `platforms`).
- `schema(JsonSchema $schema): array` —
  `caption` string required · `title` string optional · `hashtags` object
  (one array-of-strings key per platform) required.
- Attributes: `#[Temperature(0.9)]`, `#[MaxTokens(600)]`, `#[Timeout(45)]`. Model comes from
  the provider config default (env-overridable), not a hardcoded attribute.
- Consumption: `$response = (new CaptionGenerator($tone, $platforms))->prompt($brief);` then
  array-access `$response['caption']` / `['title']` / `['hashtags']`. (`$response->usage`
  available for optional cost logging later.)

## 4. Endpoint + controller

- Route `POST app/{workspace:slug}/posts/ai-caption` → named `workspace.posts.ai-caption`,
  middleware `['verified', 'workspace', 'throttle:ai-caption']`.
- `App\Http\Controllers\Application\AiCaptionController::generate` (CalendarController
  precedent) returns JSON `{ caption, title?, hashtags }`.
- `AiCaptionRequest` (`app/Http/Requests/Post/AiCaptionRequest.php`): `brief` required string
  ≤500; `tone` nullable in `casual|professional|engaging|funny|informative`; `platforms`
  nullable array of publishable platform values (defaults to all publishable).
- Rate limiter named `ai-caption` (defined in `AppServiceProvider`/provider bootstrap):
  `Limit::perMinute(6)->by($request->user()?->getKey().'|'.$request->route('workspace')->getKey())`.
- Error mapping (catch `Throwable` in the controller):
    - Missing default-provider key → 422 `{ error: "AI captioning isn't configured." }`.
    - `RateLimitedException` / `ProviderOverloadedException` / generic → 503 friendly message.
    - `TooManyRequestsHttpException` from the limiter → 429 (handled by Laravel).
- No DB writes.
- `PostController::create` passes `ai_enabled =>` (default-provider key present) so the
  composer hides the AI box when unconfigured.

## 5. Frontend

- New `resources/js/components/Posts/AiCaptionBox.tsx` (component conventions follow
  `AccountTargetPicker`):
    - Brief textarea (≤500) + tone chips + **Generate** button with loading spinner (disabled
      while in flight).
    - Result: draft caption preview; TikTok **title** field shown when a TikTok target is
      selected; per-platform hashtag chips with an insert selector.
    - Actions: **Use caption** (`form.setData('caption', …)`), **Use title**, **Insert
      hashtags** (selected platform's set, respect `MAX_CAPTION_LENGTH`), **Regenerate**,
      **Dismiss**.
    - XSRF `fetch` via Wayfinder URL (media-upload pattern); `sonner` toasts for errors/429;
      visible only when the composer's `ai_enabled` prop is true.
- `Create.tsx`: render the box near the Caption section; wire target selection (`platforms`
  derived from `selectedAccounts`) into the payload.

## 6. Tests (`tests/Feature/AiCaptionTest.php`)

- **Happy path** via `CaptionGenerator::fake([['caption' => …, 'title' => …, 'hashtags' =>
[…]]])` (SDK structured fakes accept schema-shaped arrays) → assert JSON mapping.
- **Prompt assertions:** `CaptionGenerator::assertPrompted(...)` — brief included; second
  arg/`assertPrompted` on prompt content.
- **Shape test:** fake without explicit output (SDK auto-generates matching the schema).
- Validation: `brief` required/too long, bad `tone`, invalid `platforms`.
- Throttle → 429 after 6 requests.
- Unconfigured provider key (config nulled) → 422.
- Membership: non-member of the workspace → 404.
- No DB rows created by the endpoint.
- `ai_enabled` prop present/absent with/without the provider key.

## 7. Verification

- `vendor/bin/pint --dirty --format agent`
- `php artisan test --compact`
- `npm run types:check` and `npm run build`
- UI smoke: box hidden when key absent; visible when present; faked/stubbed response fills
  caption + hashtags. Live provider calls are exercised only when a real key exists.

---

## Deferred (explicitly out of scope)

- Image/vision-aware captions (SDK `Files\Image` attachments — additive later).
- Per-target caption/hashtag storage (would need `post_targets.hashtags` + per-target editing).
- Streaming generation / SSE (SDK `stream()` + protocol support is additive).
- Provider failover chains (`provider: [Lab::OpenAI, Lab::Gemini]`) until a second key exists.
- Conversation memory ("continue this caption") via the SDK's conversation tables.
- Cost tracking from `$response->usage` (future analytics; settings accommodated later).
