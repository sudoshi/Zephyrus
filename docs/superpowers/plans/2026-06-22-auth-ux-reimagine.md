# Zephyrus Auth UX Re-imagine — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Zephyrus guest/auth pages with a dark-only, cinematic split-screen experience (photographic wind/sky slideshow + glassmorphic hero + shimmer-bordered form panel) in the indigo/blue/cyan brand, via one shared `AuthLayout`, with zero change to auth behavior.

**Architecture:** A new `AuthLayout` orchestrates three new presentational components — `AuthBackground` (slideshow), `AuthHero` (brand panel), `AuthFormPanel` (shimmer card) — plus a shared `AuthField` input. The seven Auth pages (`Login`, `Register`, `ChangePassword` first; then `ForgotPassword`, `ResetPassword`, `VerifyEmail`, `ConfirmPassword`) switch from `GuestLayout` to `AuthLayout` and compose `AuthField`s. All form names, POST targets, and props are preserved.

**Tech Stack:** React 19 + Inertia, TypeScript (new components), Tailwind v3, framer-motion, @heroui/react, @iconify/react, vitest + @testing-library/react.

**Spec:** `docs/superpowers/specs/2026-06-22-auth-ux-reimagine-design.md`

**Conventions for this plan:**
- New shared components are **TypeScript** (`.tsx`) with **named exports** (per global rules). `AuthLayout` is a default export to match the existing `GuestLayout.tsx` layout convention and Inertia layout usage.
- Inertia **pages stay `.jsx` with default exports** (Inertia page requirement; minimizes churn on protected files).
- Tests go in `tests/js/auth/*.test.tsx` and run under jsdom. The shared setup (`tests/js/setup.ts`) mocks `@inertiajs/react` (`Link`, `Head`, `router`) and `matchMedia`/`localStorage`. It does **not** mock `useForm`, so we unit-test the presentational components, not the full pages. Pages are verified via `tsc` + `vite build` + manual.
- **Verify commands** (used throughout):
  - `npx vitest run tests/js/auth` — new component tests
  - `npx tsc --noEmit` — type check
  - `npx vite build` — strict bundle (catches unresolved imports tsc misses)

**Protected-file note:** `Login.jsx`, `Register.jsx`, `ChangePassword.jsx` are listed in `.claude/rules/auth-system.md`. This rework is user-authorized and preserves every contract; each page task ends with a **Preserve checklist**.

---

## File Structure

**Create:**
- `resources/css/auth.css` — keyframes only (shimmer rotate; reduced-motion off-switch)
- `resources/js/Components/Auth/authBackgrounds.ts` — slideshow image paths + fallback gradient constant
- `resources/js/Components/Auth/AuthBackground.tsx` — slideshow with crossfade, interval, reduced-motion freeze, dark overlay
- `resources/js/Components/Auth/AuthHero.tsx` — left brand panel (mark, wordmark, features, pills)
- `resources/js/Components/Auth/AuthFormPanel.tsx` — shimmer-bordered glass card wrapper
- `resources/js/Components/Auth/AuthField.tsx` — shared label+icon+input(+reveal) field
- `resources/js/Layouts/AuthLayout.tsx` — orchestrator; forces `dark`; renders bg+hero+formpanel(children)
- `public/images/auth/wind-01.jpg … wind-05.jpg` — sourced atmospheric imagery
- Tests: `tests/js/auth/AuthField.test.tsx`, `AuthBackground.test.tsx`, `AuthHero.test.tsx`, `AuthFormPanel.test.tsx`, `AuthLayout.test.tsx`

**Modify:**
- `resources/css/app.css` — add `@import './auth.css';`
- `resources/js/Pages/Auth/Login.jsx` — reskin onto AuthLayout/AuthField (preserve behavior)
- `resources/js/Pages/Auth/Register.jsx` — reskin (passwordless; success state)
- `resources/js/Pages/Auth/ChangePassword.jsx` — reskin
- `resources/js/Pages/Auth/ForgotPassword.jsx`, `ResetPassword.jsx`, `VerifyEmail.jsx`, `ConfirmPassword.jsx` — reskin onto AuthLayout

**Untouched:** all backend, routes, middleware, `AuthenticatedLayout.jsx`, `ChangePasswordModal.jsx`, `GuestLayout.tsx` (left in place; no longer imported by Auth pages).

---

## Task 0: Branch

- [ ] **Step 1: Create the feature branch**

```bash
cd /home/smudoshi/Github/Zephyrus
git checkout -b feature/auth-ux-reimagine
git branch --show-current   # expect: feature/auth-ux-reimagine
```

---

## Task 1: Motion keyframes (`auth.css`)

**Files:**
- Create: `resources/css/auth.css`
- Modify: `resources/css/app.css` (add import after line 4)

- [ ] **Step 1: Create `resources/css/auth.css`**

```css
/* ============================================================
   Auth (guest) page motion — keyframes only.
   Layout/visuals are Tailwind utilities + inline styles.
   ============================================================ */

@keyframes auth-shimmer-rotate {
  from { transform: rotate(0deg); }
  to   { transform: rotate(360deg); }
}

/* Honour reduced-motion: stop the rotating shimmer border.
   !important overrides the element's inline animation. */
@media (prefers-reduced-motion: reduce) {
  .auth-shimmer__spin { animation: none !important; }
}
```

- [ ] **Step 2: Import it from `app.css`**

In `resources/css/app.css`, add this line immediately after `@import './components/focus.css';` (line 5):

```css
@import './auth.css';
```

- [ ] **Step 3: Verify the build picks it up**

Run: `npx vite build`
Expected: build succeeds (no missing-file error for `auth.css`).

- [ ] **Step 4: Commit**

```bash
git add resources/css/auth.css resources/css/app.css
git commit -m "feat(auth): add auth.css keyframes for guest-page motion"
```

---

## Task 2: Shared field component (`AuthField`)

**Files:**
- Create: `resources/js/Components/Auth/AuthField.tsx`
- Test: `tests/js/auth/AuthField.test.tsx`

- [ ] **Step 1: Write the failing test**

`tests/js/auth/AuthField.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { AuthField } from '@/Components/Auth/AuthField';

describe('AuthField', () => {
  it('renders the label and placeholder', () => {
    render(
      <AuthField id="username" label="Username" icon="lucide:user"
        value="" onChange={() => {}} placeholder="Enter your username" />,
    );
    expect(screen.getByText('Username')).toBeInTheDocument();
    expect(screen.getByPlaceholderText('Enter your username')).toBeInTheDocument();
  });

  it('calls onChange with the typed value', () => {
    const onChange = vi.fn();
    render(<AuthField id="username" label="Username" icon="lucide:user" value="" onChange={onChange} />);
    fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'drsmu' } });
    expect(onChange).toHaveBeenCalledWith('drsmu');
  });

  it('toggles password visibility when revealable', () => {
    render(
      <AuthField id="password" label="Password" icon="lucide:lock" type="password"
        revealable value="secret" onChange={() => {}} />,
    );
    const input = screen.getByLabelText('Password') as HTMLInputElement;
    expect(input.type).toBe('password');
    fireEvent.click(screen.getByRole('button', { name: /show password/i }));
    expect(input.type).toBe('text');
    fireEvent.click(screen.getByRole('button', { name: /hide password/i }));
    expect(input.type).toBe('password');
  });

  it('renders an optional suffix when optional', () => {
    render(<AuthField id="phone" label="Phone" icon="lucide:phone" optional value="" onChange={() => {}} />);
    expect(screen.getByText(/optional/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run tests/js/auth/AuthField.test.tsx`
Expected: FAIL — cannot resolve `@/Components/Auth/AuthField`.

- [ ] **Step 3: Implement `resources/js/Components/Auth/AuthField.tsx`**

```tsx
import { Icon } from '@iconify/react';
import { useState, type ChangeEvent } from 'react';

export interface AuthFieldProps {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  icon: string;
  type?: 'text' | 'email' | 'tel' | 'password';
  placeholder?: string;
  autoComplete?: string;
  autoFocus?: boolean;
  required?: boolean;
  revealable?: boolean;
  optional?: boolean;
  error?: string;
}

export function AuthField({
  id, label, value, onChange, icon, type = 'text', placeholder,
  autoComplete, autoFocus, required, revealable, optional, error,
}: AuthFieldProps) {
  const [revealed, setRevealed] = useState(false);
  const inputType = revealable ? (revealed ? 'text' : 'password') : type;

  return (
    <div>
      <label htmlFor={id} className="block text-xs font-medium text-slate-400 mb-1.5">
        {label}
        {optional && <span className="text-slate-500"> (optional)</span>}
      </label>
      <div className="relative">
        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
          <Icon icon={icon} className="w-[18px] h-[18px] text-slate-500" />
        </div>
        <input
          id={id}
          type={inputType}
          value={value}
          onChange={(e: ChangeEvent<HTMLInputElement>) => onChange(e.target.value)}
          placeholder={placeholder}
          required={required}
          autoFocus={autoFocus}
          autoComplete={autoComplete}
          className={[
            'w-full rounded-xl border bg-white/[0.04] py-3 pl-11 text-sm text-slate-100',
            'placeholder-slate-500 outline-none transition-colors',
            revealable ? 'pr-11' : 'pr-4',
            error ? 'border-red-500/50' : 'border-white/10',
            'hover:border-indigo-400/60 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/25',
          ].join(' ')}
        />
        {revealable && (
          <button
            type="button"
            tabIndex={-1}
            onClick={() => setRevealed((v) => !v)}
            aria-label={revealed ? 'Hide password' : 'Show password'}
            className="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-500 hover:text-slate-300 transition-colors"
          >
            <Icon icon={revealed ? 'lucide:eye-off' : 'lucide:eye'} className="w-[18px] h-[18px]" />
          </button>
        )}
      </div>
      {error && <p className="mt-1.5 text-xs text-red-400">{error}</p>}
    </div>
  );
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run tests/js/auth/AuthField.test.tsx`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Auth/AuthField.tsx tests/js/auth/AuthField.test.tsx
git commit -m "feat(auth): add shared AuthField input component"
```

---

## Task 3: Slideshow constants + `AuthBackground`

**Files:**
- Create: `resources/js/Components/Auth/authBackgrounds.ts`
- Create: `resources/js/Components/Auth/AuthBackground.tsx`
- Test: `tests/js/auth/AuthBackground.test.tsx`

- [ ] **Step 1: Create `resources/js/Components/Auth/authBackgrounds.ts`**

```ts
// Atmospheric wind/sky slideshow sources (deep navy + cyan tonality).
// The fallback gradient renders BENEATH the images, so missing/slow/404
// images degrade gracefully to an intentional-looking background.
export const AUTH_BACKGROUND_IMAGES: string[] = [
  '/images/auth/wind-01.jpg',
  '/images/auth/wind-02.jpg',
  '/images/auth/wind-03.jpg',
  '/images/auth/wind-04.jpg',
  '/images/auth/wind-05.jpg',
  '/images/17017066_8_blue.jpg', // existing particle-flow image already in repo
];

export const AUTH_BACKGROUND_FALLBACK: string =
  'radial-gradient(ellipse at 25% 30%, #1e2a5a 0%, transparent 60%),' +
  'radial-gradient(ellipse at 80% 70%, #0a2540 0%, transparent 55%),' +
  'linear-gradient(160deg, #0b1120 0%, #0a0f1f 100%)';
```

- [ ] **Step 2: Write the failing test**

`tests/js/auth/AuthBackground.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, act } from '@testing-library/react';
import { AuthBackground } from '@/Components/Auth/AuthBackground';

function setReducedMotion(matches: boolean) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches, media: query, onchange: null,
    addListener: vi.fn(), removeListener: vi.fn(),
    addEventListener: vi.fn(), removeEventListener: vi.fn(), dispatchEvent: vi.fn(),
  })) as unknown as typeof window.matchMedia;
}

describe('AuthBackground', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('advances the active slide on the interval', () => {
    setReducedMotion(false);
    const { container } = render(<AuthBackground />);
    const root = container.firstChild as HTMLElement;
    expect(root.getAttribute('data-active-index')).toBe('0');
    act(() => { vi.advanceTimersByTime(8000); });
    expect(root.getAttribute('data-active-index')).toBe('1');
  });

  it('does not advance when reduced motion is preferred', () => {
    setReducedMotion(true);
    const { container } = render(<AuthBackground />);
    const root = container.firstChild as HTMLElement;
    act(() => { vi.advanceTimersByTime(24000); });
    expect(root.getAttribute('data-active-index')).toBe('0');
  });
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `npx vitest run tests/js/auth/AuthBackground.test.tsx`
Expected: FAIL — cannot resolve `@/Components/Auth/AuthBackground`.

- [ ] **Step 4: Implement `resources/js/Components/Auth/AuthBackground.tsx`**

```tsx
import { useEffect, useState } from 'react';
import { AUTH_BACKGROUND_IMAGES, AUTH_BACKGROUND_FALLBACK } from '@/Components/Auth/authBackgrounds';

const INTERVAL_MS = 8000;

function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches === true;
}

export function AuthBackground() {
  const images = AUTH_BACKGROUND_IMAGES;
  const reduced = prefersReducedMotion();
  const [index, setIndex] = useState(0);

  useEffect(() => {
    if (reduced || images.length <= 1) return;
    const t = setInterval(() => setIndex((i) => (i + 1) % images.length), INTERVAL_MS);
    return () => clearInterval(t);
  }, [reduced, images.length]);

  return (
    <div className="absolute inset-0 z-0" data-active-index={index} aria-hidden="true">
      {/* Fallback gradient — always beneath the images */}
      <div className="absolute inset-0" style={{ background: AUTH_BACKGROUND_FALLBACK }} />

      {/* Crossfading slides */}
      {images.map((src, i) => (
        <div
          key={src}
          className="absolute inset-0 bg-cover bg-center transition-opacity duration-[2500ms] ease-in-out"
          style={{ backgroundImage: `url(${src})`, opacity: i === index ? 1 : 0 }}
        />
      ))}

      {/* Legibility overlay */}
      <div
        className="absolute inset-0"
        style={{
          background:
            'radial-gradient(ellipse at 30% 40%, rgba(8,10,20,.55) 0%, transparent 70%),'
            + 'linear-gradient(180deg, rgba(8,10,20,.45) 0%, rgba(8,10,20,.80) 100%)',
        }}
      />
    </div>
  );
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `npx vitest run tests/js/auth/AuthBackground.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Auth/authBackgrounds.ts resources/js/Components/Auth/AuthBackground.tsx tests/js/auth/AuthBackground.test.tsx
git commit -m "feat(auth): add AuthBackground slideshow with reduced-motion + gradient fallback"
```

---

## Task 4: Brand hero (`AuthHero`)

**Files:**
- Create: `resources/js/Components/Auth/AuthHero.tsx`
- Test: `tests/js/auth/AuthHero.test.tsx`

- [ ] **Step 1: Write the failing test**

`tests/js/auth/AuthHero.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { AuthHero } from '@/Components/Auth/AuthHero';

describe('AuthHero', () => {
  it('renders the wordmark, tagline, and a known feature', () => {
    render(<AuthHero />);
    expect(screen.getByText('Zephyrus')).toBeInTheDocument();
    expect(screen.getByText(/Healthcare Operations Platform/i)).toBeInTheDocument();
    expect(screen.getByText(/Real-Time Demand & Capacity/i)).toBeInTheDocument();
  });

  it('renders the three pill section labels', () => {
    render(<AuthHero />);
    expect(screen.getByText('Modules')).toBeInTheDocument();
    expect(screen.getByText('Capabilities')).toBeInTheDocument();
    expect(screen.getByText(/Standards & Security/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run tests/js/auth/AuthHero.test.tsx`
Expected: FAIL — cannot resolve `@/Components/Auth/AuthHero`.

- [ ] **Step 3: Implement `resources/js/Components/Auth/AuthHero.tsx`**

```tsx
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';

/* Concentric rising-pulse arcs — indigo→blue→cyan brand mark (from GuestLayout). */
function ZephyrusMark({ className = '' }: { className?: string }) {
  return (
    <svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
      <defs>
        <linearGradient id="hero-mark-grad" x1="0" y1="0" x2="80" y2="80" gradientUnits="userSpaceOnUse">
          <stop stopColor="#6366f1" />
          <stop offset="0.5" stopColor="#3b82f6" />
          <stop offset="1" stopColor="#06b6d4" />
        </linearGradient>
      </defs>
      <path d="M12 58 A30 30 0 0 1 68 58" stroke="url(#hero-mark-grad)" strokeWidth="2.5" strokeLinecap="round" opacity="0.35" />
      <path d="M22 54 A20 20 0 0 1 58 54" stroke="url(#hero-mark-grad)" strokeWidth="2.5" strokeLinecap="round" opacity="0.6" />
      <path d="M30 50 A12 12 0 0 1 50 50" stroke="url(#hero-mark-grad)" strokeWidth="3" strokeLinecap="round" />
      <circle cx="40" cy="38" r="3.5" fill="url(#hero-mark-grad)" />
    </svg>
  );
}

const FEATURES: { icon: string; label: string; desc: string }[] = [
  { icon: 'lucide:layout-dashboard', label: 'Operations Command Center', desc: 'House-wide situational awareness across the care continuum' },
  { icon: 'lucide:activity', label: 'Real-Time Demand & Capacity', desc: 'Live census, boarding, and bed-demand signals' },
  { icon: 'lucide:scissors', label: 'Perioperative & OR', desc: 'Block utilization, FCOTS, and case flow' },
  { icon: 'lucide:route', label: 'Patient Flow & Throughput', desc: 'ED, admissions, discharges, and boarding' },
  { icon: 'lucide:trending-up', label: 'Forecasting & Surge', desc: 'Capacity forecasts and early surge signals' },
];

const PILLS: { label: string; items: string[]; tone: string }[] = [
  { label: 'Modules', tone: 'text-indigo-300 bg-indigo-500/10 border-indigo-400/20',
    items: ['Command Center', 'RTDC', 'Perioperative', 'Patient Flow', 'Care Progression'] },
  { label: 'Capabilities', tone: 'text-cyan-300 bg-cyan-500/10 border-cyan-400/20',
    items: ['Live Census', 'Bed Management', 'Surge Forecasting', 'Block Utilization'] },
  { label: 'Standards & Security', tone: 'text-sky-300 bg-sky-500/10 border-sky-400/20',
    items: ['HIPAA', 'RBAC', 'OIDC SSO', 'Audit Logging', 'PHI Isolation'] },
];

export function AuthHero() {
  return (
    <motion.div
      initial={{ opacity: 0, x: -20 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.7, ease: [0.16, 1, 0.3, 1] }}
      className="w-full max-w-[640px] rounded-3xl border border-white/[0.08] bg-[#08090f]/55 backdrop-blur-2xl p-7 sm:p-9"
    >
      {/* Header */}
      <div className="flex items-center gap-3">
        <ZephyrusMark className="h-11 w-11" />
        <div>
          <h1 className="text-3xl font-extralight tracking-[0.18em] uppercase text-slate-100 leading-none">
            Zephyrus
          </h1>
        </div>
      </div>
      <p className="mt-3 text-xs font-medium uppercase tracking-[0.22em] text-slate-400">
        Healthcare Operations Platform
      </p>
      <div className="mt-4 h-0.5 w-12 rounded bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-400" />

      <p className="mt-4 text-sm leading-relaxed text-slate-400">
        Real-time hospital demand &amp; capacity, perioperative flow, and house-wide
        situational awareness — one operations command center for the whole hospital.
      </p>

      {/* Features — hidden on small screens */}
      <div className="mt-6 hidden lg:flex flex-col gap-2.5">
        {FEATURES.map((f) => (
          <div key={f.label} className="flex items-start gap-2.5">
            <Icon icon={f.icon} className="mt-0.5 w-4 h-4 shrink-0 text-cyan-400/80" />
            <div>
              <span className="block text-[0.8125rem] font-semibold text-slate-200 leading-tight">{f.label}</span>
              <span className="block text-xs text-slate-500 leading-snug">{f.desc}</span>
            </div>
          </div>
        ))}
      </div>

      {/* Pills — hidden on small screens */}
      <div className="mt-6 hidden lg:block space-y-3">
        {PILLS.map((group) => (
          <div key={group.label}>
            <p className="mb-1.5 text-[0.6875rem] font-semibold uppercase tracking-[0.1em] text-slate-500">
              {group.label}
            </p>
            <div className="flex flex-wrap gap-1.5">
              {group.items.map((p) => (
                <span key={p} className={`rounded-full border px-2.5 py-0.5 text-[0.6875rem] font-medium ${group.tone}`}>
                  {p}
                </span>
              ))}
            </div>
          </div>
        ))}
      </div>

      <div className="mt-7 hidden lg:block border-t border-white/[0.06] pt-3">
        <span className="text-[0.6875rem] tracking-wide text-slate-500">
          Acumenus Data Sciences &middot; Wellstack.ai
        </span>
      </div>
    </motion.div>
  );
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run tests/js/auth/AuthHero.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Auth/AuthHero.tsx tests/js/auth/AuthHero.test.tsx
git commit -m "feat(auth): add AuthHero brand panel"
```

---

## Task 5: Form panel wrapper (`AuthFormPanel`)

**Files:**
- Create: `resources/js/Components/Auth/AuthFormPanel.tsx`
- Test: `tests/js/auth/AuthFormPanel.test.tsx`

- [ ] **Step 1: Write the failing test**

`tests/js/auth/AuthFormPanel.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { AuthFormPanel } from '@/Components/Auth/AuthFormPanel';

describe('AuthFormPanel', () => {
  it('renders its children', () => {
    render(<AuthFormPanel><p>hello form</p></AuthFormPanel>);
    expect(screen.getByText('hello form')).toBeInTheDocument();
  });

  it('renders the animated shimmer spinner element', () => {
    const { container } = render(<AuthFormPanel><span /></AuthFormPanel>);
    expect(container.querySelector('.auth-shimmer__spin')).not.toBeNull();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run tests/js/auth/AuthFormPanel.test.tsx`
Expected: FAIL — cannot resolve `@/Components/Auth/AuthFormPanel`.

- [ ] **Step 3: Implement `resources/js/Components/Auth/AuthFormPanel.tsx`**

```tsx
import type { ReactNode } from 'react';

export interface AuthFormPanelProps {
  children: ReactNode;
}

export function AuthFormPanel({ children }: AuthFormPanelProps) {
  return (
    <div className="relative w-full max-w-[420px]">
      {/* Shimmer border — rotating conic gradient behind a clipped panel */}
      <div className="pointer-events-none absolute -inset-[2px] z-0 overflow-hidden rounded-[26px]">
        <div
          className="auth-shimmer__spin absolute -inset-1/2"
          style={{
            background:
              'conic-gradient(from 0deg, transparent 0%, transparent 20%,'
              + 'rgba(99,102,241,.6) 28%, rgba(99,102,241,.2) 35%, transparent 42%,'
              + 'transparent 55%, rgba(6,182,212,.55) 62%, rgba(6,182,212,.15) 70%,'
              + 'transparent 78%, transparent 100%)',
            animation: 'auth-shimmer-rotate 6s linear infinite',
          }}
        />
        <div className="absolute inset-[2px] rounded-[24px] bg-[#0b1120]/95" />
      </div>

      {/* Inner glass panel */}
      <div className="relative z-10 rounded-[24px] border border-white/[0.06] bg-[#0b1120]/65 p-7 backdrop-blur-2xl sm:p-8">
        {children}
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run tests/js/auth/AuthFormPanel.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Auth/AuthFormPanel.tsx tests/js/auth/AuthFormPanel.test.tsx
git commit -m "feat(auth): add AuthFormPanel shimmer-bordered glass card"
```

---

## Task 6: Orchestrator (`AuthLayout`)

**Files:**
- Create: `resources/js/Layouts/AuthLayout.tsx`
- Test: `tests/js/auth/AuthLayout.test.tsx`

- [ ] **Step 1: Write the failing test**

`tests/js/auth/AuthLayout.test.tsx`:

```tsx
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import AuthLayout from '@/Layouts/AuthLayout';

describe('AuthLayout', () => {
  beforeEach(() => { document.documentElement.classList.remove('dark'); });

  it('forces dark mode on the document root', () => {
    render(<AuthLayout><div>child</div></AuthLayout>);
    expect(document.documentElement.classList.contains('dark')).toBe(true);
  });

  it('renders children and the brand hero', () => {
    render(<AuthLayout><div>my form</div></AuthLayout>);
    expect(screen.getByText('my form')).toBeInTheDocument();
    expect(screen.getByText('Zephyrus')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx vitest run tests/js/auth/AuthLayout.test.tsx`
Expected: FAIL — cannot resolve `@/Layouts/AuthLayout`.

- [ ] **Step 3: Implement `resources/js/Layouts/AuthLayout.tsx`**

```tsx
import { useEffect, type ReactNode } from 'react';
import { motion } from 'framer-motion';
import { AuthBackground } from '@/Components/Auth/AuthBackground';
import { AuthHero } from '@/Components/Auth/AuthHero';
import { AuthFormPanel } from '@/Components/Auth/AuthFormPanel';

interface AuthLayoutProps {
  children: ReactNode;
}

export default function AuthLayout({ children }: AuthLayoutProps) {
  // Guest pages are dark-only. We add the class WITHOUT writing localStorage,
  // so the user's stored theme preference for the authenticated app is preserved
  // (the authenticated layout re-applies it on mount).
  useEffect(() => {
    document.documentElement.classList.add('dark');
  }, []);

  return (
    <div className="relative min-h-screen overflow-hidden bg-[#0a0f1f] text-slate-100">
      <AuthBackground />

      <div className="relative z-10 flex min-h-screen flex-col lg:flex-row">
        {/* Left — brand hero */}
        <div className="flex flex-1 items-center justify-center px-6 pt-10 lg:p-12">
          <AuthHero />
        </div>

        {/* Right — form panel */}
        <div className="flex flex-1 items-center justify-center px-6 pb-12 pt-6 lg:p-12">
          <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, ease: [0.16, 1, 0.3, 1], delay: 0.15 }}
            className="flex w-full justify-center"
          >
            <AuthFormPanel>{children}</AuthFormPanel>
          </motion.div>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx vitest run tests/js/auth/AuthLayout.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Type-check + commit**

```bash
npx tsc --noEmit
git add resources/js/Layouts/AuthLayout.tsx tests/js/auth/AuthLayout.test.tsx
git commit -m "feat(auth): add AuthLayout split-screen orchestrator (dark-only)"
```

Expected: `tsc` exits 0.

---

## Task 7: Source atmospheric imagery

**Files:**
- Create: `public/images/auth/wind-01.jpg` … `wind-05.jpg`

- [ ] **Step 1: Create the directory**

```bash
mkdir -p public/images/auth
```

- [ ] **Step 2: Obtain 5 royalty-free atmospheric wind/sky images**

Use the `firecrawl-search` skill (or Unsplash directly) to find 5 **royalty-free (Unsplash license)** dark, blue-hour / wind-over-water / dramatic-cloud photos. Download each into `public/images/auth/` as `wind-01.jpg` … `wind-05.jpg`. Unsplash direct-CDN URLs accept sizing params; fetch ~2000px wide, e.g.:

```bash
# Example shape (replace <PHOTO_ID> with real Unsplash photo ids you selected):
curl -L -o public/images/auth/wind-01.jpg \
  "https://images.unsplash.com/photo-<PHOTO_ID>?q=80&w=2000&auto=format&fit=crop"
# repeat for wind-02..wind-05
```

Prefer images that are predominantly **dark navy / teal / cyan** so they sit under the overlay without fighting the indigo palette. If sourcing is deferred, the page still renders correctly (fallback gradient + existing `17017066_8_blue.jpg`); only the extra slides are missing.

- [ ] **Step 3: Sanity-check file sizes**

```bash
ls -lh public/images/auth/
# Each file should be roughly 150KB–500KB. If any is >800KB, re-fetch with a smaller w= or
# re-encode: (requires imagemagick)  mogrify -resize 2000x -quality 78 public/images/auth/wind-0*.jpg
```

- [ ] **Step 4: Commit**

```bash
git add public/images/auth/
git commit -m "feat(auth): add atmospheric wind/sky slideshow imagery"
```

---

## Task 8: Reskin `Login.jsx`

**Files:**
- Modify: `resources/js/Pages/Auth/Login.jsx` (full rewrite of the page body; same props/behavior)

- [ ] **Step 1: Replace the file contents**

```jsx
import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Button, Checkbox } from '@heroui/react';
import { motion, AnimatePresence } from 'framer-motion';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function Login({ status, canResetPassword, oidcEnabled = false, oidcLabel = 'Sign in with Authentik' }) {
    const { data, setData, post, processing, errors } = useForm({
        username: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post('/login', {
            onFinish: () => {
                if (Object.keys(errors).length > 0) {
                    setData('password', '');
                }
            },
        });
    };

    return (
        <AuthLayout>
            <Head title="Sign In — Zephyrus" />

            {/* Heading */}
            <div className="mb-6 text-center">
                <h2 className="text-2xl font-light text-slate-100">Welcome back</h2>
                <p className="mt-1.5 text-sm text-slate-400">Sign in to continue to your dashboard</p>
            </div>

            {/* Status */}
            <AnimatePresence mode="wait">
                {status && (
                    <motion.div
                        initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="mb-5 flex items-center gap-2.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3"
                    >
                        <Icon icon="lucide:check-circle-2" className="h-4 w-4 shrink-0 text-emerald-400" />
                        <p className="text-sm text-emerald-300">{status}</p>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* Errors */}
            <AnimatePresence mode="wait">
                {(errors.username || errors.password || errors.email) && (
                    <motion.div
                        initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="mb-5 flex items-start gap-2.5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3"
                    >
                        <Icon icon="lucide:alert-circle" className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                        <div className="space-y-0.5">
                            {errors.username && <p className="text-sm text-red-300">{errors.username}</p>}
                            {errors.password && <p className="text-sm text-red-300">{errors.password}</p>}
                            {errors.email && <p className="text-sm text-red-300">{errors.email}</p>}
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="username" label="Username" icon="lucide:user"
                    value={data.username} onChange={(v) => setData('username', v)}
                    placeholder="Enter your username" autoComplete="username" autoFocus required
                />
                <AuthField
                    id="password" label="Password" icon="lucide:lock" type="password" revealable
                    value={data.password} onChange={(v) => setData('password', v)}
                    placeholder="Enter your password" autoComplete="current-password" required
                />

                <div className="flex items-center justify-between">
                    <Checkbox
                        isSelected={data.remember}
                        onValueChange={(checked) => setData('remember', checked)}
                        size="sm"
                        classNames={{ label: 'text-xs text-slate-400' }}
                    >
                        Remember me
                    </Checkbox>
                    {canResetPassword && (
                        <Link href="/forgot-password" className="text-xs font-medium text-indigo-300 transition-colors hover:text-indigo-200">
                            Forgot password?
                        </Link>
                    )}
                </div>

                <Button
                    type="submit" size="lg" isLoading={processing} radius="lg"
                    className="h-12 w-full bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 text-sm font-medium text-white shadow-lg shadow-indigo-500/20 transition-all duration-200 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 hover:shadow-indigo-500/30"
                    startContent={!processing && <Icon icon="lucide:arrow-right" className="h-4 w-4" />}
                >
                    {processing ? 'Signing in…' : 'Sign in'}
                </Button>
            </form>

            {oidcEnabled && (
                <div className="mt-5">
                    <div className="relative flex items-center">
                        <div className="flex-grow border-t border-white/10" />
                        <span className="mx-3 text-xs text-slate-500">or</span>
                        <div className="flex-grow border-t border-white/10" />
                    </div>
                    <a
                        href="/auth/oidc/redirect"
                        className="mt-4 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/[0.04] text-sm font-medium text-slate-200 transition-colors hover:bg-white/[0.08]"
                    >
                        <Icon icon="lucide:shield-check" className="h-4 w-4 text-indigo-400" />
                        {oidcLabel}
                    </a>
                </div>
            )}

            {/* Create Account CTA — REQUIRED by auth-system.md (do not remove) */}
            <div className="mt-6 rounded-xl border border-indigo-400/20 bg-indigo-500/[0.08] px-5 py-4">
                <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-500/20">
                            <Icon icon="lucide:user-plus" className="h-4 w-4 text-indigo-300" />
                        </div>
                        <div>
                            <p className="text-sm font-medium text-slate-200">New here?</p>
                            <p className="text-xs text-slate-400">Create an account to get started</p>
                        </div>
                    </div>
                    <Link
                        href="/register"
                        className="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-xs font-medium text-white shadow-sm transition-all duration-200 hover:bg-indigo-500"
                    >
                        Create Account
                        <Icon icon="lucide:arrow-right" className="h-3.5 w-3.5" />
                    </Link>
                </div>
            </div>

            {/* Demo credentials — subtle footnote */}
            <div className="mt-4 flex items-center justify-center gap-x-4 text-xs text-slate-500">
                <span className="inline-flex items-center gap-1.5">
                    <Icon icon="lucide:info" className="h-3.5 w-3.5 text-sky-400/70" />
                    Demo:
                </span>
                <span>user <code className="font-semibold text-slate-300">admin</code></span>
                <span>pass <code className="font-semibold text-slate-300">password</code></span>
            </div>
        </AuthLayout>
    );
}
```

- [ ] **Step 2: Type-check + build**

Run: `npx tsc --noEmit && npx vite build`
Expected: both succeed.

- [ ] **Step 3: Preserve checklist (verify against `.claude/rules/auth-system.md`)**

- [ ] Username + password fields present; `post('/login', …)` unchanged
- [ ] `remember` checkbox + `canResetPassword` → `/forgot-password` link present
- [ ] OIDC block renders when `oidcEnabled`, links to `/auth/oidc/redirect`, uses `oidcLabel`
- [ ] **"Create Account" CTA → `/register` present** (rule 1)
- [ ] `status` and `errors.{username,password,email}` still displayed
- [ ] Props signature unchanged: `{ status, canResetPassword, oidcEnabled, oidcLabel }`

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Auth/Login.jsx
git commit -m "feat(auth): reskin Login onto cinematic AuthLayout (behavior preserved)"
```

---

## Task 9: Reskin `Register.jsx`

**Files:**
- Modify: `resources/js/Pages/Auth/Register.jsx` (full rewrite; passwordless flow preserved)

- [ ] **Step 1: Replace the file contents**

```jsx
import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useState } from 'react';
import { Button } from '@heroui/react';
import { motion, AnimatePresence } from 'framer-motion';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function Register() {
    const [success, setSuccess] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        phone: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/register', {
            onSuccess: () => setSuccess(true),
        });
    };

    return (
        <AuthLayout>
            <Head title="Create Account — Zephyrus" />

            <div className="mb-6 text-center">
                <h2 className="text-2xl font-light text-slate-100">Create Account</h2>
                <p className="mt-1.5 text-sm text-slate-400">Sign up to get started with Zephyrus</p>
            </div>

            <AnimatePresence mode="wait">
                {(errors.name || errors.email || errors.phone) && (
                    <motion.div
                        initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="mb-5 flex items-start gap-2.5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3"
                    >
                        <Icon icon="lucide:alert-circle" className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                        <div className="space-y-0.5">
                            {errors.name && <p className="text-sm text-red-300">{errors.name}</p>}
                            {errors.email && <p className="text-sm text-red-300">{errors.email}</p>}
                            {errors.phone && <p className="text-sm text-red-300">{errors.phone}</p>}
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            <AnimatePresence mode="wait">
                {success ? (
                    <motion.div
                        key="success"
                        initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="py-4 text-center"
                    >
                        <div className="mb-4 flex justify-center">
                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-500/15">
                                <Icon icon="lucide:mail-check" className="h-7 w-7 text-emerald-400" />
                            </div>
                        </div>
                        <h3 className="mb-2 text-lg font-medium text-slate-100">Check your inbox</h3>
                        <p className="mb-6 text-sm text-slate-400">
                            We've sent your temporary password and username to your email address. Use them to sign in.
                        </p>
                        <Link
                            href="/login"
                            className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 px-5 py-2.5 text-sm font-medium text-white shadow-lg shadow-indigo-500/20 transition-all duration-200 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600"
                        >
                            <Icon icon="lucide:arrow-left" className="h-4 w-4" />
                            Go to Sign In
                        </Link>
                    </motion.div>
                ) : (
                    <motion.form key="form" onSubmit={submit} className="space-y-5" initial={{ opacity: 1 }} exit={{ opacity: 0 }}>
                        <AuthField
                            id="name" label="Full Name" icon="lucide:user"
                            value={data.name} onChange={(v) => setData('name', v)}
                            placeholder="Enter your full name" autoComplete="name" autoFocus required
                        />
                        <AuthField
                            id="email" label="Email Address" icon="lucide:mail" type="email"
                            value={data.email} onChange={(v) => setData('email', v)}
                            placeholder="Enter your email address" autoComplete="email" required
                        />
                        <AuthField
                            id="phone" label="Phone Number" icon="lucide:phone" type="tel" optional
                            value={data.phone} onChange={(v) => setData('phone', v)}
                            placeholder="Enter your phone number" autoComplete="tel"
                        />

                        <Button
                            type="submit" size="lg" isLoading={processing} radius="lg"
                            className="h-12 w-full bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 text-sm font-medium text-white shadow-lg shadow-indigo-500/20 transition-all duration-200 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 hover:shadow-indigo-500/30"
                            startContent={!processing && <Icon icon="lucide:user-plus" className="h-4 w-4" />}
                        >
                            {processing ? 'Creating account…' : 'Create Account'}
                        </Button>

                        <div className="pt-1 text-center">
                            <span className="text-sm text-slate-400">Already have an account? </span>
                            <Link href="/login" className="text-sm font-medium text-indigo-300 transition-colors hover:text-indigo-200">
                                Sign in
                            </Link>
                        </div>
                    </motion.form>
                )}
            </AnimatePresence>
        </AuthLayout>
    );
}
```

- [ ] **Step 2: Type-check + build**

Run: `npx tsc --noEmit && npx vite build`
Expected: both succeed.

- [ ] **Step 3: Preserve checklist (auth-system.md)**

- [ ] Only `name`, `email`, `phone` fields — **NO password field** (rules 3, 12)
- [ ] `post('/register', …)` unchanged; `onSuccess` shows the "Check your inbox" state
- [ ] "Already have an account? Sign in" → `/login` present

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Auth/Register.jsx
git commit -m "feat(auth): reskin Register onto AuthLayout (passwordless flow preserved)"
```

---

## Task 10: Reskin `ChangePassword.jsx`

**Files:**
- Modify: `resources/js/Pages/Auth/ChangePassword.jsx` (full rewrite)

- [ ] **Step 1: Replace the file contents**

```jsx
import { Head, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Button } from '@heroui/react';
import { motion, AnimatePresence } from 'framer-motion';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function ChangePassword() {
    const { data, setData, post, processing, errors } = useForm({
        current_password: '',
        new_password: '',
        new_password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/change-password', {
            onFinish: () => {
                if (Object.keys(errors).length > 0) {
                    setData('current_password', '');
                    setData('new_password', '');
                    setData('new_password_confirmation', '');
                }
            },
        });
    };

    return (
        <AuthLayout>
            <Head title="Change Password — Zephyrus" />

            <div className="mb-6 text-center">
                <div className="mb-3 flex justify-center">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-amber-500/15">
                        <Icon icon="lucide:shield-alert" className="h-6 w-6 text-amber-400" />
                    </div>
                </div>
                <h2 className="text-2xl font-light text-slate-100">Change Password</h2>
                <p className="mt-1.5 text-sm text-slate-400">You must change your temporary password before continuing</p>
            </div>

            <AnimatePresence mode="wait">
                {(errors.current_password || errors.new_password || errors.new_password_confirmation) && (
                    <motion.div
                        initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="mb-5 flex items-start gap-2.5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3"
                    >
                        <Icon icon="lucide:alert-circle" className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                        <div className="space-y-0.5">
                            {errors.current_password && <p className="text-sm text-red-300">{errors.current_password}</p>}
                            {errors.new_password && <p className="text-sm text-red-300">{errors.new_password}</p>}
                            {errors.new_password_confirmation && <p className="text-sm text-red-300">{errors.new_password_confirmation}</p>}
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="current_password" label="Current (Temporary) Password" icon="lucide:key" type="password" revealable
                    value={data.current_password} onChange={(v) => setData('current_password', v)}
                    placeholder="Enter your temporary password" autoComplete="current-password" autoFocus required
                />
                <AuthField
                    id="new_password" label="New Password" icon="lucide:lock" type="password" revealable
                    value={data.new_password} onChange={(v) => setData('new_password', v)}
                    placeholder="Choose a new password (min 8 characters)" autoComplete="new-password" required
                />
                <AuthField
                    id="new_password_confirmation" label="Confirm New Password" icon="lucide:lock-keyhole" type="password" revealable
                    value={data.new_password_confirmation} onChange={(v) => setData('new_password_confirmation', v)}
                    placeholder="Confirm your new password" autoComplete="new-password" required
                />

                <Button
                    type="submit" size="lg" isLoading={processing} radius="lg"
                    className="h-12 w-full bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 text-sm font-medium text-white shadow-lg shadow-indigo-500/20 transition-all duration-200 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 hover:shadow-indigo-500/30"
                    startContent={!processing && <Icon icon="lucide:check" className="h-4 w-4" />}
                >
                    {processing ? 'Changing password…' : 'Change Password'}
                </Button>
            </form>
        </AuthLayout>
    );
}
```

- [ ] **Step 2: Type-check + build**

Run: `npx tsc --noEmit && npx vite build`
Expected: both succeed.

- [ ] **Step 3: Preserve checklist (auth-system.md)**

- [ ] `current_password`, `new_password`, `new_password_confirmation` fields present
- [ ] `post('/change-password', …)` and the field-clearing `onFinish` unchanged

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Auth/ChangePassword.jsx
git commit -m "feat(auth): reskin ChangePassword onto AuthLayout"
```

---

## Task 11: Reskin remaining guest pages

Bring `ForgotPassword`, `ResetPassword`, `VerifyEmail`, `ConfirmPassword` onto `AuthLayout` + `AuthField` so the whole guest suite is consistent. For each: **read the current file first** to capture its exact `useForm` fields, props, `post()` target, and any status text — then swap `GuestLayout`→`AuthLayout`, replace inputs with `AuthField`, remove the inline `useDarkMode`/toggle, and reuse the heading + error-banner pattern from Task 8.

- [ ] **Step 1: Reskin `resources/js/Pages/Auth/ForgotPassword.jsx`**

Read it, then rewrite preserving its `email` field, `post('/forgot-password')`, and the `status` success message. Use one `AuthField` (email) + the gradient submit button + a "Back to sign in" `Link` to `/login`. Heading: "Reset your password" / subtitle "We'll email you a secure reset link."

- [ ] **Step 2: Reskin `resources/js/Pages/Auth/ResetPassword.jsx`**

Read it, then rewrite preserving its `token`/`email`/`password`/`password_confirmation` fields and `post('/reset-password')`. Email `AuthField` (or read-only if pre-filled) + two `revealable` password `AuthField`s + submit. Heading: "Choose a new password."

- [ ] **Step 3: Reskin `resources/js/Pages/Auth/VerifyEmail.jsx`**

Read it, then rewrite preserving `post('/email/verification-notification')`, the "resend" button, and the logout link. No `AuthField` needed — heading + explanatory copy + buttons. Heading: "Verify your email."

- [ ] **Step 4: Reskin `resources/js/Pages/Auth/ConfirmPassword.jsx`**

Read it, then rewrite preserving its `password` field and `post('/confirm-password')`. One `revealable` password `AuthField` + submit. Heading: "Confirm your password."

- [ ] **Step 5: Type-check + build**

Run: `npx tsc --noEmit && npx vite build`
Expected: both succeed.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Auth/ForgotPassword.jsx resources/js/Pages/Auth/ResetPassword.jsx resources/js/Pages/Auth/VerifyEmail.jsx resources/js/Pages/Auth/ConfirmPassword.jsx
git commit -m "feat(auth): reskin forgot/reset/verify/confirm pages onto AuthLayout"
```

---

## Task 12: Final verification & manual QA

- [ ] **Step 1: Full test suite**

Run: `npx vitest run`
Expected: all tests pass (existing + the 5 new auth suites). If any pre-existing unrelated test was already failing on `main`, note it and do not "fix" it here.

- [ ] **Step 2: Type-check + strict build**

Run: `npx tsc --noEmit && npx vite build`
Expected: both succeed (vite build catches unresolved imports tsc misses).

- [ ] **Step 3: Manual QA (dev server)**

```bash
npm run dev   # or the project's usual dev workflow
```

Check at a **desktop** width (≥1024px) and a **mobile** width (<1024px):
- [ ] `/login` — split layout; slideshow crossfades; shimmer border animates; form posts; **Create Account CTA visible**; OIDC button appears only when enabled; demo footnote shows.
- [ ] `/register` — no password field; submit shows "Check your inbox".
- [ ] `/change-password` — three reveal-toggle fields render.
- [ ] `/forgot-password`, `/reset-password`, `/verify-email`, `/confirm-password` — consistent shell.
- [ ] Mobile: hero collapses to compact header (features/pills hidden), form centers, background fills screen.
- [ ] Reduced motion: with OS "reduce motion" on, slideshow holds on the first image and the shimmer border stops rotating.
- [ ] A broken/missing slideshow image still looks intentional (fallback gradient shows).

- [ ] **Step 4: Deploy (per project convention)**

After merge to the working branch is approved:

```bash
./deploy.sh --frontend
```

(Per global rules: run `./deploy.sh --frontend` after frontend changes — `vite build` alone is not the deploy.)

---

## Self-Review (completed by plan author)

- **Spec coverage:** §3 layout → Task 6; §4 hero → Task 4; §5 form panel + per-page forms → Tasks 5, 8, 9, 10; §6 component architecture → Tasks 1–6; §7 assets/motion → Tasks 1, 3, 7; §8 dark-only → Task 6; §9 preserved behavior → Preserve checklists in Tasks 8–10; §10 follow-on pages → Task 11; §11 verification → Task 12. All sections mapped.
- **Placeholder scan:** No "TBD/TODO/handle edge cases". Task 7 image URLs are intentionally parameterized (real Unsplash IDs are selected at execution) with a graceful fallback documented; Task 11 instructs read-then-rewrite because those four pages weren't read during planning — each step names the exact fields/POST targets to preserve.
- **Type/name consistency:** `AuthField` prop names (`id`, `label`, `value`, `onChange`, `icon`, `type`, `revealable`, `optional`, `autoFocus`, `autoComplete`, `placeholder`, `required`, `error`) are identical across the component definition (Task 2) and every consumer (Tasks 8–10). `AuthLayout` is a default export consumed as `import AuthLayout from '@/Layouts/AuthLayout'` everywhere. `AUTH_BACKGROUND_IMAGES`/`AUTH_BACKGROUND_FALLBACK` names match between `authBackgrounds.ts` and `AuthBackground.tsx`. The `.auth-shimmer__spin` class matches between `auth.css`, `AuthFormPanel.tsx`, and its test.
```
