# DEVLOG — Authentik SSO fleet verification

**Date:** 2026-06-22
**Author:** Sanjay Udoshi (with Claude Code)
**Status:** Verified live — no code change

---

## Summary

During the fleet-wide "Login with Authentik" rollout (which shipped new OIDC to
COPE and MediCosts), Zephyrus's existing SSO was **verified live in production**
and required **no changes**.

## Findings

- The `/login` page renders the "Sign in with Authentik" button
  (Inertia props `oidcEnabled: true`, `oidcLabel: "Sign in with Authentik"`).
- `GET https://zephyrus.acumenus.net/auth/oidc/redirect` → `302` to
  `auth.acumenus.net/application/o/authorize/` with a valid `client_id`,
  `redirect_uri=https://zephyrus.acumenus.net/auth/oidc/callback`,
  `scope=openid profile email groups`, `code_challenge_method=S256`.
- Authentik app `zephyrus-oidc` (provider pk 48) exists; bound to **both**
  "Zephyrus Users" (currently empty) and **"Zephyrus Admins"** (the 7 Acumenus
  admins: `sudoshi, ebruno, kpatel, jdawe, dmuraco, gbock, admin`). With policy
  engine mode `any`, the 7 in "Zephyrus Admins" can launch and are mapped to
  the admin role server-side.

## Note on repo vs. deployed state

The deployed `/var/www/Zephyrus/.env` has `OIDC_ENABLED=true` with client
credentials set; the live 302 confirms it. No redeploy was performed (local
`main` carries unrelated unpushed commits and the prod deploy is an rsync
build-in-dev step, not a git push).

See `reference_authentik_sso_fleet` in Claude memory for the full per-app map.
