#!/usr/bin/env bash

set -euo pipefail

database="${UMLS_DATABASE:-umls_2026aa}"
operator_role="${UMLS_OPERATOR_ROLE:-$(id -un)}"
psql_bin="${UMLS_PSQL_BIN:-/usr/lib/postgresql/17/bin/psql}"
createdb_bin="${UMLS_CREATEDB_BIN:-/usr/lib/postgresql/17/bin/createdb}"

identifier_pattern='^[a-z][a-z0-9_]*$'
[[ "$database" =~ $identifier_pattern ]] || { echo "unsafe database name: $database" >&2; exit 64; }
[[ "$operator_role" =~ $identifier_pattern ]] || { echo "unsafe operator role: $operator_role" >&2; exit 64; }
[[ -x "$psql_bin" ]] || { echo "PostgreSQL 17 psql not found: $psql_bin" >&2; exit 69; }
[[ -x "$createdb_bin" ]] || { echo "PostgreSQL 17 createdb not found: $createdb_bin" >&2; exit 69; }

server_version="$(sudo -u postgres "$psql_bin" -X -d postgres -Atqc "SHOW server_version_num")"
[[ "$server_version" == 17* ]] || { echo "expected PostgreSQL 17 server, found $server_version" >&2; exit 69; }

sudo -u postgres "$psql_bin" -X -v ON_ERROR_STOP=1 -d postgres <<SQL
DO \$bootstrap\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='umls_owner') THEN CREATE ROLE umls_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='umls_loader') THEN CREATE ROLE umls_loader NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='umls_reader') THEN CREATE ROLE umls_reader NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION; END IF;
END
\$bootstrap\$;
GRANT umls_loader, umls_reader TO ${operator_role};
SQL

exists="$(sudo -u postgres "$psql_bin" -X -d postgres -Atqc "SELECT 1 FROM pg_database WHERE datname='$database'")"
if [[ "$exists" != "1" ]]; then
    sudo -u postgres "$createdb_bin" \
        --owner=umls_owner \
        --template=template0 \
        --encoding=UTF8 \
        --lc-collate=C.utf8 \
        --lc-ctype=C.utf8 \
        "$database"
fi

sudo -u postgres "$psql_bin" -X -v ON_ERROR_STOP=1 -d postgres <<SQL
REVOKE ALL ON DATABASE ${database} FROM PUBLIC;
GRANT CONNECT ON DATABASE ${database} TO umls_owner, umls_loader, umls_reader;
ALTER DATABASE ${database} OWNER TO umls_owner;
SQL

sudo -u postgres "$psql_bin" -X -v ON_ERROR_STOP=1 -d "$database" <<SQL
REVOKE ALL ON SCHEMA public FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM umls_loader, umls_reader;
SQL

echo "Bootstrapped PostgreSQL database $database with UMLS owner/loader/reader roles."
