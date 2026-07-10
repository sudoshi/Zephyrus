# Mobile Route / OpenAPI Contract Inventory

**Generated:** 2026-07-02
**Scope:** `/api/mobile/v1/*` Laravel routes compared with
`docs/hummingbird/api-contract/hummingbird-bff.v1.yaml`.

## Verification Commands

```bash
php artisan route:list --json --path=api/mobile/v1
php -r 'require "vendor/autoload.php"; Symfony\Component\Yaml\Yaml::parseFile("docs/hummingbird/api-contract/hummingbird-bff.v1.yaml");'
php artisan test --filter=MobileBffTest
npx @redocly/cli lint docs/hummingbird/api-contract/hummingbird-bff.v1.yaml
```

## Current Route Inventory

| Method | Path                                         |
| ------ | -------------------------------------------- |
| DELETE | `/devices/{device}`                          |
| GET    | `/activity`                                  |
| GET    | `/altitude/home`                             |
| GET    | `/altitude/workspace/{domain}`               |
| GET    | `/command/house`                             |
| GET    | `/drills/{itemUuid}`                         |
| GET    | `/eddy/approvals`                            |
| GET    | `/eddy/approvals/{uuid}`                     |
| GET    | `/eddy/context/{scopeRef}`                   |
| GET    | `/eddy/conversations`                        |
| GET    | `/eddy/conversations/{uuid}`                 |
| GET    | `/evs/queue`                                 |
| GET    | `/flow/floors`                               |
| GET    | `/flow/window`                               |
| GET    | `/for-you`                                   |
| GET    | `/improvement/opportunities`                 |
| GET    | `/improvement/pdsa`                          |
| GET    | `/me`                                        |
| GET    | `/ops/inbox`                                 |
| GET    | `/or/board`                                  |
| GET    | `/patients/{contextRef}/operational-context` |
| GET    | `/realtime/config`                           |
| GET    | `/rtdc/bed-requests`                         |
| GET    | `/rtdc/bed-requests/{id}/recommendations`    |
| GET    | `/rtdc/census`                               |
| GET    | `/rtdc/house`                                |
| GET    | `/staffing/overview`                         |
| GET    | `/staffing/requests/{id}/candidates`         |
| GET    | `/transport/queue`                           |
| POST   | `/activity/{eventUuid}/ack`                  |
| POST   | `/devices`                                   |
| POST   | `/eddy/approvals/{uuid}/decision`            |
| POST   | `/eddy/chat`                                 |
| POST   | `/eddy/chat/stream`                          |
| POST   | `/evs/requests/{id}/status`                  |
| POST   | `/ops/approvals/{uuid}/decision`             |
| POST   | `/rtdc/barriers/{id}/resolve`                |
| POST   | `/rtdc/bed-requests/{id}/decision`           |
| POST   | `/staffing/requests/{id}/fill`               |
| POST   | `/transport/requests/{id}/handoff`           |
| POST   | `/transport/requests/{id}/status`            |
| PUT    | `/me/preferences`                            |

## Resolved Laravel / OpenAPI Mismatches

| Classification                                    | Resolution                                                                                                                                                                                                                                                                                                  |
| ------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Implemented in Laravel, missing from OpenAPI      | Added `/devices/{device}`, `/rtdc/bed-requests/{id}/recommendations`, `/staffing/overview`, `/staffing/requests/{id}/fill`, `/improvement/pdsa`, and `/improvement/opportunities`.                                                                                                                          |
| In OpenAPI, missing from Laravel                  | Removed planned-but-unimplemented mobile paths `/home`, `/rtdc/barriers`, `/ops/actions/{id}/{transition}`, `/or/cases/{id}/status`, and `/command/brief`.                                                                                                                                                  |
| Implemented but named differently                 | Renamed `/ops/approvals/{id}/decision` to `/ops/approvals/{uuid}/decision` to match Laravel.                                                                                                                                                                                                                |
| Implemented but payload shape differs from schema | Replaced generic `Envelope` responses with named schemas for house rollup, placement recommendations, house brief, OR board, ops approvals, transport queue, EVS queue, staffing overview/fill, improvement PDSA/opportunities, Altitude home/workspace/drill, patient context, activity, and Eddy context. |
| Client method exists without contract coverage    | iOS methods for staffing, improvement, placement recommendations, and domain queues now have contract coverage.                                                                                                                                                                                             |
| Contract path exists without any client method    | Remaining known gaps are `/realtime/config`, `/devices/{device}`, and the Eddy chat/conversation/approval endpoints. iOS lacks direct client methods for these except token revoke; Android lacks most role/domain endpoint methods because it is still on the generic Altitude client surface.             |

## Guardrail

`Tests\Feature\MobileBffTest::test_laravel_mobile_routes_match_the_openapi_contract_inventory`
now fails when a Laravel `/api/mobile/v1/*` method/path exists without OpenAPI coverage
or when OpenAPI keeps a mobile method/path that Laravel no longer exposes.

## Current Validation Result

- `php artisan test --filter=MobileBffTest`: passed on 2026-07-02 with 14 tests
  and 441 assertions.
- `php artisan test --filter=MobileBackendSafetyTest`: passed on 2026-07-02
  with 15 tests and 195 assertions.
- `npx @redocly/cli lint docs/hummingbird/api-contract/hummingbird-bff.v1.yaml`:
  OpenAPI is valid on 2026-07-02. Remaining output is 62 warnings for
  style/completeness work such as `operationId`, tag descriptions, a missing
  license field, and two unused schema components.
