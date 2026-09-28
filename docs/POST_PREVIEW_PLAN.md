# Build Plan: Per-Platform Post Preview (Composer)

Locked plan for the per-platform live preview in the post composer. This is the
reviewed/approved version addressing platform constraints, account identity, tab
synchronization, responsiveness, typography, and forward compatibility for new
platforms. Refresh `docs/POSTS_PUBLISHING_PLAN.md` when the slice ships.

---

## Decisions locked in this session

- **Platform-agnostic, registry-driven.** The preview must NOT be a hardcoded
  `if facebook / if instagram / ...` switch. A single typed
  `PlatformPreviewDefinition` registry drives renderers; the composer, layout,
  and tab logic never change when a platform is added.
- **Backend already anticipates this.** `App\Enums\Platform` declares
  `Facebook`, `Instagram`, `LinkedIn`, `Tiktok` (app/Enums/Platform.php). The
  accounts page enumerates `Platform::cases()`, and platform props flow to the
  frontend as `{ value: string; label: string }` — no frontend hardcoding.
- **Sources = selected targets.** The preview platform set is derived from the
  checked accounts (`form.data.targets` → account → `platform.value`). Only
  publishable platforms (Facebook/Instagram today; guarded by `StorePostRequest`)
  can ever be selected, but the preview handles any platform value gracefully.
- **One registry entry per platform.** Adding TikTok, LinkedIn, X, Threads, etc.
  later = add ONE object to the registry file. No edits to `Create.tsx`.
- **Layout primed for it.** The composer pane is full width with the inputs
  capped; the two-column split was chosen earlier explicitly so a per-platform
  preview pane can sit beside the form.
- **All four platforms rendered now.** Facebook, Instagram, LinkedIn, and TikTok
  mockups ship in the registry today, even though only FB/IG are publishable —
  so the design language is visible up front and future wiring is purely backend.

---

## 1. Renderer registry (`resources/js/components/Preview/PostPreview.tsx`)

The core. A strictly typed definition per platform; unknown platforms fall back
to a generic card.

```ts
interface PlatformPreviewDefinition {
    id: string; // 'facebook' | 'instagram' | 'linkedin' | 'tiktok'
    label: string; // 'Facebook'
    icon: ComponentType<SVGProps<SVGSVGElement>>; // brand icon
    requiresMedia: boolean; // IG/TikTok reject text-only
    maxCharacters: number; // per-platform caption cap
    render: (props: PreviewRenderProps) => ReactElement;
}

interface PreviewRenderProps {
    caption: string;
    imageUrl: string | null;
    authorName: string; // resolved below (account vs workspace)
    platformLabel: string;
    requiresMedia: boolean; // for the in-frame notice
}
```

- Default/fallback renderer for any `id` without a custom entry: header with the
  platform label + icon, caption, image, and a neutral action row — so a brand-new
  platform is never a blank card.
- Placeholder brand icons (Instagram, LinkedIn) added as small inline SVGs next to
  the existing `FacebookIcon`/`TikTokIcon` in `SocialButtons.tsx` (or a shared
  `PlatformIcons` helper) so tabs and fallback cards carry the brand.

## 2. Composer layout (`Create.tsx`)

- Widen the page container from `max-w-4xl` to a full-width gutter pane
  (`px-4 py-8 sm:px-6`) so the side-by-side split fits.
- Large screens (`lg:grid lg:grid-cols-[minmax(0,1fr)_26rem] lg:items-start lg:gap-8`):
    - **Left:** existing form sections (Caption, Publish to, Image, Schedule).
    - **Right:** sticky preview pane (`lg:sticky lg:top-8`) that stays in view while
      typing captions or changing targets.
- Small screens (`< lg`): an `[ Edit | Preview ]` toggle so `Preview` shows the
  pane without forcing a scroll past every input to reach it.

## 3. Preview source of truth (derived, never manual)

- Available preview tabs = platforms currently selected in the form.
- One selected platform → no tab bar (locked to it).
- Multiple platforms selected → tab bar (icon + label per platform); switching
  mirrors that platform's native frame.
- Zero accounts selected → show the full platform tab set (FB/IG/LinkedIn/TikTok)
  flagged _"Preview only — no accounts selected"_, defaulting to Facebook.

### Tab synchronization rules

- If the active tab's platform is deselected in the form, fall back to the first
  remaining selected platform (or the zero-selected default).
- Tab set updates reactively as accounts are checked/unchecked.
- **Zero accounts selected → preview is locked.** The pane shows a neutral
  "Select a target platform to preview" prompt; tabs/cards only appear once at
  least one account is checked (i.e. the preview reflects the user's choice —
  no browse-before-choose mode).

## 4. Account identity (author resolution)

- Prefer the **selected connected account** for the active platform:
  `publishableAccounts` hold `display_name` (+ `platform.value`), so the preview
  header shows the real page/handle (e.g. "Acme Corp", "@acme_hq").
- Multiple accounts on the same platform → use the first selected one for that
  platform; count shown on the tab badge (e.g. `Facebook (2)`).
- No selected account for that platform → fall back to the workspace name.

## 5. Platform constraints & missing-media state

- `requiresMedia: true` for Instagram and TikTok. If media is missing for one of
  those, render an in-frame notice inside the mockup:
  _"Instagram requires an image to publish"_ (mirrors the existing amber
  "Image required" badge on the IG target row and the backend validation).
- `maxCharacters` per platform shown where useful; caption length counter already
  exists in the form — no new behavior, just preview parity.

## 6. Caption typography & token styling

- Render with `whitespace-pre-wrap break-words` so paragraphs and spacing survive.
- Highlight `#hashtags` and `@mentions` with platform-accurate link colors
  (Facebook `#1877F2`, LinkedIn `#0A66C2`, IG accent) via a small tokenizer —
  no external library.

## 7. Platform renderers

- **Facebook:** avatar + page name, timestamp "Just now · 🌐", caption, full-width
  image, Like/Comment/Share row.
- **Instagram:** square avatar + username, 3-dot menu, 1:1 image frame,
  heart/comment/share/bookmark rail, `username caption`, comment prompt.
- **LinkedIn:** avatar + name/headline ("Company • • 1st • Just now"), professional
  caption, image card, Like/Comment/Repost/Send row.
- **TikTok:** vertical 9:16 viewport simulation, floating right-side action rail
  (heart, comment, bookmark, share), bottom author + caption + audio ticker.
- Empty caption + no image anywhere → friendly prompt: _"Start typing or add an
  image to see a live preview."_

## 8. Theming

- Build on the existing design-token system (`--panel`, `--text`, `--border`,
  `--muted`, `--color-accent-start` in `resources/css/app.css`) so previews adapt
  to both `data-theme="dark"` and `prefers-color-scheme` automatically.

---

## Verification

- `npm run types:check` — registry types, props, and tab logic type-check.
- `npm run build` — Vite bundle succeeds.
- Manual pass over `Create.tsx` at `lg`, `md`, and `sm` widths (sticky placement,
  mobile toggle, tab sync edge cases listed above).

---

## Deferred / out of scope this slice

- Real LinkedIn/TikTok **publishing** (backend work; not part of preview).
- Video / carousel media in the composer (affects TikTok fidelity later).
- Platform-specific character/exact-fidelity polish (crunchyroll-faithful frame
  pixels) — v1 is a styled, believable mockup.

---

## Implemented and verified

Shipped. `vp check`, `npm run types:check`, and `npm run build` all pass.

- `resources/js/components/Preview/PostPreview.tsx` — `PlatformPreviewDefinition`
  registry (Facebook, Instagram, LinkedIn, TikTok + `createFallbackDefinition()`
  for unknown platforms), brand SVGs, `#hashtag`/`@mention` tokenizer
  (`CaptionText`), `PostPreviewPane` (tabs when 2+ platforms; locked
  "Select a target platform to preview" panel until an account is checked, with
  the content empty-state prompt when only caption + image are both empty).
- `Create.tsx` — full-width gutter container; `lg:grid-cols-[minmax(0,1fr)_26rem]`
  split with a sticky `lg:top-8` preview aside; `lg:hidden` Editor/Preview toggle
  on small screens; platforms + author derived from the checked accounts
  (account `display_name`, falling back to the workspace name).
- Media-required platforms (Instagram/TikTok) render an in-frame notice when no
  image is present, mirroring the amber "Image required" badge and
  `StorePostRequest` validation.
