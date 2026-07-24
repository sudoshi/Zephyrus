# iOS Build Evidence

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Commit: post-review hardening working tree; final commit recorded by git history
Environment: Linux local host

## Result

iOS build validation was not run because this host does not have `swift`, `xcodebuild`, or `xcodegen` available.

## Code Prepared For macOS Validation

- `APIClient.swift` now sends deterministic `Idempotency-Key` headers for `POST /api/mobile/v1/*` requests.
- JSON request body serialization uses sorted keys so identical retries produce identical keys.
- `APIClient.swift` exposes mobile Patient Flow demo-scenario and occupancy-history calls for parity with the BFF.
- Query values now use RFC3986-style percent encoding so ISO timestamps and other reserved characters are not corrupted in URLs.
- Shared fixture floor-plate version expectation was updated in the iOS decode script.

## Required macOS Commands

```bash
cd hummingbird/iosApp
xcodegen generate
xcodebuild -project Hummingbird.xcodeproj -scheme Hummingbird -configuration Debug build
```

Archive the output under this directory before marking PRD-HB-006 or PRD-GATE-AUTO-004 complete.
