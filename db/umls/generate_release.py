#!/usr/bin/env python3
"""Generate a PostgreSQL 17 import package from a UMLS RRF release manifest."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable


PRIMARY_KEYS: dict[str, tuple[str, ...]] = {
    "mrconso": ("aui",),
    "mrdef": ("atui",),
    "mrrank": ("sab", "tty"),
    "mrrel": ("rui",),
    "mrsab": ("vsab",),
    "mrsat": ("atui",),
    "mrsty": ("atui",),
}

INDEXES: dict[str, list[tuple[str, str, tuple[str, ...]]]] = {
    "ambiglui": [("btree", "lui", ("lui",))],
    "ambigsui": [("btree", "sui", ("sui",))],
    "deletedcui": [("btree", "pcui", ("pcui",))],
    "mergedcui": [("btree", "pcui", ("pcui",)), ("btree", "cui", ("cui",))],
    "mraui": [("btree", "cui2", ("cui2",)), ("btree", "aui1", ("aui1",))],
    "mrcui": [("btree", "cui1", ("cui1",)), ("btree", "cui2", ("cui2",))],
    "mrdef": [
        ("btree", "cui", ("cui",)),
        ("btree", "aui", ("aui",)),
        ("btree", "sab", ("sab",)),
    ],
    "mrhier": [
        ("btree", "cui", ("cui",)),
        ("btree", "aui", ("aui",)),
        ("btree", "paui", ("paui",)),
        ("btree", "sab", ("sab",)),
        ("btree", "ptr", ("ptr",)),
    ],
    "mrmap": [("btree", "mapsetcui", ("mapsetcui",)), ("btree", "fromid", ("fromid",))],
    "mrrel": [
        ("btree", "cui1", ("cui1",)),
        ("btree", "aui1", ("aui1",)),
        ("btree", "cui2", ("cui2",)),
        ("btree", "aui2", ("aui2",)),
        ("btree", "sab", ("sab",)),
        ("btree", "rela", ("rela",)),
    ],
    "mrsab": [("btree", "rsab", ("rsab",))],
    "mrsat": [
        ("btree", "cui", ("cui",)),
        ("btree", "metaui", ("metaui",)),
        ("btree", "sab", ("sab",)),
        ("btree", "atn", ("atn",)),
        ("btree", "sab_atn", ("sab", "atn")),
    ],
    "mrsmap": [("btree", "mapsetcui", ("mapsetcui",))],
    "mrsty": [("btree", "cui", ("cui",)), ("btree", "sty", ("sty",))],
    "mrxns_eng": [("hash", "nstr_hash", ("nstr",)), ("btree", "cui", ("cui",))],
    "mrxnw_eng": [("btree", "nwd_cui", ("nwd", "cui")), ("btree", "cui", ("cui",))],
    "mrconso": [
        ("btree", "cui", ("cui",)),
        ("btree", "sui", ("sui",)),
        ("btree", "lui", ("lui",)),
        ("btree", "sab_code", ("sab", "code")),
        ("btree", "sab_tty", ("sab", "tty")),
        ("btree", "sab_scui", ("sab", "scui")),
        ("btree", "sab_sdui", ("sab", "sdui")),
        ("hash", "str_hash", ("str",)),
    ],
}

STATISTICS_TARGETS: dict[str, tuple[str, ...]] = {
    "mrconso": ("sab", "lat", "tty", "suppress"),
    "mrhier": ("sab", "rela"),
    "mrrel": ("sab", "rel", "rela"),
    "mrsat": ("sab", "atn", "stype"),
}

EXPECTED_LAT_COUNTS = {
    "ARA": 120907,
    "BAQ": 695,
    "CHI": 95399,
    "CZE": 358455,
    "DAN": 723,
    "DUT": 375241,
    "ENG": 10755691,
    "EST": 149310,
    "FIN": 145483,
    "FRE": 465255,
    "GER": 283966,
    "GRE": 211588,
    "HEB": 485,
    "HUN": 120712,
    "ISL": 119994,
    "ITA": 277441,
    "JPN": 355458,
    "KOR": 158658,
    "LAV": 121399,
    "LIT": 119994,
    "NOR": 183791,
    "POL": 222130,
    "POR": 468869,
    "RUS": 319947,
    "SCR": 130029,
    "SLK": 119994,
    "SLV": 119994,
    "SPA": 2031002,
    "SWE": 169730,
    "TUR": 50013,
    "UKR": 12617,
}


@dataclass(frozen=True)
class Column:
    name: str
    description: str
    minimum: int
    average: str
    maximum: int
    umls_type: str
    postgres_type: str


@dataclass(frozen=True)
class ReleaseFile:
    file_name: str
    table_name: str
    description: str
    columns: tuple[Column, ...]
    expected_rows: int
    declared_bytes: int
    actual_bytes: int
    sha256: str
    load_order: int


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--meta-dir", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--database", default="umls_2026aa")
    parser.add_argument("--schema", default="umls")
    return parser.parse_args()


def validate_identifier(value: str, label: str) -> str:
    if not re.fullmatch(r"[a-z][a-z0-9_]*", value):
        raise ValueError(f"unsafe {label}: {value!r}")
    return value


def split_rrf(line: str, expected_fields: int | None = None) -> list[str]:
    values = line.rstrip("\n").split("|")
    if not values or values[-1] != "":
        raise ValueError("RRF row does not end in a trailing pipe")
    values.pop()
    if expected_fields is not None and len(values) != expected_fields:
        raise ValueError(f"expected {expected_fields} fields, found {len(values)}")
    return values


def read_rrf(path: Path) -> Iterable[list[str]]:
    with path.open("r", encoding="utf-8", newline="") as handle:
        for line_number, line in enumerate(handle, start=1):
            try:
                yield split_rrf(line)
            except ValueError as error:
                raise ValueError(f"{path}:{line_number}: {error}") from error


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while chunk := handle.read(16 * 1024 * 1024):
            digest.update(chunk)
    return digest.hexdigest()


def postgres_type(umls_type: str) -> str:
    normalized = umls_type.strip().lower()
    if normalized == "integer":
        return "bigint"
    if normalized == "numeric(5,2)":
        return "numeric(5,2)"
    match = re.fullmatch(r"(?:var)?char\((\d+)\)", normalized)
    if not match:
        raise ValueError(f"unsupported UMLS type {umls_type!r}")
    size = int(match.group(1))
    return "text" if size >= 1000 else f"varchar({size})"


def sql_literal(value: str | None) -> str:
    if value is None:
        return "NULL"
    return "'" + value.replace("'", "''") + "'"


def load_order(file_name: str) -> int:
    priorities = {
        "MRFILES.RRF": 10,
        "MRCOLS.RRF": 20,
        "MRDOC.RRF": 30,
        "MRSAB.RRF": 40,
        "MRRANK.RRF": 50,
        "MRCONSO.RRF": 100,
        "MRSTY.RRF": 110,
        "MRDEF.RRF": 120,
        "MRREL.RRF": 200,
        "MRHIER.RRF": 210,
        "MRSAT.RRF": 300,
        "MRMAP.RRF": 400,
        "MRSMAP.RRF": 410,
        "MRCUI.RRF": 420,
        "MRAUI.RRF": 430,
        "MRHIST.RRF": 440,
        "MRXNS_ENG.RRF": 500,
        "MRXNW_ENG.RRF": 510,
    }
    if file_name in priorities:
        return priorities[file_name]
    if file_name.startswith("MRXW_"):
        return 600
    if file_name.startswith("AMBIG"):
        return 700
    if file_name.startswith("CHANGE/"):
        return 800
    return 900


def release_metadata(meta_dir: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw_line in (meta_dir / "release.dat").read_text(encoding="utf-8").splitlines():
        if not raw_line or raw_line.startswith("#") or "=" not in raw_line:
            continue
        key, value = raw_line.split("=", 1)
        values[key] = value
    return values


def load_columns(meta_dir: Path) -> dict[tuple[str, str], Column]:
    columns: dict[tuple[str, str], Column] = {}
    for values in read_rrf(meta_dir / "MRCOLS.RRF"):
        if len(values) != 8:
            raise ValueError(f"MRCOLS row has {len(values)} fields instead of 8")
        col, description, _reference, minimum, average, maximum, file_name, data_type = values
        key = (file_name, col)
        if key in columns:
            raise ValueError(f"duplicate MRCOLS definition for {file_name}.{col}")
        columns[key] = Column(
            name=col.lower(),
            description=description,
            minimum=int(minimum),
            average=average,
            maximum=int(maximum),
            umls_type=data_type,
            # MRRANK.RANK is declared as integer but the release intentionally
            # encodes the precedence as four-character values (0947..0001).
            # Preserve that lexical contract instead of discarding leading zeroes.
            postgres_type="varchar(4)"
            if key == ("MRRANK.RRF", "RANK")
            else postgres_type(data_type),
        )
    return columns


def load_files(meta_dir: Path, columns: dict[tuple[str, str], Column]) -> list[ReleaseFile]:
    files: list[ReleaseFile] = []
    for values in read_rrf(meta_dir / "MRFILES.RRF"):
        if len(values) != 6:
            raise ValueError(f"MRFILES row has {len(values)} fields instead of 6")
        file_name, description, fmt, cls, rows, declared_bytes = values
        column_names = fmt.split(",")
        if len(column_names) != int(cls):
            raise ValueError(f"{file_name}: FMT has {len(column_names)} columns, CLS says {cls}")
        file_columns = tuple(columns[(file_name, name)] for name in column_names)
        path = meta_dir / file_name
        if not path.is_file():
            raise FileNotFoundError(path)
        table_name = Path(file_name).stem.lower()
        files.append(
            ReleaseFile(
                file_name=file_name,
                table_name=table_name,
                description=description,
                columns=file_columns,
                expected_rows=int(rows),
                declared_bytes=int(declared_bytes),
                actual_bytes=path.stat().st_size,
                sha256=sha256_file(path),
                load_order=load_order(file_name),
            )
        )
    files.sort(key=lambda item: (item.load_order, item.file_name))
    if len(files) != 56:
        raise ValueError(f"expected 56 RRF files, found {len(files)}")
    return files


def canonical_manifest(metadata: dict[str, str], files: list[ReleaseFile]) -> dict[str, object]:
    return {
        "release": {
            "name": metadata["umls.release.name"],
            "description": metadata["umls.release.description"],
            "date": metadata["umls.release.date"],
            "mmsys_version": metadata["mmsys.version"],
            "nlm_build_date": metadata["nlm.build.date"],
        },
        "files": [
            {
                "file_name": item.file_name,
                "table_name": item.table_name,
                "description": item.description,
                "columns": [
                    {
                        "name": column.name,
                        "minimum": column.minimum,
                        "average": column.average,
                        "maximum": column.maximum,
                        "umls_type": column.umls_type,
                        "postgres_type": column.postgres_type,
                    }
                    for column in item.columns
                ],
                "expected_rows": item.expected_rows,
                "declared_bytes": item.declared_bytes,
                "actual_bytes": item.actual_bytes,
                "sha256": item.sha256,
                "load_order": item.load_order,
            }
            for item in files
        ],
    }


def manifest_digest(manifest: dict[str, object]) -> str:
    encoded = json.dumps(manifest, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode()
    return hashlib.sha256(encoded).hexdigest()


def write_manifest(output_dir: Path, manifest: dict[str, object], digest: str) -> None:
    output_dir.mkdir(parents=True, exist_ok=True)
    (output_dir / "manifest.json").write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    (output_dir / "manifest.sha256").write_text(digest + "\n", encoding="ascii")
    lines = [
        "load_order\tfile_name\ttable_name\tcolumns\texpected_columns\texpected_rows\tdeclared_bytes\tactual_bytes\tsha256\tdescription"
    ]
    for item in manifest["files"]:  # type: ignore[index]
        columns = ",".join(column["name"] for column in item["columns"])
        description = str(item["description"]).replace("\t", " ").replace("\n", " ")
        lines.append(
            "\t".join(
                str(value)
                for value in (
                    item["load_order"],
                    item["file_name"],
                    item["table_name"],
                    columns,
                    len(item["columns"]),
                    item["expected_rows"],
                    item["declared_bytes"],
                    item["actual_bytes"],
                    item["sha256"],
                    description,
                )
            )
        )
    (output_dir / "manifest.tsv").write_text("\n".join(lines) + "\n", encoding="utf-8")


def schema_sql(
    metadata: dict[str, str], files: list[ReleaseFile], digest: str, database: str, schema: str, source_root: Path
) -> str:
    release_name = metadata["umls.release.name"]
    release_date = datetime.strptime(metadata["umls.release.date"], "%Y%m%d").date().isoformat()
    total_rows = sum(item.expected_rows for item in files)
    declared_bytes = sum(item.declared_bytes for item in files)
    actual_bytes = sum(item.actual_bytes for item in files)
    output = [
        "\\set ON_ERROR_STOP on",
        "SET ROLE umls_owner;",
        f"CREATE SCHEMA IF NOT EXISTS {schema} AUTHORIZATION umls_owner;",
        f"REVOKE ALL ON SCHEMA {schema} FROM PUBLIC;",
        f"GRANT USAGE ON SCHEMA {schema} TO umls_loader, umls_reader;",
        f"ALTER DEFAULT PRIVILEGES FOR ROLE umls_owner IN SCHEMA {schema} REVOKE ALL ON TABLES FROM PUBLIC;",
        f"ALTER DEFAULT PRIVILEGES FOR ROLE umls_owner IN SCHEMA {schema} GRANT SELECT ON TABLES TO umls_reader;",
        "",
        f"CREATE TABLE IF NOT EXISTS {schema}._release (",
        "    release_name text PRIMARY KEY,",
        "    release_description text NOT NULL,",
        "    release_date date NOT NULL,",
        "    mmsys_version text NOT NULL,",
        "    nlm_build_date text NOT NULL,",
        "    manifest_sha256 varchar(64) NOT NULL CHECK (manifest_sha256 ~ '^[0-9a-f]{64}$'),",
        "    expected_file_count integer NOT NULL,",
        "    expected_row_count bigint NOT NULL,",
        "    declared_bytes bigint NOT NULL,",
        "    actual_bytes bigint NOT NULL,",
        "    source_root text NOT NULL,",
        "    schema_generated_at timestamptz NOT NULL DEFAULT clock_timestamp(),",
        "    source_validated_at timestamptz,",
        "    loaded_at timestamptz,",
        "    indexed_at timestamptz,",
        "    validated_at timestamptz",
        ");",
        f"CREATE TABLE IF NOT EXISTS {schema}._load_file (",
        f"    release_name text NOT NULL REFERENCES {schema}._release(release_name),",
        "    file_name text NOT NULL,",
        "    table_name name NOT NULL,",
        "    description text NOT NULL,",
        "    column_list text[] NOT NULL,",
        "    expected_columns integer NOT NULL,",
        "    expected_rows bigint NOT NULL,",
        "    declared_bytes bigint NOT NULL,",
        "    actual_bytes bigint NOT NULL,",
        "    sha256 varchar(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),",
        "    load_order integer NOT NULL,",
        "    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','loading','loaded','indexed','validated','failed')),",
        "    load_started_at timestamptz,",
        "    load_completed_at timestamptz,",
        "    loaded_rows bigint,",
        "    validation_rows bigint,",
        "    error_text text,",
        "    PRIMARY KEY (release_name, file_name),",
        "    UNIQUE (release_name, table_name)",
        ");",
        f"REVOKE ALL ON {schema}._release, {schema}._load_file FROM PUBLIC;",
        f"GRANT SELECT ON {schema}._release, {schema}._load_file TO umls_reader;",
        f"GRANT SELECT ON {schema}._release TO umls_loader;",
        f"GRANT SELECT, UPDATE ON {schema}._load_file TO umls_loader;",
        "",
        "DO $umls$",
        "BEGIN",
        f"    IF EXISTS (SELECT 1 FROM {schema}._release WHERE release_name={sql_literal(release_name)} AND manifest_sha256 <> {sql_literal(digest)}) THEN",
        "        RAISE EXCEPTION 'existing release manifest digest does not match generated 2026AA manifest';",
        "    END IF;",
        "END",
        "$umls$;",
        f"INSERT INTO {schema}._release (release_name,release_description,release_date,mmsys_version,nlm_build_date,manifest_sha256,expected_file_count,expected_row_count,declared_bytes,actual_bytes,source_root)",
        f"VALUES ({sql_literal(release_name)},{sql_literal(metadata['umls.release.description'])},{sql_literal(release_date)}::date,{sql_literal(metadata['mmsys.version'])},{sql_literal(metadata['nlm.build.date'])},{sql_literal(digest)},56,{total_rows},{declared_bytes},{actual_bytes},{sql_literal(str(source_root))})",
        "ON CONFLICT (release_name) DO UPDATE SET source_root=EXCLUDED.source_root, schema_generated_at=clock_timestamp()",
        f"WHERE {schema}._release.manifest_sha256=EXCLUDED.manifest_sha256;",
        "",
    ]
    for item in files:
        output.append(f"CREATE TABLE IF NOT EXISTS {schema}.{item.table_name} (")
        definitions = [f"    {column.name} {column.postgres_type}" for column in item.columns]
        definitions.append("    _rrf_trailer text CHECK (_rrf_trailer IS NULL)")
        output.append(",\n".join(definitions))
        output.append(");")
        output.append(
            f"COMMENT ON TABLE {schema}.{item.table_name} IS {sql_literal('UMLS 2026AA ' + item.file_name + ': ' + item.description)};"
        )
        output.append(f"REVOKE ALL ON {schema}.{item.table_name} FROM PUBLIC;")
        output.append(f"GRANT SELECT, INSERT, TRUNCATE ON {schema}.{item.table_name} TO umls_loader;")
        output.append(f"GRANT SELECT ON {schema}.{item.table_name} TO umls_reader;")
        array_value = "ARRAY[" + ",".join(sql_literal(column.name) for column in item.columns) + "]::text[]"
        output.extend(
            [
                "DO $umls$",
                "BEGIN",
                f"    IF EXISTS (SELECT 1 FROM {schema}._load_file WHERE release_name={sql_literal(release_name)} AND file_name={sql_literal(item.file_name)} AND sha256 <> {sql_literal(item.sha256)}) THEN",
                f"        RAISE EXCEPTION 'existing audit digest differs for {item.file_name}';",
                "    END IF;",
                "END",
                "$umls$;",
                f"INSERT INTO {schema}._load_file (release_name,file_name,table_name,description,column_list,expected_columns,expected_rows,declared_bytes,actual_bytes,sha256,load_order)",
                f"VALUES ({sql_literal(release_name)},{sql_literal(item.file_name)},{sql_literal(item.table_name)}::name,{sql_literal(item.description)},{array_value},{len(item.columns)},{item.expected_rows},{item.declared_bytes},{item.actual_bytes},{sql_literal(item.sha256)},{item.load_order})",
                "ON CONFLICT (release_name,file_name) DO UPDATE SET description=EXCLUDED.description, load_order=EXCLUDED.load_order",
                f"WHERE {schema}._load_file.sha256=EXCLUDED.sha256;",
                "",
            ]
        )
    output.extend(
        [
            f"DO $umls$ BEGIN IF (SELECT count(*) FROM {schema}._load_file WHERE release_name={sql_literal(release_name)}) <> 56 THEN RAISE EXCEPTION 'release audit must contain 56 files'; END IF; END $umls$;",
            "RESET ROLE;",
            "",
        ]
    )
    return "\n".join(output)


def finalize_sql(files: list[ReleaseFile], digest: str, schema: str) -> str:
    output = [
        "\\set ON_ERROR_STOP on",
        "SET ROLE umls_owner;",
        "DO $umls$",
        "BEGIN",
        f"    IF NOT EXISTS (SELECT 1 FROM {schema}._release WHERE release_name='2026AA' AND manifest_sha256={sql_literal(digest)}) THEN",
        "        RAISE EXCEPTION 'target release manifest is absent or differs';",
        "    END IF;",
        f"    IF (SELECT count(*) FROM {schema}._load_file WHERE release_name='2026AA' AND status IN ('loaded','indexed','validated') AND loaded_rows=expected_rows) <> 56 THEN",
        "        RAISE EXCEPTION 'all 56 release files must be loaded with their expected row counts before finalization';",
        "    END IF;",
        "END",
        "$umls$;",
        "",
    ]
    for item in files:
        clauses = ["DROP COLUMN IF EXISTS _rrf_trailer"]
        clauses.extend(f"ALTER COLUMN {column.name} SET NOT NULL" for column in item.columns if column.minimum > 0)
        output.append(f"ALTER TABLE {schema}.{item.table_name}\n    " + ",\n    ".join(clauses) + ";")
    output.extend(
        [
            f"UPDATE {schema}._release SET loaded_at=COALESCE(loaded_at,clock_timestamp()) WHERE release_name='2026AA';",
            "RESET ROLE;",
            "",
        ]
    )
    return "\n".join(output)


def constraint_block(schema: str, table: str, columns: tuple[str, ...]) -> list[str]:
    constraint = f"pk_{table}"
    column_sql = ",".join(columns)
    return [
        "DO $umls$",
        "BEGIN",
        f"    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='{schema}.{table}'::regclass AND conname={sql_literal(constraint)}) THEN",
        f"        ALTER TABLE {schema}.{table} ADD CONSTRAINT {constraint} PRIMARY KEY ({column_sql});",
        "    END IF;",
        "END",
        "$umls$;",
    ]


def indexes_sql(files: list[ReleaseFile], schema: str) -> str:
    output = [
        "\\set ON_ERROR_STOP on",
        "SET ROLE umls_owner;",
        "SET maintenance_work_mem='2GB';",
        "SET max_parallel_maintenance_workers=4;",
        "SET synchronous_commit=off;",
        "",
    ]
    for item in files:
        table = item.table_name
        if table in PRIMARY_KEYS:
            output.extend(constraint_block(schema, table, PRIMARY_KEYS[table]))
        table_indexes = list(INDEXES.get(table, []))
        if table.startswith("mrxw_"):
            table_indexes.append(("btree", "wd_cui", ("wd", "cui")))
        for method, suffix, columns in table_indexes:
            name = f"ix_{table}_{suffix}"
            column_sql = ",".join(columns)
            using = " USING hash" if method == "hash" else ""
            output.append(f"CREATE INDEX IF NOT EXISTS {name} ON {schema}.{table}{using} ({column_sql});")
        for column in STATISTICS_TARGETS.get(table, ()):
            output.append(f"ALTER TABLE {schema}.{table} ALTER COLUMN {column} SET STATISTICS 1000;")
        output.append(f"ANALYZE {schema}.{table};")
        output.append(
            f"UPDATE {schema}._load_file SET status='indexed' WHERE release_name='2026AA' AND table_name={sql_literal(table)}::name AND status IN ('loaded','indexed');"
        )
        output.append("")
    output.extend(
        [
            f"UPDATE {schema}._release SET indexed_at=clock_timestamp() WHERE release_name='2026AA';",
            "RESET ROLE;",
            "",
        ]
    )
    return "\n".join(output)


def validation_sql(schema: str, database: str, digest: str) -> str:
    lat_json = json.dumps(EXPECTED_LAT_COUNTS, sort_keys=True, separators=(",", ":"))
    return f"""\\set ON_ERROR_STOP on
SET ROLE umls_owner;

DO $umls$
DECLARE
    item record;
    actual bigint;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM {schema}._release WHERE release_name='2026AA' AND manifest_sha256={sql_literal(digest)}) THEN
        RAISE EXCEPTION 'target release manifest is absent or differs';
    END IF;
    FOR item IN SELECT file_name,table_name,expected_rows FROM {schema}._load_file WHERE release_name='2026AA' ORDER BY load_order,file_name LOOP
        EXECUTE format('SELECT count(*) FROM %I.%I', {sql_literal(schema)}, item.table_name) INTO actual;
        UPDATE {schema}._load_file
           SET validation_rows=actual,
               status=CASE WHEN actual=expected_rows THEN 'validated' ELSE 'failed' END,
               error_text=CASE WHEN actual=expected_rows THEN NULL ELSE format('validation count %s differs from expected %s',actual,expected_rows) END
         WHERE release_name='2026AA' AND file_name=item.file_name;
    END LOOP;
END
$umls$;

DO $umls$
DECLARE value bigint;
BEGIN
    IF (SELECT count(*) FROM {schema}._load_file WHERE release_name='2026AA') <> 56 THEN RAISE EXCEPTION 'expected 56 load-audit rows'; END IF;
    IF EXISTS (SELECT 1 FROM {schema}._load_file WHERE release_name='2026AA' AND validation_rows IS DISTINCT FROM expected_rows) THEN RAISE EXCEPTION 'one or more table row counts differ from MRFILES'; END IF;
    SELECT sum(validation_rows) INTO value FROM {schema}._load_file WHERE release_name='2026AA';
    IF value <> 392875930 THEN RAISE EXCEPTION 'total row count %, expected 392875930',value; END IF;
    IF (SELECT count(*) FROM pg_tables WHERE schemaname={sql_literal(schema)} AND tablename NOT LIKE '\\_%') <> 56 THEN RAISE EXCEPTION 'expected 56 UMLS data tables'; END IF;
    IF to_regclass({sql_literal(schema + '.mrcxt')}) IS NOT NULL THEN RAISE EXCEPTION 'unmanifested MRCXT exists'; END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema={sql_literal(schema)} AND column_name='_rrf_trailer') THEN RAISE EXCEPTION 'one or more RRF trailer columns remain'; END IF;
    IF (SELECT count(*) FROM {schema}.mrconso) <> 18064970 THEN RAISE EXCEPTION 'MRCONSO count mismatch'; END IF;
    IF (SELECT count(DISTINCT cui) FROM {schema}.mrconso) <> 3530466 THEN RAISE EXCEPTION 'MRCONSO distinct CUI mismatch'; END IF;
    IF (SELECT count(*) FROM {schema}.mrsat) <> 105826727 THEN RAISE EXCEPTION 'MRSAT count mismatch'; END IF;
    IF (SELECT count(*) FROM {schema}.mrrel) <> 66241184 THEN RAISE EXCEPTION 'MRREL count mismatch'; END IF;
    IF (SELECT count(*) FROM {schema}.mrhier) <> 44803899 THEN RAISE EXCEPTION 'MRHIER count mismatch'; END IF;
    IF (SELECT max(length(atv)) FROM {schema}.mrsat) <> 35985 THEN RAISE EXCEPTION 'MRSAT.ATV truncation/max mismatch'; END IF;
    IF (SELECT max(length(def)) FROM {schema}.mrdef) <> 10992 THEN RAISE EXCEPTION 'MRDEF.DEF truncation/max mismatch'; END IF;
    IF (SELECT max(length(str)) FROM {schema}.mrconso) <> 2864 THEN RAISE EXCEPTION 'MRCONSO.STR truncation/max mismatch'; END IF;
    IF (SELECT max(length(nstr)) FROM {schema}.mrxns_eng) <> 2528 THEN RAISE EXCEPTION 'MRXNS_ENG.NSTR truncation/max mismatch'; END IF;
    IF (SELECT count(*) FROM {schema}.mrsab) <> 201 THEN RAISE EXCEPTION 'MRSAB count mismatch'; END IF;
    IF (SELECT count(*) FROM {schema}.mrsab WHERE curver='Y') <> 197 THEN RAISE EXCEPTION 'MRSAB current-source count mismatch'; END IF;
    IF EXISTS (SELECT 1 FROM {schema}.mrsab WHERE cenc <> 'UTF-8') THEN RAISE EXCEPTION 'MRSAB contains a non-UTF-8 source encoding'; END IF;
    IF (SELECT count(*) FROM {schema}.mrrank) <> 947 OR (SELECT min(rank) FROM {schema}.mrrank) <> '0001' OR (SELECT max(rank) FROM {schema}.mrrank) <> '0947' THEN RAISE EXCEPTION 'MRRANK lexical range/count mismatch'; END IF;
    IF (SELECT count(*) FROM {schema}.mrhist) <> 0 OR (SELECT count(*) FROM {schema}.deletedlui) <> 0 OR (SELECT count(*) FROM {schema}.deletedsui) <> 0 OR (SELECT count(*) FROM {schema}.mergedlui) <> 0 THEN RAISE EXCEPTION 'one or more expected-empty tables are nonempty'; END IF;
    IF (SELECT jsonb_object_agg(suppress,n ORDER BY suppress) FROM (SELECT suppress,count(*) n FROM {schema}.mrconso GROUP BY suppress) s) <> '{{"E":9491,"N":15551711,"O":2154677,"Y":349091}}'::jsonb THEN RAISE EXCEPTION 'MRCONSO SUPPRESS distribution mismatch'; END IF;
    IF (SELECT jsonb_object_agg(ts,n ORDER BY ts) FROM (SELECT ts,count(*) n FROM {schema}.mrconso GROUP BY ts) s) <> '{{"P":9555099,"S":8509871}}'::jsonb THEN RAISE EXCEPTION 'MRCONSO TS distribution mismatch'; END IF;
    IF (SELECT jsonb_object_agg(lat,n ORDER BY lat) FROM (SELECT lat,count(*) n FROM {schema}.mrconso GROUP BY lat) s) <> {sql_literal(lat_json)}::jsonb THEN RAISE EXCEPTION 'MRCONSO LAT distribution mismatch'; END IF;
    IF NOT EXISTS (SELECT 1 FROM {schema}.mrconso WHERE cui='C0001175') THEN RAISE EXCEPTION 'C0001175 concept-name smoke failed'; END IF;
    IF NOT EXISTS (SELECT 1 FROM {schema}.mrsty WHERE cui='C0001175' AND tui='T047') THEN RAISE EXCEPTION 'C0001175 semantic-type smoke failed'; END IF;
    IF NOT EXISTS (SELECT 1 FROM {schema}.mrdef WHERE cui='C0001175') THEN RAISE EXCEPTION 'C0001175 definition smoke failed'; END IF;
    IF NOT EXISTS (SELECT 1 FROM {schema}.mrrel WHERE cui1='C0001175' OR cui2='C0001175') THEN RAISE EXCEPTION 'C0001175 relationship smoke failed'; END IF;
END
$umls$;

DO $umls$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_database d, LATERAL aclexplode(COALESCE(d.datacl,acldefault('d',d.datdba))) a
        WHERE d.datname={sql_literal(database)} AND a.grantee=0 AND a.privilege_type='CONNECT'
    ) THEN RAISE EXCEPTION 'PUBLIC retains CONNECT on {database}'; END IF;
    IF EXISTS (
        SELECT 1 FROM pg_namespace n, LATERAL aclexplode(COALESCE(n.nspacl,acldefault('n',n.nspowner))) a
        WHERE n.nspname={sql_literal(schema)} AND a.grantee=0
    ) THEN RAISE EXCEPTION 'PUBLIC retains privileges on schema {schema}'; END IF;
    IF NOT has_schema_privilege('umls_reader',{sql_literal(schema)},'USAGE') OR NOT has_table_privilege('umls_reader',{sql_literal(schema + '.mrconso')},'SELECT') THEN RAISE EXCEPTION 'reader grants are incomplete'; END IF;
    IF has_table_privilege('umls_reader',{sql_literal(schema + '.mrconso')},'INSERT,UPDATE,DELETE,TRUNCATE') THEN RAISE EXCEPTION 'reader has write privileges'; END IF;
    IF NOT has_table_privilege('umls_loader',{sql_literal(schema + '.mrconso')},'SELECT,INSERT,TRUNCATE') THEN RAISE EXCEPTION 'loader data grants are incomplete'; END IF;
    IF has_table_privilege('umls_loader',{sql_literal(schema + '.mrconso')},'UPDATE,DELETE') THEN RAISE EXCEPTION 'loader has row mutation privileges beyond bulk load'; END IF;
END
$umls$;

UPDATE {schema}._release SET validated_at=clock_timestamp() WHERE release_name='2026AA';

SELECT file_name,table_name,expected_rows,validation_rows,status
FROM {schema}._load_file
WHERE release_name='2026AA'
ORDER BY load_order,file_name;

SELECT c.relname AS table_name,
       pg_size_pretty(pg_table_size(c.oid)) AS heap,
       pg_size_pretty(pg_indexes_size(c.oid)) AS indexes,
       pg_size_pretty(pg_total_relation_size(c.oid)) AS total
FROM pg_class c
JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname={sql_literal(schema)} AND c.relkind='r'
ORDER BY pg_total_relation_size(c.oid) DESC;

RESET ROLE;
"""


def lengths_sql(files: list[ReleaseFile], schema: str) -> str:
    output = [
        "\\set ON_ERROR_STOP on",
        "SET ROLE umls_owner;",
        "CREATE TEMP TABLE _umls_length_validation (",
        "    file_name text NOT NULL,",
        "    column_name text NOT NULL,",
        "    actual_min bigint NOT NULL,",
        "    actual_average numeric(20,2) NOT NULL,",
        "    actual_max bigint NOT NULL,",
        "    PRIMARY KEY (file_name,column_name)",
        ");",
        "",
    ]
    for item in files:
        aggregate_columns = ["count(*) AS row_count"]
        for column in item.columns:
            aggregate_columns.extend(
                [
                    f"count({column.name}) AS {column.name}_count",
                    f"min(length({column.name}::text)) AS {column.name}_min",
                    f"round(avg(COALESCE(length({column.name}::text),0))::numeric,2) AS {column.name}_average",
                    f"max(length({column.name}::text)) AS {column.name}_max",
                ]
            )
        output.append("WITH metrics AS MATERIALIZED (")
        output.append("    SELECT\n        " + ",\n        ".join(aggregate_columns))
        output.append(f"    FROM {schema}.{item.table_name}\n)")
        output.append("INSERT INTO _umls_length_validation (file_name,column_name,actual_min,actual_average,actual_max)")
        rows = []
        for column in item.columns:
            rows.append(
                "SELECT "
                + ",".join(
                    [
                        sql_literal(item.file_name),
                        sql_literal(column.name.upper()),
                        f"CASE WHEN row_count=0 OR {column.name}_count<row_count THEN 0 ELSE COALESCE({column.name}_min,0) END",
                        f"COALESCE({column.name}_average,0)",
                        f"COALESCE({column.name}_max,0)",
                    ]
                )
                + " FROM metrics"
            )
        output.append("\nUNION ALL\n".join(rows) + ";")
        output.append("")
    output.extend(
        [
            "SELECT e.fil,e.col,e.min AS expected_min,a.actual_min,e.av AS expected_average,a.actual_average,e.max AS expected_max,a.actual_max",
            f"FROM {schema}.mrcols e",
            "JOIN _umls_length_validation a ON a.file_name=e.fil AND a.column_name=e.col",
            "WHERE e.min<>a.actual_min OR e.av<>a.actual_average OR e.max<>a.actual_max",
            "ORDER BY e.fil,e.col;",
            "",
            "DO $umls$",
            "BEGIN",
            f"    IF (SELECT count(*) FROM {schema}.mrcols) <> (SELECT count(*) FROM _umls_length_validation) THEN RAISE EXCEPTION 'length-control row count differs from MRCOLS'; END IF;",
            f"    IF EXISTS (SELECT 1 FROM {schema}.mrcols e JOIN _umls_length_validation a ON a.file_name=e.fil AND a.column_name=e.col WHERE e.min<>a.actual_min OR e.av<>a.actual_average OR e.max<>a.actual_max) THEN RAISE EXCEPTION 'one or more imported length controls differ from MRCOLS'; END IF;",
            "END",
            "$umls$;",
            "RESET ROLE;",
            "",
        ]
    )
    return "\n".join(output)


def write_sql_files(
    output_dir: Path,
    metadata: dict[str, str],
    files: list[ReleaseFile],
    digest: str,
    database: str,
    schema: str,
    source_root: Path,
) -> None:
    files_to_content = {
        "01-schema.sql": schema_sql(metadata, files, digest, database, schema, source_root),
        "02-finalize.sql": finalize_sql(files, digest, schema),
        "03-indexes.sql": indexes_sql(files, schema),
        "04-validate.sql": validation_sql(schema, database, digest),
        "05-lengths.sql": lengths_sql(files, schema),
    }
    for name, content in files_to_content.items():
        (output_dir / name).write_text(content, encoding="utf-8")


def main() -> None:
    args = parse_args()
    meta_dir = args.meta_dir.resolve()
    output_dir = args.output_dir.resolve()
    database = validate_identifier(args.database, "database")
    schema = validate_identifier(args.schema, "schema")
    for required in ("MRFILES.RRF", "MRCOLS.RRF", "release.dat", "config.prop"):
        if not (meta_dir / required).is_file():
            raise FileNotFoundError(meta_dir / required)
    metadata = release_metadata(meta_dir)
    if metadata.get("umls.release.name") != "2026AA":
        raise ValueError(f"expected release 2026AA, found {metadata.get('umls.release.name')!r}")
    columns = load_columns(meta_dir)
    files = load_files(meta_dir, columns)
    manifest = canonical_manifest(metadata, files)
    digest = manifest_digest(manifest)
    write_manifest(output_dir, manifest, digest)
    write_sql_files(output_dir, metadata, files, digest, database, schema, meta_dir)
    summary = {
        "release": metadata["umls.release.name"],
        "files": len(files),
        "rows": sum(item.expected_rows for item in files),
        "declared_bytes": sum(item.declared_bytes for item in files),
        "actual_bytes": sum(item.actual_bytes for item in files),
        "manifest_sha256": digest,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "output_dir": str(output_dir),
    }
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    main()
