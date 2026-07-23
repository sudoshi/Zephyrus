# Development and Production Release Runbook

## Scope

This is the supported workflow for developing on the macOS checkout, publishing
through GitHub, and manually releasing to `zephyrus.acumenus.net`.

The two deliberate release stages are:

1. Publish a feature branch and merge it through a CI-successful pull request.
2. Run the canonical `./deploy.sh` command from a clean, synchronized local
   `main` branch.

GitHub Actions validates releases but never deploys them. Operators must not use
ad hoc SSH deployment commands, direct production `git pull`, or another deploy
script.

## Safety boundaries

- `main` is protected. Changes require a pull request, strict successful CI,
  linear history, and resolved review conversations. Force-pushes and branch
  deletion are disabled.
- The local `.env` intentionally reads the production PostgreSQL database.
  Laravel forces every matching non-production PostgreSQL connection into
  transaction read-only mode as it is established.
- Verify the local database guard whenever environment configuration changes:

    ```bash
    php artisan zephyrus:database-safety
    ```

    Both `Session default` and `Transaction` must be `on`, and `Safe` must be
    `yes`.

- The Laravel guard does not govern arbitrary external database clients. Do not
  run `psql`, `pg_dump`, data loaders, seeders, migrations, or standalone
  database scripts from the Mac against production. Production migrations use
  only the guarded path-scoped release command below.
- Production application releases and database migrations are separate
  operations. A normal deployment never migrates.
- The production checkout is
  `/home/smudoshi/Github/Zephyrus`, accessed as
  `smudoshi@zephyrus.acumenus.net`. The script uses the full target rather than
  an SSH alias.

## Start or resume development

First synchronize the protected base branch:

```bash
git switch main
git status --short --branch
git fetch origin
git pull --ff-only
git rev-list --left-right --count HEAD...origin/main
```

The final command must return `0 0`. Do not discard unrelated local work to
make synchronization convenient.

Create a branch:

```bash
git switch -c feature/<short-description>
```

Develop and run the checks appropriate to the change. Database-mutating local
test suites must use the isolated test database, never the production
connection from `.env`.

## Commit and publish

Stage only the intended paths:

```bash
git status --short --branch
git add <explicit-path> [<explicit-path> ...]
git diff --cached --name-status
git diff --cached --stat
git diff --cached --check
git commit -m "<concise change description>"
git push -u origin HEAD
gh pr create --fill
```

Never use `git add -A` in a mixed worktree. Review the pull request, wait for
every required GitHub check to pass, resolve conversations, and merge it.

## Synchronize the merged release

Return the Mac to the merged `main` commit:

```bash
git switch main
git status --short --branch
git fetch origin
git pull --ff-only
git rev-list --left-right --count HEAD...origin/main
```

The worktree must be clean and ahead/behind must be `0 0`.

## Read-only release preflight

```bash
./deploy.sh --check
```

This verifies, without changing the deployed application:

- clean local `main` tracking `origin/main`;
- exact local/remote commit identity;
- a successful exact-commit `main` push run of `.github/workflows/ci.yml`;
- key-based SSH access to `smudoshi@zephyrus.acumenus.net`;
- the canonical production checkout, branch, upstream, cleanliness, and remote
  commit;
- non-interactive `sudo` availability.

If any check fails, stop and fix that specific condition. The preflight does not
stash, reset, force-update, or deploy.

## Deploy the application

Run:

```bash
./deploy.sh
```

The script asks for `DEPLOY <12-character-commit>`. It then:

1. acquires the production release lock;
2. fetches `origin/main` in the canonical production checkout;
3. fast-forwards only to the exact locally approved commit;
4. re-verifies exact-commit GitHub CI;
5. builds an immutable Git snapshot;
6. publishes it to `/var/www/Zephyrus`;
7. preserves the production `.env` and runtime storage;
8. clears Laravel caches, refreshes the queue worker and sidecar where present,
   and restarts Apache;
9. verifies the release marker, vhost, production assets, edge policy, services,
   and storage permissions.

`./deploy.sh --frontend` remains a compatibility label for older runbooks. It
still deploys the full immutable application snapshot and never runs database
migrations.

For a non-interactive operator session, confirmation can be supplied only as
the exact full commit:

```bash
./deploy.sh --confirm "$(git rev-parse HEAD)"
```

Do not put that form into unattended automation.

## Path-scoped production migration

First deploy the application commit normally. Review every migration and its
rollback/forward-fix plan. Then name only the approved migration file:

```bash
./deploy.sh --migrate \
  --path database/migrations/YYYY_MM_DD_HHMMSS_example.php
```

Repeat `--path` for a reviewed multi-file release. `--db` is an alias for
`--migrate` but still requires explicit `--path` values.

The script asks for `MIGRATE <12-character-commit>` and then:

1. proves the deployed `.release-commit` equals the approved `main` commit;
2. rejects paths outside the tracked `database/migrations/*.php` files;
3. previews only the named migrations with `--pretend`;
4. creates a full directory-format logical backup under
   `/var/backups/zephyrus/`;
5. validates the backup with `pg_restore --list`;
6. saves the pre-migration Laravel migration ledger beside the backup;
7. runs only the named paths and prints final migration status.

The legacy `DEPLOY_RUN_MIGRATIONS=1` blanket path is rejected. Never use
`migrate:rollback` casually in production; prefer a reviewed forward repair or
an Ops-approved restore from the verified backup.

## Post-deploy verification

The deploy command performs automated checks. Also inspect the affected
workflow manually:

```bash
curl --fail --silent --show-error --output /dev/null \
  https://zephyrus.acumenus.net/login
ssh smudoshi@zephyrus.acumenus.net \
  'sudo cat /var/www/Zephyrus/.release-commit'
git rev-parse HEAD
```

The two commits must match. If a check fails, retain the complete deploy output
and inspect:

```bash
ssh smudoshi@zephyrus.acumenus.net
sudo systemctl status apache2 zephyrus-queue-worker.service
sudo journalctl -u apache2.service -n 50
sudo journalctl -u zephyrus-queue-worker.service -n 50
```

## Rollback and recovery

Application rollback is a new reviewed Git release:

1. Revert the offending pull request on a branch.
2. Run CI and merge the revert through the protected `main` branch.
3. Synchronize local `main`.
4. Run `./deploy.sh --check`, then `./deploy.sh`.

Do not detach or force-reset production to an unreviewed old commit.

For a migration incident:

1. Disable the affected feature through its approved kill switch where
   available.
2. Stop additional writes to the affected workflow.
3. Preserve logs, the release SHA, backup path, migration ledger, and failure
   output.
4. Prefer a reviewed forward repair.
5. Restore the verified logical backup only through the Ops-approved recovery
   procedure and only after accounting for post-backup production writes.

## Common failures

- **Local or production tree is dirty:** inspect it; do not auto-stash or reset.
- **Local `main` differs from `origin/main`:** use `git pull --ff-only`; resolve
  divergence deliberately.
- **CI missing, pending, cancelled, or failed:** wait or repair CI; deployment
  fails closed.
- **Production `origin/main` changed after local approval:** resynchronize local
  `main` and repeat preflight.
- **Production checkout cannot fast-forward:** stop and investigate; never force.
- **Database safety command reports writable:** stop local Laravel processes and
  correct the connection guard before continuing development.
- **Migration backup or verification fails:** no migration runs; fix backup
  readiness first.
