---
name: Zephyrus
description: Dark-default hospital operations command center — clinical rigor with executive polish.
colors:
  primary-blue: "#2563EB"
  primary-blue-dark: "#3B82F6"
  brand-crimson: "#9B1B30"
  brand-crimson-light: "#B82D42"
  accent-gold: "#C9A227"
  accent-gold-ink: "#A6791A"
  status-success-teal: "#2DD4BF"
  status-warning-amber: "#E5A84B"
  status-critical-coral: "#E85A6B"
  status-info-blue: "#60A5FA"
  ink-dark: "#F0EDE8"
  ink-light: "#1E293B"
  muted-dark: "#94A3B8"
  muted-light: "#475569"
  surface-base-dark: "#0F172A"
  surface-raised-dark: "#1E293B"
  surface-base-light: "#F8FAFC"
  surface-raised-light: "#FFFFFF"
  border-dark: "#334155"
  border-light: "#E2E8F0"
typography:
  display:
    fontFamily: "Figtree, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.75rem"
    fontWeight: 600
    lineHeight: "2.125rem"
    letterSpacing: "-0.01em"
  headline:
    fontFamily: "Figtree, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.375rem"
    fontWeight: 600
    lineHeight: "1.75rem"
  title:
    fontFamily: "Figtree, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 600
    lineHeight: "1.5rem"
  body:
    fontFamily: "Figtree, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: "1.25rem"
  label:
    fontFamily: "Figtree, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.6875rem"
    fontWeight: 600
    lineHeight: "1rem"
    letterSpacing: "0.05em"
rounded:
  sm: "6px"
  md: "8px"
  lg: "12px"
  full: "9999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
components:
  panel:
    backgroundColor: "{colors.surface-raised-light}"
    rounded: "{rounded.lg}"
    padding: "16px"
  button-primary:
    backgroundColor: "{colors.primary-blue}"
    textColor: "#FFFFFF"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-primary-hover:
    backgroundColor: "{colors.primary-blue-dark}"
    textColor: "#FFFFFF"
  kpi-tile:
    backgroundColor: "{colors.surface-raised-dark}"
    rounded: "{rounded.lg}"
    padding: "16px"
  nav-top:
    backgroundColor: "{colors.surface-raised-light}"
    textColor: "{colors.ink-light}"
    height: "56px"
  input:
    backgroundColor: "{colors.surface-raised-light}"
    textColor: "{colors.ink-light}"
    rounded: "{rounded.md}"
    padding: "8px 12px"
---

# Design System: Zephyrus

## 1. Overview

**Creative North Star: "The Operations Bridge"**

Zephyrus is the bridge of a ship the size of a hospital — a calm, instrument-dense
surface where a charge nurse, a bed manager, and a CMO all read the same truth at
different altitudes. It is **dark by default** because that is where it lives: on
wall displays and shared workstations, glanced at during a surge and studied in a
Monday review. The design serves the workflow, never performs over it. Confidence
comes from restraint and from data that is honest enough to defend line by line.

The working surface is a **cool blue/slate operational system** (the gold-standard
Perioperative dashboard look): white or near-black panels, slate ink, and a single
interactive blue. Over that sits the **Acumenus brand layer** — a heritage
**crimson + gold** identity that appears in the wordmark, the focus ring, and a few
brand moments, but is *never* the dashboard's working primary. Status lives in a
deliberately small vocabulary — teal, amber, coral, info-blue — rationed so that
when something turns coral, it means it.

This system explicitly rejects four things, carried verbatim from the product
brief: the **legacy hospital EHR** (dense gray grids, tiny inscrutable toolbars,
1998 enterprise chrome) it exists to replace; **consumer SaaS / startup** styling
(playful gradients, blobby illustrations, emoji, marketing hero-metric cards); the
**alarm-fatigue dashboard** (everything red, blinking, urgent at once); and the
**generic admin template** (Bootstrap/Material defaults, identical card grids,
AI-slop sameness with no point of view).

**Key Characteristics:**
- Dark-default, dual-theme; light mode mirrors the same structure on white/slate.
- Density *with* clarity — 13–14px body, 4px spacing grid, tabular metrics.
- One interactive color (blue); status as scarce, redundant signal.
- Calm at rest; emphasis is earned by the moment, not applied ambiently.
- Crimson + gold is identity and focus, not chrome.

## 2. Colors

A cool, composed slate base carrying one interactive blue, a heritage crimson/gold
brand accent, and a tight four-color status vocabulary. Values below are the
dark-default canon; light mode darkens saturated hues for contrast on white.

### Primary
- **Interactive Blue** (`#2563EB` light / `#3B82F6` dark): the single working
  interactive color — primary buttons, links, active nav, selected states, the
  "Hospital/info" domain. If something is clickable and important, it is this blue.

### Secondary
- **Acumenus Crimson** (`#9B1B30`, light `#B82D42`): the master brand identity —
  wordmark, brand chrome, the night-shift status hue. A heritage accent, used
  sparingly; it does not drive operational UI.
- **Research Gold** (`#C9A227` dark / `#A6791A` light): the **focus color** —
  every `:focus-visible` outline is gold — plus accent highlights and the skip-link.
  Gold earns its place through focus and emphasis, never as a fill.

### Tertiary — Status (the four-color vocabulary)
Each status color is paired *always* with a non-color cue (arrow, icon, label).
- **Success / Optimal — Teal** (`#2DD4BF` dark / `#059669` light): on-time, optimal
  flow, healthy capacity.
- **Warning / Delayed — Amber** (`#E5A84B` dark / `#D97706` light): slipping,
  approaching threshold, moderate acuity.
- **Critical / Breach — Coral-Red** (`#E85A6B` dark / `#DC2626` light): true
  threshold breach, bottleneck, high acuity. Reserved; never decorative.
- **Info / Completed — Sky** (`#60A5FA` dark / `#0284C7` light): completed states,
  neutral information, secondary series.

### Neutral
- **Ink** (`#1E293B` light / `#F0EDE8`–`#F8FAFC` dark): primary text.
- **Muted Ink** (`#475569` light / `#94A3B8` dark): secondary text, labels, captions.
- **Surface** (`#FFFFFF` raised / `#F8FAFC` base in light; `#1E293B` raised /
  `#0F172A` base in dark): panels and page field.
- **Border** (`#E2E8F0` light / `#334155` dark): hairline dividers and panel edges.

### Named Rules
**The Two-System Rule.** The blue/slate operational palette governs every working
surface and interaction. Crimson + gold is the Acumenus brand/heritage layer —
identity, focus, night-shift status — and nothing more. Never promote crimson to a
dashboard primary, and never let a new screen drift toward one system's tokens
where the other governs. They coexist on purpose; keep the seam disciplined.

**The Earned-Red Rule.** Coral-red is the most expensive ink in the system. It
appears only for a genuine breach or critical acuity — never as a default border,
never to "add energy." If everything is red, nothing is.

**The Status-Never-Alone Rule.** No state is communicated by color alone. Teal,
amber, coral, and sky always travel with an arrow (▲ ▼ ▬), an icon, or a worded
label, so the meaning survives color-blindness and a grayscale wall display.

## 3. Typography

**Display / Body Font:** Figtree (with `ui-sans-serif, system-ui` fallback)
**Secondary Font:** Inter (loaded for weight range; same humanist-sans register)
**Data:** Figtree with `font-variant-numeric: tabular-nums` (no separate mono face)

**Character:** One humanist sans family doing all the work, separated by weight and
size rather than by pairing. The register is plain-spoken and legible at glance
distance — there is no decorative or editorial typeface, by design. Numbers are the
real headline of this product, so digits are tabular everywhere they tick.

### Hierarchy
- **Display** (Figtree 600, 1.75rem / 28px, lh 2.125rem, -0.01em): hero metrics and
  page-level values — the number you read from across the room.
- **Headline** (Figtree 600, 1.375rem / 22px): section headers, large KPI values.
- **Title** (Figtree 600, 1rem / 16px): panel and card titles.
- **Body** (Figtree 400, 0.875rem / 14px, lh 1.25rem): default text; cap prose at
  65–75ch on the rare long-form surface.
- **Label** (Figtree 600, 0.6875rem / 11px, +0.05em, UPPERCASE): KPI labels, column
  headers, tile captions — functional data labels only.

### Named Rules
**The Tabular Rule.** Every metric, count, time, and percentage uses `tabular-nums`.
Digits must not reflow or jitter as live values update; a ticking number that
shifts width reads as broken on an ops floor.

**The Functional-Label Rule.** Uppercase tracked labels are permitted *only* as data
labels on tiles, columns, and chart axes — a clinical-instrument convention. They
are forbidden as decorative eyebrows above sections; this is not a marketing page.

## 4. Elevation

A low-contrast, restrained elevation system. In **light mode** panels rest on a
soft `shadow-sm` and lift to `shadow-md` on hover. In **dark mode** depth comes
primarily from **tonal layering** — the `#0F172A → #1E293B` surface step — with
shadow used only as a faint hover response (`0 4px 12px rgba(0,0,0,0.25)`), never at
rest. Every panel also carries a subtle top-down **3D sheen** (a near-transparent
white highlight gradient) that reads as light catching a physical surface, not as a
glass effect.

### Shadow Vocabulary
- **Resting (light)** (`box-shadow: 0 1px 3px rgba(15,23,42,0.10)`): default panel lift.
- **Hover (light)** (`box-shadow: 0 4px 12px rgba(15,23,42,0.10)`): interactive lift.
- **Hover (dark)** (`box-shadow: 0 4px 12px rgba(0,0,0,0.25)`): the only dark shadow;
  surfaces are otherwise flat and separated by tone.

### Named Rules
**The Quiet-Lift Rule.** Surfaces are calm at rest and lift only in response to
state. In dark mode, prefer a tonal step over a shadow. Depth is a whisper, not a
drop shadow contest.

## 5. Components

### Buttons
- **Shape:** gently rounded (`rounded-md`, 8px).
- **Primary:** interactive blue fill, white text, `h-9` (36px), `px-4 py-2`,
  medium weight. Hover darkens to `blue/90`.
- **Variants:** `outline` (hairline border on transparent), `secondary`, `ghost`
  (hover-tint only), `link` (underline-on-hover). Sizes `xs / sm / default / lg / icon`.
- **Focus:** gold `:focus-visible` outline (2px) + soft ring; ring-offset preserved.

### Cards / Containers — the Panel (signature component)
- **Corner Style:** `rounded-lg` (12px), `overflow-hidden`.
- **Background:** `healthcare-surface` (white / `#1E293B` dark) + a top-down white
  sheen gradient.
- **Border:** 1px `healthcare-border` (`#E2E8F0` / `#334155`).
- **Shadow Strategy:** see Elevation — `shadow-sm` resting (light), tonal in dark,
  lift on hover, 300ms transition.
- **Internal Padding:** 16px (`p-4`), denser `p-3`/`p-2` for compact tiles.

### KPI Tile (signature component)
- **Built on Panel**, with a **3px left status stripe** in the metric's status color.
  This is the *one* sanctioned colored side-accent in the system — and it is always
  backed by redundant encoding: the value color, a trajectory arrow (▲ ▼ ▬), a
  trust badge, an ⓘ definition tooltip, and an uppercase label.
- **Percent metrics** render a radial **Gauge**; **count metrics** render a large
  tabular value. Both can carry an inline **Sparkline** (area + line + target dash).
- **Source-trust badge** surfaces data lineage/provenance inline — "defensible at a
  glance" made literal.

### Inputs / Fields
- **Style:** `@tailwindcss/forms` base — surface background, hairline border,
  `rounded-md`, slate text.
- **Focus:** gold outline + soft focus ring (light mode shifts the ring to blue).
- **Error / Disabled:** coral border for errors (with message, never color alone);
  reduced opacity when disabled.

### Navigation — Top bar
- **Style:** sticky 56px bar, `healthcare-surface` background, `border-b` hairline.
- **Contents:** Zephyrus icon + wordmark (17px bold) → horizontally-scrolling domain
  mega-menus → search (⌘K command palette), dark-mode toggle, user menu.
- **States:** active domain/home gets a `healthcare-hover` tint; hover tints + 300ms
  transition. Mega-menu panels are portaled so the scroll container never clips them.

## 6. Do's and Don'ts

### Do:
- **Do** keep the blue/slate operational palette as the working surface and use
  **one** interactive blue for clickable primary actions (`#2563EB` / `#3B82F6`).
- **Do** ration status color — teal/amber/coral/sky — and always pair it with an
  arrow, icon, or label (the Status-Never-Alone Rule).
- **Do** reserve coral-red for genuine threshold breaches and critical acuity only.
- **Do** use `tabular-nums` on every metric, count, time, and percentage.
- **Do** use the gold `:focus-visible` outline on every interactive element and
  honor `prefers-reduced-motion` with a crossfade or instant alternative.
- **Do** keep panels calm at rest and lift them only on hover; in dark mode lean on
  the `#0F172A → #1E293B` tonal step for depth.
- **Do** surface provenance/trust inline on metrics — defensible at a glance.

### Don't:
- **Don't** look like a **legacy hospital EHR**: no dense gray grids, tiny
  inscrutable toolbars, or 1998 enterprise chrome.
- **Don't** look like **consumer SaaS / a startup**: no playful gradients, blobby
  illustrations, emoji, or marketing-style hero-metric cards.
- **Don't** build an **alarm-fatigue dashboard**: never make everything red,
  blinking, or urgent at once. Urgency is earned per element, never ambient.
- **Don't** ship a **generic admin template**: no Bootstrap/Material defaults, no
  endless identical card grids with icon-heading-text, no AI-slop sameness.
- **Don't** add colored `border-left` / `border-right` side-stripes as decoration.
  The **only** sanctioned colored side-accent is the KPI status stripe, and even
  there it must be backed by an arrow, label, and matching data-color.
- **Don't** use gradient text (`background-clip: text`) — emphasis comes from weight
  and size, in a single solid color.
- **Don't** use glassmorphism on dashboards. Blur/glass tokens exist **only** for
  the auth atmosphere and true overlays; working surfaces stay solid.
- **Don't** promote crimson to an operational primary or let new screens drift
  between the two color systems (the Two-System Rule).
- **Don't** put uppercase tracked labels above sections as decorative eyebrows —
  they are data labels on tiles and columns only.
