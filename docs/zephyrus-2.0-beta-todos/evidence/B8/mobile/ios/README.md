# iOS Build Evidence

Date: 2026-07-09
Branch: `feat/hummingbird-4d-service-line-eddy`
Commit: `3093c355efdc6d2bbdf3310f30d3ba663105629f` plus uncommitted local changes
Environment: Linux local host

## Result

iOS build validation was not run because this host does not have `swift`, `xcodebuild`, or `xcodegen` available.

## Code Prepared For macOS Validation

- `APIClient.swift` now sends deterministic `Idempotency-Key` headers for `POST /api/mobile/v1/*` requests.
- JSON request body serialization uses sorted keys so identical retries produce identical keys.
- Shared fixture floor-plate version expectation was updated in the iOS decode script.

## Required macOS Commands

```bash
cd hummingbird/iosApp
xcodegen generate
xcodebuild -project Hummingbird.xcodeproj -scheme Hummingbird -configuration Debug build
```

Archive the output under this directory before marking PRD-HB-006 or PRD-GATE-AUTO-004 complete.
