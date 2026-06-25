# Manual Production Deployment Checklist

Zephyrus has one supported application deployment mechanism:

```bash
cd /home/smudoshi/Github/Zephyrus
./deploy.sh
```

## Pre-Deploy

- [ ] Confirm the release commit is already pushed to `origin/main`.
- [ ] Confirm the local worktree is clean with `git status --short`.
- [ ] Run any release-specific tests or migrations checks needed for the change.
- [ ] Confirm schema-bearing releases have a migration plan; `./deploy.sh` syncs
      code and assets but does not replace explicit migration review.

## Deploy

- [ ] Run `./deploy.sh` from `/home/smudoshi/Github/Zephyrus`.
- [ ] Confirm the script completes its asset build, rsync, cache-clear, Apache
      restart, and vhost smoke check.

## Post-Deploy

- [ ] Verify `https://zephyrus.acumenus.net` or the affected route returns a
      successful response.
- [ ] Check Laravel and Apache logs if the deploy script reports any failure.

Do not use GitHub Actions, ad hoc SSH command blocks, direct production
`git pull`, or alternate deploy scripts as application deployment mechanisms.
