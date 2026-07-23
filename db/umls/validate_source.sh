#!/usr/bin/env bash

set -euo pipefail

meta_dir="${UMLS_META_DIR:?UMLS_META_DIR is required}"
generated_dir="${UMLS_GENERATED_DIR:?UMLS_GENERATED_DIR is required}"
manifest="$generated_dir/manifest.tsv"
manifest_digest_file="$generated_dir/manifest.sha256"

[[ -f "$manifest" ]] || { echo "generated manifest is missing: $manifest" >&2; exit 66; }
[[ -f "$manifest_digest_file" ]] || { echo "generated manifest digest is missing: $manifest_digest_file" >&2; exit 66; }

failures=0
validated=0

while IFS=$'\t' read -r load_order file_name table_name columns expected_columns expected_rows declared_bytes actual_bytes expected_sha description; do
    [[ "$load_order" == "load_order" ]] && continue
    path="$meta_dir/$file_name"
    if [[ ! -f "$path" ]]; then
        echo "FAIL $file_name: missing file" >&2
        failures=$((failures + 1))
        continue
    fi
    stat_bytes="$(stat -c '%s' "$path")"
    if [[ "$stat_bytes" != "$actual_bytes" ]]; then
        echo "FAIL $file_name: actual bytes $stat_bytes differ from manifest $actual_bytes" >&2
        failures=$((failures + 1))
        continue
    fi
    result="$(iconv -f UTF-8 -t UTF-8 "$path" | LC_ALL=C awk -F'|' -v expected="$((expected_columns + 1))" '
        BEGIN { min=1000000; max=0; bad=0; trailer_bad=0; ctrl_a=0 }
        { n++; if (NF<min) min=NF; if (NF>max) max=NF; if (NF!=expected) bad++; if ($NF!="") trailer_bad++; if (index($0,sprintf("%c",1))) ctrl_a++ }
        END { if (n==0) { min=0; max=0 } printf "%d|%d|%d|%d|%d|%d",n,min,max,bad,trailer_bad,ctrl_a }
    ')"
    IFS='|' read -r rows min_nf max_nf bad_nf bad_trailer ctrl_a <<< "$result"
    if [[ "$rows" != "$expected_rows" || "$bad_nf" != "0" || "$bad_trailer" != "0" || "$ctrl_a" != "0" ]]; then
        echo "FAIL $file_name rows=$rows expected_rows=$expected_rows min_nf=$min_nf max_nf=$max_nf bad_nf=$bad_nf bad_trailer=$bad_trailer ctrl_a=$ctrl_a" >&2
        failures=$((failures + 1))
        continue
    fi
    if [[ "$declared_bytes" != "$actual_bytes" && "$file_name" != "MRDOC.RRF" ]]; then
        echo "FAIL $file_name: undeclared byte discrepancy declared=$declared_bytes actual=$actual_bytes" >&2
        failures=$((failures + 1))
        continue
    fi
    echo "PASS $file_name rows=$rows columns=$expected_columns bytes=$actual_bytes utf8=yes trailer=yes ctrl_a=absent"
    validated=$((validated + 1))
done < "$manifest"

if [[ "$failures" != "0" || "$validated" != "56" ]]; then
    echo "Source validation failed: validated=$validated failures=$failures" >&2
    exit 1
fi

cp "$manifest_digest_file" "$generated_dir/source-validation.ok"
echo "Source validation complete: 56 files passed. Manifest $(tr -d '\n' < "$manifest_digest_file")"
