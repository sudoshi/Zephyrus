# 06 — Security, HIPAA & PHI

Hummingbird handles operational data that is **PHI-adjacent or PHI** on personal, mobile,
easily-lost devices. Security is a release gate, not a backlog. This document is the
checklist + acceptance criteria, grounded in
[research/07-mobile-best-practices.md](../research/07-mobile-best-practices.md) and bounded by
the locked auth rules in [.claude/rules/auth-system.md](../../../.claude/rules/auth-system.md).

> **Prime directive:** the cheapest, highest-impact HIPAA wins are (1) **no PHI in
> notifications/logs/app-switcher snapshots**, (2) **biometric-gated token storage + auto-
> lock**, and (3) **TLS + cert pinning with no PHI on public channels**. Do these first and
> well.

---

## 1. Authentication & session (additive to the locked flow)

- [ ] Token-based auth (**Sanctum PATs** v1; **OIDC + PKCE** via existing Authentik for SSO
      orgs) — a *new path*, never a modification of the temp-password / `must_change_password`
      / Resend flow. → [04 §1](04-backend-requirements.md).
- [ ] **PKCE** for any OAuth/OIDC native flow; system browser (ASWebAuthenticationSession /
      Custom Tabs via AppAuth), never an embedded webview.
- [ ] **Short-lived access tokens + rotating refresh tokens**; refresh rotation detects
      replay (revoke family on reuse).
- [ ] Tokens stored in **Keychain** (iOS, `kSecAttrAccessibleWhenUnlockedThisDeviceOnly`,
      `.biometryCurrentSet`) / **Android Keystore-backed EncryptedSharedPreferences**;
      **never** in plain prefs/UserDefaults.
- [ ] **Biometric unlock** (Face ID/Touch ID / BiometricPrompt) on cold start and after
      **idle auto-lock** (configurable, default short); device-passcode fallback.
- [ ] **Honor `must_change_password`** on mobile: forced-change challenge before any
      operational screen; the superuser account behavior is untouched.
- [ ] **Server-side revocation / remote wipe**: `/auth/token/revoke` + device-revoke so a
      lost device's tokens die immediately; tie to MDM where present.
- [ ] **Token abilities/scopes** match the user's role (defense in depth behind the UI).

## 2. Transport security

- [ ] **TLS 1.2+ everywhere**; no cleartext (Android `cleartextTrafficPermitted=false`, iOS
      ATS enforced).
- [ ] **Certificate / public-key pinning** to the Zephyrus API/BFF and Reverb hosts, with a
      backup pin + a remote kill-switch for rotation.
- [ ] **No PHI on public WS channels** — mirror the web's PHI-free broadcast posture
      (counts/ids only). Any future PHI-on-wire requires `PrivateChannel` + token channel
      auth. → [04 §4](04-backend-requirements.md).

## 3. Data at rest

- [ ] Local cache (**SQLDelight**) holding any PHI is **encrypted** (SQLCipher / Keystore-
      derived key); key gated by biometric/passcode.
- [ ] **Data minimization in the cache**: store the least PHI needed; prefer tokens/initials;
      purge on logout and on configurable TTL.
- [ ] **No PHI in app logs, crash reports, or analytics.** A hard client-side **PHI log
      filter**; analytics/crash SDKs must be **BAA-covered** or PHI-free by construction.
- [ ] **No PHI in the app-switcher snapshot**: Android `FLAG_SECURE` on PHI screens; iOS
      privacy overlay on `scenePhase` background/inactive.

## 4. PHI handling rules (the bright lines)

- [ ] **Notifications are PHI-free** — generic copy only; detail appears only in-app after
      unlock. Automated **payload linter** in CI fails the build on any PHI field in a push.
      → [05 §6](05-notifications-earned-urgency.md).
- [ ] **Lists & glance surfaces are PHI-minimized** — widgets, Live Activities, watch
      complications, and For-You list items show **operational** facts (counts, statuses,
      unit/room), not patient identity. Full PHI is an explicit, authorized **detail** call.
- [ ] **Screenshot/screen-record awareness** on PHI detail screens (iOS detection + privacy
      view; Android `FLAG_SECURE`).
- [ ] **Clipboard discipline** — no auto-copy of PHI; sensitive fields excluded from
      keyboard learning/autofill.

## 5. Device & platform integrity

- [ ] **Jailbreak/root detection** + **device attestation** (App Attest / Play Integrity);
      degrade or block on compromised devices per policy.
- [ ] **MDM compatibility** (managed app config, remote wipe, per-app VPN) for enterprise
      deployments; document the unmanaged-BYOD posture too.
- [ ] **Minimum OS versions** with security support; block known-vulnerable OS below a floor.
- [ ] **Supply chain:** pinned dependencies, SBOM, no non-BAA SDK that can see PHI.

## 6. Authorization

- [ ] **Action-level RBAC** (Laravel Policies) so a device can only *act* within its role/
      assignment — only the assigned transporter progresses a trip; only an authorized
      approver decides an action. → [04 §3](04-backend-requirements.md).
- [ ] **Reads role-scoped + PHI-gated** by the BFF; broad glanceable status, gated identity.

## 7. Audit & compliance

- [ ] **Audit logging** of security-relevant actions (login, token issue/refresh/revoke, PHI
      detail views, every operational mutation, notification deliveries/escalations) —
      server-side, tamper-evident, PHI-appropriate.
- [ ] **HIPAA Security Rule** mapping: access control (§164.312(a)), audit controls (b),
      integrity (c), authentication (d), transmission security (e) — each mapped to a control
      above.
- [ ] **BAAs** in place for any third-party processing PHI (push providers see **no** PHI by
      design; analytics/crash must be BAA-covered or PHI-free).
- [ ] **Data retention & purge** policy for on-device cache and server audit logs.
- [ ] **Incident response & remote-wipe runbook** for lost/stolen devices.

## 8. Accessibility as a safety property (WCAG 2.2 AA pragmatic)

- [ ] **Status never by color alone** (icon/arrow/label) — survives color-blindness and a
      sunlit/grayscale screen.
- [ ] Dynamic Type / font scaling; VoiceOver/TalkBack labels on every status and action;
      44pt minimum targets; `prefers-reduced-motion` honored.

---

## 9. Release-gate acceptance criteria

Hummingbird may not GA unless **all** hold:

1. **Zero PHI** demonstrably leaves the secure boundary in notifications, logs, crash
   reports, analytics, or app-switcher snapshots (verified by automated checks + a manual
   PHI-leak audit).
2. **Token auth is additive** — a regression suite proves the web temp-password /
   `must_change_password` / Resend / superuser flows are **unchanged**.
3. **Biometric + auto-lock + remote-revoke** all enforced and tested (including the
   lost-device wipe path).
4. **Cert pinning** active with a tested rotation/kill-switch.
5. **Action RBAC** proven: a user cannot perform an action outside their role/assignment even
   with a tampered client.
6. **Audit trail** captures every mutation and security event.
7. A **named security review + clinical-safety review** sign off (the latter specifically on
   the [notification taxonomy](05-notifications-earned-urgency.md)).

> Items 1, 2, and 7 are the three that most often slip and most badly hurt — treat them as
> P0 acceptance criteria written into the definition of done, not late QA.
