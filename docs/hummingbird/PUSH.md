# Hummingbird — Push Notifications

The push path end-to-end:

```
iOS app  ──register──▶  POST /api/mobile/v1/devices  ──▶  prod.mobile_devices
                                                            │
clinical/ops event ──▶ PushNotifier::sendToUser(...) ───────┘──▶ APNs ──▶ device
```

## What's wired

- **iOS** (`PushManager`): requests permission, registers for remote notifications, sends the
  APNs token to the BFF, shows foreground banners, and routes a tapped notification to a tab
  (`tab` in the payload → For You by default). Enable from **Profile → Notifications**.
- **Device registry**: `POST /api/mobile/v1/devices` upserts into `prod.mobile_devices`
  (created 2026-06-28). `DELETE /devices/{uuid}` revokes.
- **Sender seam** (`PushNotifier`): bound to **`ApnsPushNotifier`** (real, token-`.p8`, HTTP/2)
  when APNs is configured, else the log-only `LogPushNotifier`. Payloads are PHI-free
  (generic title/body + ids/deep-links).
- **Manual trigger**: `php artisan hummingbird:test-push <username> [--title=] [--body=] [--tab=]`
  fans a test push to a user's active devices via whichever sender is bound.

## To go live (provide the APNs key)

Create an APNs **Auth Key (.p8)** in the Apple Developer portal (Keys → enable Apple Push
Notifications service), note its **Key ID** and your **Team ID**, then set in `.env`:

```dotenv
APNS_KEY_ID=ABCDE12345
APNS_TEAM_ID=TKXPY255A2
APNS_BUNDLE_ID=net.acumenus.hummingbird
# inline PEM contents of the .p8 …
APNS_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n…\n-----END PRIVATE KEY-----"
# …or a path instead:
# APNS_PRIVATE_KEY_PATH=/var/www/Zephyrus/storage/app/apns/AuthKey_ABCDE12345.p8
APNS_PRODUCTION=false   # false = sandbox (dev/simulator builds); true = TestFlight/App Store
```

Then `php artisan config:cache` and restart. The container auto-binds `ApnsPushNotifier`.
Sandbox (`false`) matches the app's `aps-environment: development` (Debug); set `true` only for
the production-entitlement build.

## Verify

- **Simulator (no APNs needed):** `xcrun simctl push <device> net.acumenus.hummingbird payload.apns`
  where the file contains `{"Simulator Target Bundle":"net.acumenus.hummingbird","aps":{"alert":{"title":"…","body":"…"}},"tab":"foryou"}`
  (the app must have been granted notification permission).
- **Real device / TestFlight:** enable notifications in‑app, then `php artisan hummingbird:test-push <username>`.

## Still to wire (product)

Automatic, **role-targeted** triggers (e.g. push the charge nurse when a placement to their unit
needs action, or a supervisor on a new escalation) — deferred until the Zephyrus role/assignment
backend lands so recipients can be resolved without spraying every device. Hook them by calling
`PushNotifier::sendToUser($recipient, …, ['tab' => 'foryou'])` from the relevant model observer /
domain event.
