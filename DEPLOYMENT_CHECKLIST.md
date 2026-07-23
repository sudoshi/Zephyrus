# Manual Production Deployment Checklist

Zephyrus has one supported application deployment mechanism:

```bash
cd /Users/sudoshi/Github/Zephyrus
./deploy.sh --check
./deploy.sh
```

## Pre-Deploy

- [ ] Confirm the release was merged through a protected pull request.
- [ ] Synchronize local `main` with `git pull --ff-only`.
- [ ] Confirm `git rev-list --left-right --count HEAD...origin/main` is `0 0`.
- [ ] Confirm the local worktree is clean with `git status --short --branch`.
- [ ] Run `php artisan zephyrus:database-safety`; local production access must
      report read-only and safe.
- [ ] Run any release-specific tests or migrations checks needed for the change.
- [ ] Run `./deploy.sh --check`.

## Deploy

- [ ] Run `./deploy.sh` from the clean local `main` checkout.
- [ ] Type the requested exact-commit deployment confirmation.
- [ ] Confirm the script completes its asset build, rsync, cache-clear, Apache
      restart, and vhost smoke check.

## Schema-Bearing Release

- [ ] Deploy the application commit first.
- [ ] Review exact migration paths, production-volume behavior, and forward-fix
      or restore strategy.
- [ ] Run `./deploy.sh --migrate --path database/migrations/<file.php>`.
- [ ] Confirm the script previews only those paths.
- [ ] Retain the verified `/var/backups/zephyrus/pre-migrate-*.dir` path and
      adjacent migration ledger.
- [ ] Confirm final migration status.

## Post-Deploy

- [ ] Verify `https://zephyrus.acumenus.net` or the affected route returns a
      successful response.
- [ ] Confirm local `git rev-parse HEAD` matches the production
      `/var/www/Zephyrus/.release-commit`.
- [ ] Check Laravel and Apache logs if the deploy script reports any failure.

Do not use GitHub Actions, ad hoc SSH command blocks, direct production
`git pull`, or alternate deploy scripts as application deployment mechanisms.

See
[Development and Production Release Runbook](docs/operations/DEVELOPMENT-AND-PRODUCTION-RELEASE-RUNBOOK.md)
for the complete workflow and recovery procedure.
