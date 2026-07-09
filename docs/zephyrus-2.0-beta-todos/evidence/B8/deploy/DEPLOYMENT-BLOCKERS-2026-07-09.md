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

## Evidence Still Required After Deploy

1. `./deploy.sh` output.
2. `sudo -u www-data php artisan migrate:status` from `/var/www/Zephyrus`.
3. `sudo -u www-data php artisan route:list --path=api/patient-flow`.
4. `sudo -u www-data php artisan schedule:list`.
5. `sudo -u www-data php artisan queue:failed`.
6. `sudo -u www-data php artisan config:show queue.default`.
7. Apache active state.
8. Local vhost HTTP status with `Host: zephyrus.acumenus.net`.
9. Public HTTPS header/status check.
10. Scheduler, queue, Reverb/fallback, cockpit, Patient Flow, mobile BFF, Eddy, and Integration Health smoke disposition.

## Non-Blocking Limitations

- iOS build evidence is unavailable on this Linux host and remains a macOS/Xcode follow-up.
- Manual screenshot PHI review and full demo rehearsal remain release-limitations until completed by a human/operator with browser and native-device access.
