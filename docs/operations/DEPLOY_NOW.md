# Manual Deployment Only

Zephyrus production deployment is intentionally manual.

Run the canonical deploy script from the clean, synchronized `main` branch on
the development Mac:

```bash
cd /Users/sudoshi/Github/Zephyrus
./deploy.sh --check
./deploy.sh
```

Do not deploy by GitHub Actions, ad hoc SSH command blocks, direct production
`git pull`, or alternate deploy scripts. The manual script is the only supported
application release path.

`./deploy.sh` verifies the local tree and exact-commit GitHub CI, connects to
`smudoshi@zephyrus.acumenus.net`, fast-forwards the canonical checkout, builds
an immutable release, syncs it to `/var/www/Zephyrus`, clears Laravel caches,
restarts services, and checks the Zephyrus vhost.

Migrations are a separate, backed-up, path-scoped operation:

```bash
./deploy.sh --migrate \
  --path database/migrations/YYYY_MM_DD_HHMMSS_example.php
```

See [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md).
