#!/usr/bin/env python3
"""Resolve feature-test shard membership from tests/ci/shard-manifest.json.

Reads the discovered test-file list (one path per line) on stdin — produced
by run-backend-test-shard.sh's discovery pipeline — and either prints the
files belonging to one shard (--shard N) or verifies the full assignment
(--verify): every discovered file lands on exactly one shard, no duplicates,
no silent exclusions.

Rules (plan S1, §3.2.9):
- Listed files run on their manifest shard. The manifest is generated from
  measured medians (scripts/ci/generate-shard-manifest.py), never hand-tuned.
- Manifest entries whose file no longer exists are DRIFT and fail hard:
  deleting or renaming a test file requires regenerating the manifest so
  exclusions stay explicit.
- Discovered files missing from the manifest (new tests) are slotted at
  runtime: sorted lexicographically, LPT-packed onto the manifest's recorded
  shard loads using default_weight_s, so every runner computes the same
  deterministic assignment without a manifest edit.
"""

import argparse
import json
import sys
from pathlib import Path


def load(manifest_path: str, discovered: list[str]):
    manifest = json.loads(Path(manifest_path).read_text())
    shard_count = int(manifest["shard_count"])
    assignments = {f: int(spec["shard"]) for f, spec in manifest["assignments"].items()}

    discovered_set = set(discovered)
    stale = sorted(set(assignments) - discovered_set)
    if stale:
        for f in stale:
            print(f"MANIFEST DRIFT: {f} is in tests/ci/shard-manifest.json but not on disk", file=sys.stderr)
        print("Regenerate the manifest: python3 scripts/ci/generate-shard-manifest.py <run-evidence-dirs>", file=sys.stderr)
        raise SystemExit(4)

    loads = {int(i): float(s) for i, s in manifest["shard_load_s"].items()}
    default_weight = float(manifest["default_weight_s"])
    resolved = dict(assignments)
    for f in sorted(discovered_set - set(assignments)):
        shard = min(range(shard_count), key=lambda i: (loads[i], i))
        resolved[f] = shard
        loads[shard] += default_weight

    return shard_count, resolved


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--manifest", required=True)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--shard", type=int)
    group.add_argument("--verify", action="store_true")
    args = parser.parse_args()

    discovered = [line.strip() for line in sys.stdin if line.strip()]
    if len(set(discovered)) != len(discovered):
        raise SystemExit("Discovery produced duplicate paths")

    shard_count, resolved = load(args.manifest, discovered)

    if args.verify:
        missing = sorted(set(discovered) - set(resolved))
        if missing:
            for f in missing:
                print(f"UNASSIGNED: {f}", file=sys.stderr)
            raise SystemExit(5)
        counts = [sum(1 for s in resolved.values() if s == i) for i in range(shard_count)]
        empty = [i for i, c in enumerate(counts) if c == 0]
        if empty:
            raise SystemExit(f"Shards with no tests: {empty}")
        print(f"OK: {len(discovered)} feature test files cover shards 0-{shard_count - 1} exactly once {counts}")
        return

    if not 0 <= args.shard < shard_count:
        raise SystemExit(f"Shard index {args.shard} out of range 0-{shard_count - 1}")

    # Plan S2: classes using UsesCommittedAncillaryScenario leave a committed
    # demo baseline behind, and the tests/TestCase.php boundary guard pays a
    # full schema wipe + re-migration when a non-scenario class follows one.
    # Emitting scenario classes LAST inside the shard means that guard never
    # fires in CI. Detection is content-based so newly converted classes
    # order correctly without touching this script.
    def uses_committed_scenario(path: str) -> bool:
        try:
            return "UsesCommittedAncillaryScenario" in Path(path).read_text()
        except OSError:
            return False

    shard_files = (f for f, s in resolved.items() if s == args.shard)
    for f in sorted(shard_files, key=lambda f: (uses_committed_scenario(f), f)):
        print(f)


if __name__ == "__main__":
    main()
