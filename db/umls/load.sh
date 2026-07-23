#!/usr/bin/env bash

set -euo pipefail

meta_dir="${UMLS_META_DIR:?UMLS_META_DIR is required}"
generated_dir="${UMLS_GENERATED_DIR:?UMLS_GENERATED_DIR is required}"
database="${UMLS_DATABASE:-umls_2026aa}"
schema="${UMLS_SCHEMA:-umls}"
psql_bin="${UMLS_PSQL_BIN:-/usr/lib/postgresql/17/bin/psql}"
manifest="$generated_dir/manifest.tsv"
validated_marker="$generated_dir/source-validation.ok"
manifest_digest_file="$generated_dir/manifest.sha256"
logs_dir="$generated_dir/logs"

identifier_pattern='^[a-z][a-z0-9_]*$'
[[ "$database" =~ $identifier_pattern ]] || { echo "unsafe database name: $database" >&2; exit 64; }
[[ "$schema" =~ $identifier_pattern ]] || { echo "unsafe schema name: $schema" >&2; exit 64; }
[[ -x "$psql_bin" ]] || { echo "PostgreSQL 17 psql not found: $psql_bin" >&2; exit 69; }
[[ -f "$manifest" && -f "$manifest_digest_file" && -f "$validated_marker" ]] || { echo "prepare and source-validate must succeed before load" >&2; exit 66; }
cmp -s "$manifest_digest_file" "$validated_marker" || { echo "source-validation marker does not match current manifest" >&2; exit 65; }
mkdir -p "$logs_dir"

loaded=0
skipped=0

while IFS=$'\t' read -r load_order file_name table_name columns expected_columns expected_rows declared_bytes actual_bytes expected_sha description; do
    [[ "$load_order" == "load_order" ]] && continue
    [[ "$table_name" =~ $identifier_pattern ]] || { echo "unsafe table name in manifest: $table_name" >&2; exit 65; }
    path="$meta_dir/$file_name"
    current_sha="$(sha256sum "$path" | awk '{print $1}')"
    [[ "$current_sha" == "$expected_sha" ]] || { echo "source digest changed for $file_name" >&2; exit 65; }

    audit="$($psql_bin -X -d "$database" -v ON_ERROR_STOP=1 -AtF '|' -c "SELECT status,sha256,COALESCE(loaded_rows,-1) FROM $schema._load_file WHERE release_name='2026AA' AND file_name='${file_name//\'/\'\'}'")"
    [[ -n "$audit" ]] || { echo "missing target audit row for $file_name; run schema first" >&2; exit 65; }
    IFS='|' read -r status stored_sha loaded_rows <<< "$audit"
    if [[ "$status" =~ ^(loaded|indexed|validated)$ && "$stored_sha" == "$expected_sha" && "$loaded_rows" == "$expected_rows" ]]; then
        echo "SKIP $file_name status=$status rows=$loaded_rows"
        skipped=$((skipped + 1))
        continue
    fi

    trailer_exists="$($psql_bin -X -d "$database" -Atqc "SELECT 1 FROM information_schema.columns WHERE table_schema='$schema' AND table_name='$table_name' AND column_name='_rrf_trailer'")"
    [[ "$trailer_exists" == "1" ]] || { echo "cannot reload finalized table $schema.$table_name" >&2; exit 65; }

    log_file="$logs_dir/load-${table_name}.log"
    echo "LOAD $file_name -> $schema.$table_name expected_rows=$expected_rows"
    if "$psql_bin" -X -d "$database" -v ON_ERROR_STOP=1 \
        -c "SET ROLE umls_loader; BEGIN; SET LOCAL synchronous_commit=off; UPDATE $schema._load_file SET status='loading',load_started_at=clock_timestamp(),load_completed_at=NULL,loaded_rows=NULL,error_text=NULL WHERE release_name='2026AA' AND file_name='${file_name//\'/\'\'}'; TRUNCATE TABLE $schema.$table_name;" \
        -c "\\copy $schema.$table_name($columns,_rrf_trailer) FROM '$path' WITH (FORMAT csv, DELIMITER '|', QUOTE E'\\x01', ESCAPE E'\\x01', NULL '', ENCODING 'UTF8', FREEZE true)" \
        -c "UPDATE $schema._load_file SET status='loaded',load_completed_at=clock_timestamp(),loaded_rows=$expected_rows,error_text=NULL WHERE release_name='2026AA' AND file_name='${file_name//\'/\'\'}'; COMMIT;" \
        >"$log_file" 2>&1; then
        echo "DONE $file_name rows=$expected_rows"
        loaded=$((loaded + 1))
    else
        "$psql_bin" -X -d "$database" -v ON_ERROR_STOP=1 -c "SET ROLE umls_loader; UPDATE $schema._load_file SET status='failed',load_completed_at=clock_timestamp(),error_text='psql COPY failed; inspect ignored loader log' WHERE release_name='2026AA' AND file_name='${file_name//\'/\'\'}';" >/dev/null
        echo "FAIL $file_name; see $log_file" >&2
        exit 1
    fi
done < "$manifest"

echo "Load pass complete: loaded=$loaded skipped=$skipped"
