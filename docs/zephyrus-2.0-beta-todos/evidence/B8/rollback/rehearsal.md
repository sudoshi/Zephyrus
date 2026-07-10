# Rollback Rehearsal And Plan

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Status: plan documented; app rollback route available; production rollback drill not executed

## Rollback Triggers

- `./deploy.sh` fails after sync or Apache restart.
- Zephyrus vhost returns non-2xx/3xx after deploy.
- Laravel logs show new fatal errors.
- PHI exposure is found in screenshots, logs, push payloads, or Eddy prompts.
- Mobile BFF, Patient Flow, cockpit, or Eddy catalog is unavailable after deploy.
- Scheduler or queue behavior degrades a required beta workflow.

## Current Slice Schema Position

- The post-review hardening slice adds no Laravel migrations.
- The prior 2026-07-09 deploy applied one additive Patient Flow migration for `flow_core.occupancy_snapshots` detail columns.
- Rollback is expected to be app-artifact/commit based unless production has unrelated pending migrations or a database restore is explicitly approved.
- Run `sudo -u www-data php artisan migrate:status` before and after deployment.
- Do not run `migrate --force` unless migration status proves it is needed and the release owner approves it.

## App Rollback Path

1. Identify the previous known-good commit or app backup.
2. If using Git source rollback, check out the previous known-good commit in `/home/smudoshi/Github/Zephyrus`, commit/push state must be intentional, and run `./deploy.sh`.
3. If using artifact rollback, restore the approved `/var/www/Zephyrus` artifact backup.
4. Clear Laravel caches as `www-data`.
5. Restart Apache.
6. Run post-rollback smokes.

## Required Smoke Checks

```bash
cd /var/www/Zephyrus
sudo -u www-data php artisan migrate:status
sudo -u www-data php artisan route:list --path=api/patient-flow
sudo -u www-data php artisan schedule:list
sudo -u www-data php artisan queue:failed
sudo -u www-data php artisan config:show queue.default
sudo systemctl is-active apache2
curl -sS -o /dev/null -w '%{http_code}\n' -H 'Host: zephyrus.acumenus.net' http://localhost/
curl -sSI https://zephyrus.acumenus.net/ | sed -n '1,20p'
```

## Database Rollback

- No database rollback is expected for the post-review hardening slice.
- Do not drop the additive Patient Flow detail columns during an app rollback; older code should tolerate the extra columns, and dropping them would risk losing snapshot/history evidence.
- If unrelated pending migrations or data corruption are found, stop and use the established ops-approved PostgreSQL backup/restore procedure.
- Do not place database credentials in evidence files.

## Rehearsal Gap

The rollback path is documented and the prior deploy passed smoke checks, but an actual production rollback drill has not been executed. Archive the actual rollback artifact or previous known-good commit before final beta signoff.
