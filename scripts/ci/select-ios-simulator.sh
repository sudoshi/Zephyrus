#!/usr/bin/env bash

set -euo pipefail

# Select an available iPhone from the newest installed iOS runtime. This keeps
# CI independent of runner-specific simulator names and UDIDs while remaining
# deterministic for a given runner image.
xcrun simctl list devices available --json | python3 -c '
import json
import re
import sys

payload = json.load(sys.stdin)
candidates = []

for runtime, devices in payload.get("devices", {}).items():
    match = re.search(r"\.iOS-(\d+(?:-\d+)*)$", runtime)
    if match is None:
        continue

    version = tuple(int(component) for component in match.group(1).split("-"))
    for device in devices:
        name = str(device.get("name", ""))
        udid = str(device.get("udid", ""))
        if device.get("isAvailable") is False or not name.startswith("iPhone") or not udid:
            continue
        candidates.append((version, name, udid))

if not candidates:
    raise SystemExit("No available iPhone Simulator was found")

newest_runtime = max(version for version, _, _ in candidates)
runtime_devices = sorted(
    (name, udid) for version, name, udid in candidates if version == newest_runtime
)
print(runtime_devices[0][1])
'
