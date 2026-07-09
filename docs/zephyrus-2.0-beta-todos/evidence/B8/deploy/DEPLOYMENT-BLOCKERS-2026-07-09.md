# Deployment Preflight And Deferred Evidence - 2026-07-09

Deployment was initially deferred while the checkout contained uncommitted product and evidence changes. The user then explicitly approved commit, push, deployment, and continuation on the current branch.

## Cleared Before Deploy

- Commit the intended beta hardening slice.
- Push the branch so `deploy.sh` sees the local branch current with its upstream.
- Verify `git status --short` is clean.
- Verify `git rev-list --left-right --count @{u}...HEAD` is `0 0`.
- Run `./deploy.sh` from `/home/smudoshi/Github/Zephyrus`.

## Deployment Constraints

- `deploy.sh` is the only approved production deployment mechanism.
- GitHub Actions must not deploy production.
- `deploy.sh` builds assets, rsyncs to `/var/www/Zephyrus`, clears Laravel caches, restarts Apache, and verifies the Zephyrus vhost with `Host: zephyrus.acumenus.net`.
- `deploy.sh` does not run Laravel migrations. This slice did not add Laravel migrations, so production `migrate --force` is not required unless `migrate:status` shows pending migrations from another release.
- Post-deploy runtime proof must be captured after the clean deployment finishes.

## Evidence Captured After Deploy

Captured in `DEPLOYMENT-RESULT-2026-07-09.md`:

1. `./deploy.sh` result.
2. `sudo -u www-data php artisan migrate:status` from `/var/www/Zephyrus`.
3. Targeted Patient Flow migration result.
4. `sudo -u www-data php artisan route:list --path=api/patient-flow`.
5. `sudo -u www-data php artisan route:list --path=api/mobile/v1`.
6. `sudo -u www-data php artisan route:list --path=api/eddy`.
7. `sudo -u www-data php artisan schedule:list`.
8. `timeout 120s sudo -u www-data php artisan schedule:run -vvv`.
9. `sudo -u www-data php artisan flow:snapshot`.
10. `sudo -u www-data php artisan queue:failed`.
11. `sudo -u www-data php artisan config:show queue.default`.
12. Apache active state.
13. Local vhost HTTP status with `Host: zephyrus.acumenus.net`.
14. Public HTTPS header/status check.
15. Public `/api/health`.

## Non-Blocking Limitations

- iOS build evidence is unavailable on this Linux host and remains a macOS/Xcode follow-up.
- Manual screenshot PHI review, authenticated mobile/Eddy/Integration Health browser smoke, Reverb/fallback proof, and full demo rehearsal remain release limitations until completed by a human/operator with browser, beta credentials, and native-device access.
