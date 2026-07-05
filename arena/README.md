# Patient-Flow Arena — OCPM sidecar (Part X, X1)

The stateless, read-only object-centric process-mining engine for
[Part X](../docs/ZEPHYRUS-2.0-PART-X.md). It reads a **de-identified OCEL 2.0
export** (produced by `php artisan ocel:export`) and returns canon-shaped
discovery JSON. The Laravel app orchestrates + caches; this service only mines.

- **No PHI, no prod.\* access.** It reads a de-identified OCEL log
  (`patient-<hash>`, `enc-<hash>`, coded clinical flags only). Its blast radius
  is one JSON file.
- **pm4py does the mining;** this is the seam. Treat the relational `ocel.*`
  store as the contract and pm4py as replaceable (§X.2 "standard drift").
- Ships behind `ARENA_ENABLED` (the Laravel flag gates the routes that call it).

## Endpoints

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/health` | — | liveness + pm4py availability + whether the export is present (always 200) |
| POST | `/ocel/summary` | `{ocel_path?}` \| `{ocel?}` | `{events, objects, object_types{}, activities{}}` |
| POST | `/discover` | `{ocel_path?/ocel?, object_types?[], activity_min_freq?}` | `{object_types[], nodes[], edges[], stats{}}` |

**OCEL source resolution** (in order): inline `ocel` doc → explicit `ocel_path`
→ the configured `ARENA_OCEL_EXPORT_PATH` (default `/data/ocel/ocel-export.json`).

**Discovery output** is the object-centric DFG as the union of per-object-type
directly-follows relations — each `node` tagged with the object types that touch
it, each `edge` tagged with its single `object_type`. Performance overlays
(OPerA sync/lag/pool) are **X2**, not X1.

## Run

```bash
# 1. Produce the OCEL export the sidecar reads (writes storage/app/ocel/…)
php artisan ocel:project --days=90 && php artisan ocel:export --out=storage/app/ocel/ocel-export.json

# 2a. Via compose (opt-in profile; mounts storage/app/ocel read-only)
docker compose --profile arena up -d --build arena
curl -s localhost:${ARENA_PORT:-8110}/health | jq

# 2b. Standalone (no compose), e.g. to validate the image
docker build -f docker/arena/Dockerfile -t zephyrus-arena:dev .
docker run --rm -p 8110:8100 -v "$PWD/storage/app/ocel:/data/ocel:ro" zephyrus-arena:dev
```

Discover a map (Encounter + Bed lifecycles):

```bash
curl -s localhost:8110/discover -H 'content-type: application/json' \
  -d '{"object_types":["Encounter","Bed"],"activity_min_freq":2}' | jq '.stats'
```

## Test

```bash
docker run --rm -v "$PWD/arena:/app" -w /app zephyrus-arena:dev \
  sh -c "pip install -q pytest && pytest -q"
```

## Deploy

Two supported shapes (§X.4.2). The **long-running FastAPI service** is the
default (lower latency for the workbench):

- **Container** (this image) as a sibling to `eddy` — `docker compose --profile arena up -d`.
- **systemd + uvicorn** on the Apache/php-fpm host, mirroring the `zephyrus-reverb`
  unit: a venv running `uvicorn app.main:app --port 8100`, Apache/nginx proxying
  `/arena/*` if exposed. The OCEL export path is then a host path, not `/data`.

The Laravel orchestrator (`/api/arena/*` routes + a sidecar client that caches
into `arena.maps`) and the React Study UI Map pane are the **remaining X1 work**;
this service is the engine they call.
