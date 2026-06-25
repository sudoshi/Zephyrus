# Manual Deployment Only

Zephyrus production deployment is intentionally manual.

Run the canonical deploy script from the development checkout:

```bash
cd /home/smudoshi/Github/Zephyrus
./deploy.sh
```

Do not deploy by GitHub Actions, ad hoc SSH command blocks, direct production
`git pull`, or alternate deploy scripts. The manual script is the only supported
application release path.

`./deploy.sh` verifies the local tree, builds production assets, syncs the
application to `/var/www/Zephyrus`, clears Laravel caches, restarts Apache, and
checks the Zephyrus vhost.
