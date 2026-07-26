#!/usr/bin/env python3
"""Write a phpunit config whose single testsuite lists exact files (plan D3).

paratest accepts one directory path, never a file list, so the two-pass
shard runner materializes each pass (non-scenario / scenario / anchor
override) as its own config derived from the repo phpunit.xml — every
<php> env var, the timing extension, and the bootstrap ride along.

Usage: make-paratest-suite.py <output.xml> < newline-separated-file-list
"""

import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]


def main(output: str) -> None:
    files = [line.strip() for line in sys.stdin if line.strip()]
    if not files:
        raise SystemExit("make-paratest-suite.py: empty file list")

    # Every path in a phpunit config resolves relative to the CONFIG file,
    # and these configs live in a temp dir — absolutize everything.
    entries = "\n".join(
        f"            <file>{(REPO_ROOT / f).resolve()}</file>" for f in files
    )
    block = (
        "    <testsuites>\n"
        '        <testsuite name="Pass">\n'
        f"{entries}\n"
        "        </testsuite>\n"
        "    </testsuites>"
    )

    base = (REPO_ROOT / "phpunit.xml").read_text()
    base = base.replace(
        'bootstrap="tests/bootstrap.php"',
        f'bootstrap="{REPO_ROOT / "tests/bootstrap.php"}"',
    )
    base = base.replace(
        "<directory>app</directory>",
        f"<directory>{REPO_ROOT / 'app'}</directory>",
    )
    patched, count = re.subn(
        r"    <testsuites>.*?</testsuites>", block, base, count=1, flags=re.S
    )
    if count != 1:
        raise SystemExit("make-paratest-suite.py: could not locate <testsuites> block")

    Path(output).write_text(patched)


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    main(sys.argv[1])
