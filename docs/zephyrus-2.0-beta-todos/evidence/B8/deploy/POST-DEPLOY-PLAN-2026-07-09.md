# Post-Deploy Smoke Plan - 2026-07-09

Branch: `feat/hummingbird-4d-service-line-eddy`
Deploy command: `./deploy.sh`
Production path: `/var/www/Zephyrus`

## Required Commands

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

## Expected Results

- Apache is active.
- Zephyrus vhost returns 2xx or 3xx locally.
- Public HTTPS endpoint returns an HTTP response header without TLS/certificate failure.
- No unexpected pending migrations for this slice.
- Patient Flow route list includes `demo-scenarios` and `occupancy/history`.
- Queue failed jobs are empty or known.
- Queue default is documented.
- Scheduler list renders without fatal errors.

## Artifact

The completed deployment and smoke evidence is archived in `DEPLOYMENT-RESULT-2026-07-09.md`.
