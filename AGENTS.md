# AGENTS.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Project Overview

Zephyrus is a healthcare operations platform for hospital management — covering Emergency Department, Real-Time Demand & Capacity (RTDC), Perioperative, and Process Improvement workflows. It is built with **Laravel 11** (PHP 8.2+) on the backend and **React 18 with Inertia.js** on the frontend, using a PostgreSQL database.

## Build and Development Commands

### Install dependencies
```
composer install
npm install
```

### Run development servers
```
# Start both Laravel (port 8001) and Vite (port 5176):
./start-dev.sh

# Stop both:
./stop-dev.sh

# Or run them individually:
php artisan serve --port=8001   # Laravel on :8001
npm run dev                     # Vite HMR on :5176

# Or use composer's concurrent dev script:
composer run dev           # Runs serve + queue + pail + vite concurrently
```

### Build for production
```
npm run build
```

### Clear Laravel caches
```
./clear-cache.sh
# or individually:
php artisan cache:clear && php artisan config:clear && php artisan route:clear && php artisan view:clear
```

### Run tests
```
php artisan test                          # All tests
php artisan test --testsuite=Unit         # Unit tests only
php artisan test --testsuite=Feature      # Feature tests only
php artisan test --filter=TestClassName   # Single test class
```

### Linting
```
./vendor/bin/pint          # PHP code style (Laravel Pint)
```

### Database
```
php artisan migrate                       # Run migrations
php artisan migrate:rollback              # Rollback last batch
```

Database schema SQL lives in `db/schemas/` (separate from Laravel migrations in `database/migrations/`). The DB uses a multi-schema design: `raw` → `stg` → `prod` → `star`, plus `fhir`. The Laravel app reads from the `prod` schema (configured via `search_path` in `config/database.php`).

## Architecture

### Backend (Laravel + Inertia)

The backend serves as a thin layer that passes data to React via Inertia.js — there are no Blade-rendered views for the main app. Controllers render pages with `Inertia::render('PageName', $props)`.

**Controller organization by domain:**
- `Http/Controllers/Analytics/` — Perioperative analytics (block utilization, OR utilization, turnover times, etc.)
- `Http/Controllers/Operations/` — Room status, block schedule, case management
- `Http/Controllers/Predictions/` — Demand analysis, resource planning, utilization forecast
- `Http/Controllers/Api/` — JSON API endpoints for AJAX data fetching (cases, blocks, analytics, reference data)
- `EDDashboardController` — All Emergency Department pages
- `RTDCDashboardController` — All RTDC pages
- `DashboardController` — Workflow dashboards + Improvement pages
- `ProcessAnalysisController` — Process mining / analysis

**Routes:**
- `routes/web.php` — Main Inertia page routes; CSRF is disabled on most routes via `withoutMiddleware`
- `routes/api.php` — JSON API endpoints under `/api/`
- `routes/auth.php` — Authentication routes (Laravel Breeze)

**Authentication:** The root route (`/`) auto-authenticates as a default admin user and redirects to the dashboard. This is a demo/development setup — there is no login gate.

**Models** map to the `prod` PostgreSQL schema. Key models: `ORCase`, `ORLog`, `Room`, `Provider`, `BlockTemplate`, `BlockUtilization`, `CaseMetrics`, `CaseTiming`, `Location`, `User`. Reference data models live under `Models/Reference/`.

### Frontend (React + Inertia + HeroUI + Tailwind)

**Entry point:** `resources/js/app.jsx` — bootstraps Inertia with the `Providers` wrapper.

**Provider hierarchy** (in `Providers/HeroUIProvider.jsx`):
`HeroUIProvider` → `ModeProvider` → `DashboardProvider`

**Key contexts:**
- `DashboardContext` — Manages active workflow (superuser/rtdc/perioperative/emergency/improvement), navigation items per workflow, and workflow switching via Inertia router
- `ModeContext` — Toggles between `dev` (mock data) and `live` (API) mode, persisted in sessionStorage

**Data fetching pattern:** `services/data-service.js` provides a `DataService` class that checks `ModeContext`. In `dev` mode it returns mock data from `mock-data/`; in `live` mode it hits `/api/` endpoints. Use `DataService.useDataService()` hook in components.

**Page organization mirrors the workflows** (under `resources/js/Pages/`):
- `Home/` — Landing/home page
- `Dashboard/` — Per-workflow dashboard views (Superuser, RTDC, Perioperative, ED, Improvement)
- `Analytics/` — Perioperative analytics pages
- `RTDC/` — RTDC analytics, operations, predictions
- `Improvement/` — Bottlenecks, root cause, process analysis, PDSA cycles
- `Operations/` — Room status, block schedule, cases
- `Predictions/` — Forecast, demand, resource planning

**Layout:** `AuthenticatedLayout` wraps all authenticated pages with `TopNavigation` and dark mode support (dark mode is on by default).

**Component libraries:**
- HeroUI (`@heroui/react`) — Primary UI component library
- Flowbite React — Additional UI components
- Nivo (`@nivo/*`) — Charts (bar, line, pie, heatmap, radar, calendar, circle-packing)
- Recharts — Additional charting
- ReactFlow — Process flow diagrams (used in Process Analysis)
- Lucide React — Icons
- Framer Motion — Animations

**Path alias:** `@` maps to `resources/js/` (configured in `vite.config.js`).

**Styling:** Tailwind CSS with a healthcare-specific theme extension in `tailwind.config.js`. Uses dark mode class strategy. All pages support dark/light mode.

### Deployment

Production deployment uses GitHub Actions CI/CD (`.github/workflows/main.yml`). On push to `main`, it builds assets and deploys via SSH to the production server at `/var/www/Zephyrus/`, using Apache2 as the web server.

Manual deployment: `./deploy.sh` (builds, rsyncs to production, clears caches, restarts Apache).

## Key Patterns

- New pages: Create a React component in `Pages/`, add an Inertia route in `routes/web.php`, and create a controller method that calls `Inertia::render()`.
- New API endpoints: Add to `routes/api.php` with a controller in `Http/Controllers/Api/`.
- Mock data for development: Add mock data files in `resources/js/mock-data/` and wire them into `DataService`.
- Workflow navigation: To add items to a workflow's nav menu, update `workflowNavigationConfig` in `Contexts/DashboardContext.jsx`.
