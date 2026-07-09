# Deployment Result - 2026-07-09

Date: 2026-07-09
Operator: Codex
Branch: `feat/hummingbird-4d-service-line-eddy`
Commit deployed: `2e58cf2a8492bbcd0e13c746725b08c7278a337e`
Production path: `/var/www/Zephyrus`
Deploy mechanism: `./deploy.sh`

## Preflight

| Check | Result |
| --- | --- |
| `git status --short --branch` | clean on `feat/hummingbird-4d-service-line-eddy` |
| `git rev-list --left-right --count @{u}...HEAD` | `0 0` |
| Branch pushed | yes |
| User approval for feature-branch deploy | yes, 2026-07-09 |

## Deploy Command

```bash
cd /home/smudoshi/Github/Zephyrus
./deploy.sh
```

Result: pass.

Observed deploy stages:

- Remote status current.
- `npm run build` completed through Vite production build with existing large-chunk/Browserslist warnings.
- Files were rsynced to `/var/www/Zephyrus`.
- Ownership was reset to `www-data:www-data`.
- Laravel cache, view, config, and route caches were cleared.
- Apache restarted.
- Zephyrus vhost verification passed with `Host: zephyrus.acumenus.net`.

## Production Migration Status

Initial `migrate:status` showed pending legacy/base migrations plus pending service-line/staffing alignment migrations and:

- `2026_07_05_000400_extend_patient_flow_occupancy_snapshots_for_disk_details` pending.

The deployed Patient Flow snapshot/history code requires the new `flow_core.occupancy_snapshots` detail columns, and production did not have them.

Targeted migration run:

```bash
cd /var/www/Zephyrus
sudo -u www-data php artisan migrate --force --path=database/migrations/2026_07_05_000400_extend_patient_flow_occupancy_snapshots_for_disk_details.php
```

Result: pass in 13.57ms.

Post-migration column check:

- `occupancy_details`: true.
- `timer_status_counts`: true.
- `service_line_timer_counts`: true.
- `persona_timer_counts`: true.
- `projection_window`: true.

Post-migration `migrate:status` shows `2026_07_05_000400_extend_patient_flow_occupancy_snapshots_for_disk_details` ran in batch 13.

Not run:

- Blanket `php artisan migrate --force`.
- Pending service-line/staffing alignment migrations.
- Pending legacy/base migrations that are inconsistent with this multi-schema migration history.

## Post-Deploy Smoke Results

| Command | Result |
| --- | --- |
| `sudo -u www-data php artisan route:list --path=api/patient-flow` | pass; 13 routes including `demo-scenarios` and `occupancy/history` |
| `sudo -u www-data php artisan route:list --path=api/mobile/v1` | pass; 42 mobile routes |
| `sudo -u www-data php artisan route:list --path=api/eddy` | pass; 13 Eddy routes |
| `sudo -u www-data php artisan route:list --path=api/health` | pass; 1 route |
| `sudo -u www-data php artisan schedule:list` | pass; cockpit, flow snapshot, materialized view, pruning, OCEL, and Arena schedules listed |
| `timeout 120s sudo -u www-data php artisan schedule:run -vvv` | pass; `App\Jobs\RefreshCockpitSnapshot` ran in 223.47ms |
| `sudo -u www-data php artisan flow:snapshot` | pass; captured 25 unit checkpoints for `2026-07-09 02:00:00` |
| `sudo -u www-data php artisan queue:failed` | pass; no failed jobs |
| `sudo -u www-data php artisan config:show queue.default` | pass; `sync` |
| `sudo systemctl is-active apache2` | pass; `active` |
| `sudo crontab -u www-data -l` | pass; Laravel scheduler installed every minute |
| `curl -H 'Host: zephyrus.acumenus.net' http://localhost/` | pass; `301` to HTTPS |
| `curl -I https://zephyrus.acumenus.net/` | pass; `302` to `/login` with security headers |
| `curl https://zephyrus.acumenus.net/api/health` | pass; `200`, database connected |
| `curl https://zephyrus.acumenus.net/api/patient-flow/demo-scenarios` | expected unauthenticated `302` to login |
| `curl https://zephyrus.acumenus.net/api/mobile/v1/me` | expected unauthenticated `302` to login |
| `curl https://zephyrus.acumenus.net/api/eddy/actions/catalog` | expected unauthenticated `302` to login |

## Log Notes

- Apache journal for the deployment window shows stop/start and successful restart.
- Laravel log tail included two `Writing to directory /var/www/.config/psysh is not allowed` errors from the operator's first schema probe using `php artisan tinker` without a writable `HOME`/`XDG_CONFIG_HOME`.
- Corrected schema probes used `sudo -u www-data env XDG_CONFIG_HOME=/tmp HOME=/tmp php artisan tinker ...` and passed.
- Laravel log tail also contained an older `OrganizationController` stack trace; it was not reproduced by the post-deploy smoke block.

## Remaining Runtime Evidence

- Authenticated mobile BFF response samples with beta token/session.
- Authenticated Eddy catalog/proposal smoke with approved beta session/token.
- Integration Health browser/admin smoke.
- Reverb/fallback runtime proof.
- 15-30 minute monitoring window after a scheduler interval.

## Post-Review Hardening Deploy Target

After the first deployment, adversarial review produced additional product fixes:

- Patient Flow history redaction across web/mobile.
- Mobile persona-specific write gates.
- Eddy web/mobile role gates.
- OR case write authorization and current-schema mapping.
- Android release HTTPS/WSS defaults and debug-only cleartext.
- iOS query encoding and mobile Patient Flow parity calls.
- Login failed-auth error rendering.

These changes add no new Laravel migrations. The final deploy for this run should:

1. Commit and push the post-review hardening tranche.
2. Run `./deploy.sh` from a clean, current branch.
3. Re-run health/vhost, route-list, scheduler, queue, Patient Flow, mobile route, and Eddy route smokes.
4. Leave unrelated pending migrations untouched.
