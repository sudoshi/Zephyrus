# Zephyrus Auth UX Re-imagine — Design Spec

**Date:** 2026-06-22
**Status:** Approved (design), pending implementation plan
**Author:** Claude (Opus 4.8) with Dr. Sanjay Udoshi
**Inspiration:** `aurora.acumenus.net` split-screen glassmorphic auth

## 1. Summary

Completely re-imagine the Zephyrus guest/auth UX as a **dark-only, cinematic
split-screen** experience modeled on Aurora's auth layout, but rendered in
Zephyrus's own **indigo → blue → cyan** identity with an **atmospheric
wind/sky photographic slideshow** that ties the "Zephyrus" (west wind) name to
the palette.

The current login page is a light-first indigo/slate single-card design that
matches neither Aurora nor the rest of the Zephyrus app (whose own design
system, `tokens-dark.css`, is dark crimson + gold). We deliberately keep the
login's **indigo/blue/cyan** brand (per user decision) rather than adopting the
app's crimson, so the auth surface stays a distinct, branded "front door."

This is a **visual + structural reskin with explicit user authorization**. All
protected auth behavior (see §9) is preserved — additions/restyling only.

### Decisions (locked)

| Decision | Choice |
|----------|--------|
| Palette | **Indigo / blue / cyan** (keep current login identity) |
| Theme mode | **Dark-only**, dramatic (no light/dark toggle on guest pages) |
| Background | **Photographic slideshow** (atmospheric wind & sky, navy/cyan toned) |
| Scope | **Full guest suite** via one shared `AuthLayout` |
| Imagery | **Atmospheric wind & sky**, royalty-free (Unsplash), sourced into repo |

## 2. Goals / Non-Goals

**Goals**
- A distinctive, production-grade, dark cinematic auth experience.
- One shared `AuthLayout` so every guest page is consistent.
- Small, focused, well-bounded components; remove the input markup currently
  duplicated ~8× across pages.
- Full `prefers-reduced-motion` support.
- Zero change to auth behavior, endpoints, or the temp-password/Resend flow.

**Non-Goals**
- No backend / controller / route / middleware changes.
- No change to `AuthenticatedLayout` or the `ChangePasswordModal`.
- No change to the app's authenticated-side theming (it keeps light + dark).
- No new auth features (SSO providers, password policy, etc.).

## 3. Layout

Full-viewport, dark-only.

- **Desktop (≥1024px):** two columns.
  - **Left (~55%)** — full-bleed photographic **slideshow** under a dark navy
    radial+linear gradient overlay, with a glassmorphic **brand hero** panel
    floating over it (centered, max-width ~720px).
  - **Right (~45%)** — centered glassmorphic **form panel** (max-width ~420px)
    with an animated indigo→cyan **shimmer border**.
- **Mobile / tablet (<1024px):** single column.
  - Slideshow becomes the full-screen background behind everything.
  - Hero collapses to a compact logo + wordmark + tagline header; feature list
    and pill rows hide.
  - Form panel centers below the compact header.

Breakpoint: Tailwind `lg` (1024px). (Aurora used 900px; we standardize on `lg`.)

## 4. Left brand hero (`AuthHero`)

Glass card over the slideshow. Indigo/blue/cyan accents only.

- **Mark + wordmark:** reuse the existing `ElegantMark` SVG from
  `GuestLayout.tsx` (concentric rising-pulse arcs, already an indigo→blue→cyan
  gradient) + "ZEPHYRUS" wordmark (extralight, wide tracking, uppercase).
- **Subtitle:** "Healthcare Operations Platform" (uppercase, tracked).
- **Divider:** 48px indigo→cyan gradient bar.
- **Description:** one short paragraph — real-time hospital demand & capacity /
  operations command center.
- **Feature list** (icon + label + short desc), ~5 items, drawn from what
  Zephyrus actually ships:
  - Operations Command Center — house-wide situational awareness
  - Real-Time Demand & Capacity (RTDC) — live census, boarding, bed demand
  - Perioperative & OR — block utilization, FCOTS, case flow
  - Patient Flow & Throughput — ED, admissions, discharges, boarding
  - Forecasting & Surge — capacity forecasts and early surge signals
- **Pill rows** (color-coded, in-palette):
  - **Modules** (indigo): Command Center · RTDC · Perioperative · Patient Flow · Care Progression
  - **Capabilities** (cyan): Live Census · Bed Management · Surge Forecasting · Block Utilization
  - **Standards & Security** (sky): HIPAA · RBAC · OIDC SSO · Audit Logging · PHI Isolation
- **Footer tagline:** "Acumenus Data Sciences · Wellstack.ai".

Hero content is fixed/shared across all guest pages (it is brand identity, not
page-specific).

## 5. Right form panel (`AuthFormPanel`)

- Glass card: dark translucent surface (`backdrop-blur`), subtle white-alpha
  border, 24px radius.
- **Shimmer border:** an oversized rotating `conic-gradient` (indigo + cyan
  stops) behind a clipped panel — Aurora's effect recolored. Animation paused
  under `prefers-reduced-motion`.
- `children` (the page's form) render inside.

Per-page content (forms compose the shared `AuthField`):

- **Login** (`Login.jsx`)
  - Eyebrow/title "Welcome back" + "Sign in to continue to your dashboard".
  - Username (icon, autofocus) + Password (icon, show/hide).
  - Remember me (HeroUI `Checkbox`) + "Forgot password?" (when `canResetPassword`).
  - Gradient submit (indigo→blue→cyan), `processing` state.
  - OIDC button + "or" divider when `oidcEnabled` (label `oidcLabel`).
  - **Create Account CTA** — preserved (see §9), restyled to the dark panel.
  - **Demo credentials** — subtle muted footnote (admin / password).
- **Register** (`Register.jsx`)
  - "Create Account" + subtitle.
  - Name / Email / Phone(optional) — **no password fields**.
  - Submit ("Creating account…"), then the existing **"Check your inbox"**
    success state (temp password + username emailed).
  - "Already have an account? Sign in".
- **ChangePassword** (`ChangePassword.jsx`)
  - Shield eyebrow + "You must change your temporary password before continuing".
  - Current (Temporary) / New / Confirm — each with show/hide.
  - Submit ("Changing password…").

## 6. Component architecture

New files (dark-only, TypeScript where new; pages stay `.jsx` to minimize churn):

| File | Purpose | Depends on |
|------|---------|------------|
| `resources/js/Layouts/AuthLayout.tsx` | Orchestrates background + hero + form-panel wrapper; forces `dark` class; `children` render inside the form panel. | `AuthBackground`, `AuthHero`, `AuthFormPanel` |
| `resources/js/Components/Auth/AuthBackground.tsx` | Slideshow: crossfade, 8s interval, gradient fallback, reduced-motion freeze. | — |
| `resources/js/Components/Auth/AuthHero.tsx` | Left brand panel (§4). Contains `ElegantMark` (moved here). | — |
| `resources/js/Components/Auth/AuthFormPanel.tsx` | Shimmer-bordered glass card wrapping `children`. | `auth.css` |
| `resources/js/Components/Auth/AuthField.tsx` | Shared label + icon + input (+ optional show/hide). Replaces input block duplicated ~8×. | `@iconify/react` |
| `resources/css/auth.css` | Keyframes only: `auth-shimmer-rotate`, slideshow crossfade. Imported by `app.css`. | — |

`AuthField` props (typed): `label`, `name`, `type`, `value`, `onChange`,
`icon` (iconify id), `placeholder`, `autoComplete`, `autoFocus?`, `required?`,
`error?`, and `revealable?` (renders the eye/eye-off toggle for password types).

The three in-scope pages become thin: they render `AuthField`s + the submit
button + page-specific CTAs inside `<AuthLayout>`. The duplicated inline
`useDarkMode` hook and per-page dark-mode toggle are **removed** (dark-only).

`GuestLayout.tsx` is retained for now but the in-scope pages switch to
`AuthLayout`. (See §10 for the follow-on pages.)

## 7. Assets & motion

- **Imagery:** source **5–6 royalty-free (Unsplash)** atmospheric wind/sky
  photos, dark navy/cyan-toned, optimized (~1600–2000px wide, compressed JPG),
  stored in `public/images/auth/`. The existing `public/images/17017066_8_blue.jpg`
  particle-flow image becomes one slide and/or informs the CSS fallback gradient.
  A navy→indigo CSS gradient renders before images load / if they fail.
- **Motion (framer-motion, already a dependency):**
  - Slideshow crossfade (~2.5s) every ~8s.
  - Hero panel entrance (fade + slight slide); form panel entrance (fade-up,
    small delay).
  - Shimmer border rotation (CSS, ~6s linear infinite).
- **Reduced motion:** when `prefers-reduced-motion: reduce`, freeze the
  slideshow on the first image, stop shimmer rotation, and drop entrance
  transforms (content still appears, just without movement).

## 8. Dark-only enforcement

`AuthLayout` adds `dark` to `document.documentElement` on mount (guest pages are
dark-only). The per-page `useDarkMode` toggles are removed. This does **not**
affect the authenticated app, which keeps its own light/dark handling via
`@/hooks/useDarkMode`.

## 9. Preserved auth behavior (compliance with `.claude/rules/auth-system.md`)

This rework is explicitly authorized by the user. It is visual/structural only;
every protected behavior is preserved:

- **Login:** keeps username + password, `POST /login`, remember, forgot-password
  link, OIDC button (`oidcEnabled`/`oidcLabel`), and the **"Create Account" CTA**
  (rule 1). No fields added/removed beyond styling.
- **Register:** stays **name / email / phone only — no password fields**
  (rules 3, 12); preserves the temp-password + Resend email flow and the
  "Check your inbox" success state.
- **ChangePassword:** keeps current / new / confirm and `POST /change-password`.
- **Unchanged:** all routes/controllers/middleware, email sender, Resend config,
  `must_change_password` redirect, `ChangePasswordModal`, `AuthenticatedLayout`,
  superuser account, password requirements, email-enumeration prevention.

No secrets are introduced; no `.env` or `config/services.php` changes.

## 10. Follow-on (recommended, fast)

"Forgot password?" links to `/forgot-password`, a Breeze page still on the old
`GuestLayout`. To avoid a jarring visual switch, extend `AuthLayout` to the
remaining guest pages — `ForgotPassword.jsx`, `ResetPassword.jsx`,
`VerifyEmail.jsx`, `ConfirmPassword.jsx` — reusing the same shell with
page-appropriate copy. These are trivial (single form each) and should be
included in the implementation plan as a final phase so the entire guest suite
is consistent.

## 11. Verification

- `npx tsc --noEmit` **and** `npx vite build` (vite is stricter — catches
  unresolved imports tsc misses).
- Manual: load `/login`, `/register`, `/change-password` (+ follow-on pages) at
  desktop and mobile widths; confirm slideshow crossfade, shimmer, entrance
  motion, and reduced-motion behavior; confirm Login still posts and the
  Create Account CTA + OIDC button render; confirm Register has no password
  fields and shows the success state.
- Deploy via `./deploy.sh --frontend` (per project convention) after merge.

## 12. Risks

- **Asset weight / licensing:** keep images optimized; use Unsplash (free
  license). Provide a CSS gradient fallback so the page never blocks on images.
- **Protected-file edits:** Login/Register/ChangePassword are listed as
  protected in `auth-system.md`; mitigated by preserving every contract in §9
  and by the user's explicit authorization for this rework.
- **`backdrop-filter` performance:** acceptable on the few small auth panels;
  no large scrolling surfaces involved.
