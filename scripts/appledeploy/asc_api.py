#!/usr/bin/env python3
"""
asc_api.py — minimal App Store Connect API client for the TestFlight pathway.

Stdlib only (no pip installs): the ES256 JWT is signed by shelling out to
`openssl`, and the DER signature is converted to the raw r||s form the JWS spec
requires. Credentials come from `.appledeploy` (see .appledeploy.example).

Used by ./testflight.sh for the things `xcrun altool` cannot do: reading a
build's processing state, listing TestFlight builds, and handing a finished
build to a beta tester group.

Commands
  apps                              list every app record on the team
  builds   <app-id> [--limit N]     recent TestFlight builds for an app
  status   <app-id> <build-number>  processing state of one build
  wait     <app-id> <build-number>  poll until processing finishes
  groups   <app-id>                 beta tester groups
  distribute <app-id> <build-number> <group-name>
                                    add a processed build to a tester group
"""

from __future__ import annotations

import argparse
import base64
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ASC_BASE = "https://api.appstoreconnect.apple.com/v1"
REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


# ── credentials ────────────────────────────────────────────────────────────────


def load_config(path: str | None = None) -> dict[str, str]:
    """Parse the shell-style .appledeploy file into a dict."""
    path = path or os.environ.get("APPLEDEPLOY_FILE") or os.path.join(REPO_ROOT, ".appledeploy")
    if not os.path.exists(path):
        die(f"no credentials file at {path} — copy .appledeploy.example to .appledeploy")

    cfg: dict[str, str] = {}
    with open(path, encoding="utf-8") as fh:
        for raw in fh:
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            line = re.sub(r"^export\s+", "", line)
            if "=" not in line:
                continue
            key, _, val = line.partition("=")
            val = val.strip().strip('"').strip("'")
            # Expand $HOME / ${HOME} and friends so the key path stays portable.
            cfg[key.strip()] = os.path.expandvars(os.path.expanduser(val))
    return cfg


def credentials(cfg: dict[str, str]) -> tuple[str, str, str]:
    key_id = os.environ.get("ASC_KEY_ID") or cfg.get("ASC_KEY_ID", "")
    issuer_id = os.environ.get("ASC_ISSUER_ID") or cfg.get("ASC_ISSUER_ID", "")
    key_path = os.environ.get("ASC_KEY_PATH") or cfg.get("ASC_KEY_PATH", "")

    missing = [n for n, v in (("ASC_KEY_ID", key_id), ("ASC_ISSUER_ID", issuer_id), ("ASC_KEY_PATH", key_path)) if not v]
    if missing:
        die("missing in .appledeploy: " + ", ".join(missing))
    if not os.path.exists(key_path):
        die(f"ASC_KEY_PATH does not exist: {key_path}")
    return key_id, issuer_id, key_path


# ── ES256 JWT, signed via openssl ──────────────────────────────────────────────


def _b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode("ascii")


def _der_to_raw(der: bytes) -> bytes:
    """ECDSA DER SEQUENCE{INTEGER r, INTEGER s} -> the 64-byte r||s JWS wants."""

    def read_int(buf: bytes, pos: int) -> tuple[int, int]:
        if buf[pos] != 0x02:
            raise ValueError("malformed DER signature: expected INTEGER")
        length = buf[pos + 1]
        pos += 2
        if length & 0x80:  # long-form length
            n = length & 0x7F
            length = int.from_bytes(buf[pos : pos + n], "big")
            pos += n
        return int.from_bytes(buf[pos : pos + length], "big"), pos + length

    if not der or der[0] != 0x30:
        raise ValueError("malformed DER signature: expected SEQUENCE")
    pos = 2
    if der[1] & 0x80:
        pos = 2 + (der[1] & 0x7F)
    r, pos = read_int(der, pos)
    s, _ = read_int(der, pos)
    return r.to_bytes(32, "big") + s.to_bytes(32, "big")


def make_token(key_id: str, issuer_id: str, key_path: str, ttl: int = 900) -> str:
    header = {"alg": "ES256", "kid": key_id, "typ": "JWT"}
    now = int(time.time())
    payload = {"iss": issuer_id, "iat": now, "exp": now + ttl, "aud": "appstoreconnect-v1"}
    signing_input = f"{_b64url(json.dumps(header).encode())}.{_b64url(json.dumps(payload).encode())}"

    proc = subprocess.run(
        ["openssl", "dgst", "-sha256", "-sign", key_path],
        input=signing_input.encode("ascii"),
        capture_output=True,
    )
    if proc.returncode != 0:
        die(f"openssl could not sign with {key_path}: {proc.stderr.decode().strip()}")
    return f"{signing_input}.{_b64url(_der_to_raw(proc.stdout))}"


# ── HTTP ───────────────────────────────────────────────────────────────────────


class ASC:
    def __init__(self, cfg: dict[str, str]) -> None:
        self.token = make_token(*credentials(cfg))

    def request(self, method: str, path: str, params: dict | None = None, body: dict | None = None) -> dict:
        url = path if path.startswith("http") else f"{ASC_BASE}{path}"
        if params:
            url += "?" + urllib.parse.urlencode(params)
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(url, data=data, method=method)
        req.add_header("Authorization", f"Bearer {self.token}")
        if data:
            req.add_header("Content-Type", "application/json")
        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                raw = resp.read()
                return json.loads(raw) if raw else {}
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode(errors="replace")
            try:
                errors = json.loads(detail).get("errors", [])
                detail = "; ".join(f"{e.get('title')}: {e.get('detail')}" for e in errors) or detail
            except json.JSONDecodeError:
                pass
            die(f"App Store Connect {exc.code} on {method} {url}\n  {detail}")
        except urllib.error.URLError as exc:
            die(f"could not reach App Store Connect: {exc.reason}")

    def get(self, path: str, **params) -> dict:
        return self.request("GET", path, params=params or None)

    def post(self, path: str, body: dict) -> dict:
        return self.request("POST", path, body=body)


# ── operations ─────────────────────────────────────────────────────────────────


def find_build(asc: ASC, app_id: str, build_number: str) -> dict | None:
    """Look up one build of an app by its CFBundleVersion."""
    resp = asc.get(
        "/builds",
        **{
            "filter[app]": app_id,
            "filter[version]": build_number,
            "limit": "1",
            "fields[builds]": "version,processingState,uploadedDate,expired,minOsVersion",
        },
    )
    data = resp.get("data") or []
    return data[0] if data else None


def cmd_apps(asc: ASC, args) -> int:
    resp = asc.get("/apps", **{"limit": "200", "fields[apps]": "name,bundleId,sku"})
    rows = [(a["id"], a["attributes"]["name"], a["attributes"]["bundleId"]) for a in resp.get("data", [])]
    if args.json:
        print(json.dumps([{"id": i, "name": n, "bundleId": b} for i, n, b in rows], indent=2))
        return 0
    width = max((len(n) for _, n, _ in rows), default=4)
    for app_id, name, bundle in sorted(rows, key=lambda r: r[1]):
        print(f"{app_id}  {name:<{width}}  {bundle}")
    return 0


def cmd_builds(asc: ASC, args) -> int:
    resp = asc.get(
        "/builds",
        **{
            "filter[app]": args.app_id,
            "limit": str(args.limit),
            "sort": "-uploadedDate",
            "fields[builds]": "version,processingState,uploadedDate,expired",
        },
    )
    builds = resp.get("data", [])
    if args.json:
        print(json.dumps([{"id": b["id"], **b["attributes"]} for b in builds], indent=2))
        return 0
    if not builds:
        print("no builds uploaded yet")
        return 0
    print(f"{'BUILD':<16} {'STATE':<12} {'UPLOADED':<22} EXPIRED")
    for b in builds:
        a = b["attributes"]
        print(f"{a.get('version',''):<16} {a.get('processingState',''):<12} {str(a.get('uploadedDate','')):<22} {a.get('expired')}")
    return 0


def cmd_status(asc: ASC, args) -> int:
    build = find_build(asc, args.app_id, args.build_number)
    if not build:
        print(f"build {args.build_number} not visible yet (App Store Connect can lag a few minutes after upload)")
        return 2
    a = build["attributes"]
    if args.json:
        print(json.dumps({"id": build["id"], **a}, indent=2))
        return 0
    print(f"build {a.get('version')}: {a.get('processingState')}  (uploaded {a.get('uploadedDate')})")
    return 0 if a.get("processingState") == "VALID" else 1


def cmd_confirm(asc: ASC, args) -> int:
    """Poll until the build is VISIBLE at all, in any processing state.

    This is the ground truth that an upload landed. `xcrun altool` has been
    observed exiting 0 after logging a fatal ERROR (App Store Connect 500s),
    so its exit code alone cannot be trusted to mean the binary arrived.
    """
    deadline = time.time() + args.timeout
    while True:
        build = find_build(asc, args.app_id, args.build_number)
        if build:
            print(f"confirmed: build {args.build_number} is in App Store Connect ({build['attributes'].get('processingState')})")
            return 0
        if time.time() >= deadline:
            print(
                f"build {args.build_number} never appeared in App Store Connect after {args.timeout}s — the upload did not land",
                file=sys.stderr,
            )
            return 1
        time.sleep(args.interval)


def cmd_wait(asc: ASC, args) -> int:
    """Poll until the build leaves PROCESSING. Apple typically takes 5-20 min."""
    deadline = time.time() + args.timeout
    last = None
    while time.time() < deadline:
        build = find_build(asc, args.app_id, args.build_number)
        state = build["attributes"].get("processingState") if build else "NOT_VISIBLE"
        if state != last:
            print(f"  {time.strftime('%H:%M:%S')}  {state}", flush=True)
            last = state
        if state == "VALID":
            print(f"build {args.build_number} is ready for testers")
            return 0
        if state in ("INVALID", "FAILED"):
            print(f"build {args.build_number} failed processing ({state}) — check App Store Connect for the reason", file=sys.stderr)
            return 1
        time.sleep(args.interval)
    print(f"timed out after {args.timeout}s; last state {last}", file=sys.stderr)
    return 1


def cmd_groups(asc: ASC, args) -> int:
    resp = asc.get("/betaGroups", **{"filter[app]": args.app_id, "limit": "200", "fields[betaGroups]": "name,isInternalGroup"})
    groups = resp.get("data", [])
    if args.json:
        print(json.dumps([{"id": g["id"], **g["attributes"]} for g in groups], indent=2))
        return 0
    if not groups:
        print("no beta groups — create one in App Store Connect → TestFlight")
        return 0
    for g in groups:
        kind = "internal" if g["attributes"].get("isInternalGroup") else "external"
        print(f"{g['id']}  {g['attributes'].get('name')}  ({kind})")
    return 0


def cmd_distribute(asc: ASC, args) -> int:
    build = find_build(asc, args.app_id, args.build_number)
    if not build:
        die(f"build {args.build_number} not found for app {args.app_id}")
    state = build["attributes"].get("processingState")
    if state != "VALID":
        die(f"build {args.build_number} is {state}, not VALID — wait for processing before distributing")

    resp = asc.get("/betaGroups", **{"filter[app]": args.app_id, "limit": "200", "fields[betaGroups]": "name"})
    match = next((g for g in resp.get("data", []) if g["attributes"].get("name") == args.group), None)
    if not match:
        names = ", ".join(g["attributes"].get("name", "?") for g in resp.get("data", [])) or "(none)"
        die(f"no beta group named {args.group!r}; existing groups: {names}")

    asc.post(f"/betaGroups/{match['id']}/relationships/builds", {"data": [{"type": "builds", "id": build["id"]}]})
    print(f"build {args.build_number} handed to tester group {args.group!r}")
    return 0


# ── entry point ────────────────────────────────────────────────────────────────


def die(msg: str) -> "NoReturn":  # type: ignore[valid-type]
    print(f"error: {msg}", file=sys.stderr)
    raise SystemExit(1)


def main() -> int:
    parser = argparse.ArgumentParser(prog="asc_api.py", description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--config", help="path to .appledeploy (default: repo root)")
    parser.add_argument("--json", action="store_true", help="emit raw JSON")
    sub = parser.add_subparsers(dest="cmd", required=True)

    sub.add_parser("apps", help="list all app records on the team")

    p = sub.add_parser("builds", help="recent TestFlight builds")
    p.add_argument("app_id")
    p.add_argument("--limit", type=int, default=10)

    p = sub.add_parser("status", help="processing state of one build")
    p.add_argument("app_id")
    p.add_argument("build_number")

    p = sub.add_parser("confirm", help="poll until the build is visible at all (upload landed)")
    p.add_argument("app_id")
    p.add_argument("build_number")
    p.add_argument("--interval", type=int, default=20)
    p.add_argument("--timeout", type=int, default=600)

    p = sub.add_parser("wait", help="poll until processing finishes")
    p.add_argument("app_id")
    p.add_argument("build_number")
    p.add_argument("--interval", type=int, default=30)
    p.add_argument("--timeout", type=int, default=2400)

    p = sub.add_parser("groups", help="beta tester groups")
    p.add_argument("app_id")

    p = sub.add_parser("distribute", help="add a processed build to a tester group")
    p.add_argument("app_id")
    p.add_argument("build_number")
    p.add_argument("group")

    args = parser.parse_args()
    asc = ASC(load_config(args.config))
    return {
        "apps": cmd_apps,
        "builds": cmd_builds,
        "status": cmd_status,
        "confirm": cmd_confirm,
        "wait": cmd_wait,
        "groups": cmd_groups,
        "distribute": cmd_distribute,
    }[args.cmd](asc, args)


if __name__ == "__main__":
    sys.exit(main())
