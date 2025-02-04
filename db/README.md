# OR Analytics Platform Database

## Schema Organization

The database is organized into multiple schemas, each serving a specific purpose:

1. `raw` - Raw data imports
   - Stores data in its original format
   - Tracks import history and data sources
   - Uses JSONB for flexible data storage

2. `stg` - Staging area
   - Validates and transforms raw data
   - Standardizes data structures
   - Tracks transformation jobs and validation results

3. `prod` - Production application data
   - Core application tables
   - Normalized data structures
   - Referential integrity enforced

4. `star` - Analytics schema
   - Dimensional model for analytics
   - Fact and dimension tables
   - Optimized for reporting queries

5. `fhir` - FHIR standard healthcare data
   - FHIR resource storage
   - Mapping tables to internal data
   - FHIR operations tracking

## Schema Dependencies

```
raw   → stg   (ETL processing)
stg   → prod  (Data validation/transformation)
prod  → star  (Analytics transformation)
prod  → fhir  (FHIR conversion)
```

## Directory Structure

```
db/
├── schemas/
│   ├── init/                    # Initial schema creation
│   │   ├── 001-schemas.sql      # Create all schemas
│   │   ├── 002-raw/            # Raw data schema
│   │   ├── 003-stg/            # Staging schema
│   │   ├── 004-prod/           # Production schema
│   │   ├── 005-star/           # Star schema
│   │   └── 006-fhir/           # FHIR schema
│   └── migrations/             # Schema-specific migrations
│       ├── raw/                # Raw schema migrations
│       ├── stg/                # Staging schema migrations
│       ├── prod/               # Production schema migrations
│       ├── star/               # Star schema migrations
│       └── fhir/               # FHIR schema migrations
└── deploy-schema.sh           # Deployment script
```

## Deployment Process

1. Environment Setup
   ```bash
   # Set database connection details
   export PGHOST=localhost
   export PGPORT=5432
   export PGDATABASE=oap_db
   export PGUSER=postgres
   ```

2. Run Deployment Script
   ```bash
   ./deploy-schema.sh
   ```

The deployment script:
- Creates schemas in dependency order
- Executes initialization scripts
- Applies schema-specific migrations
- Verifies schema deployment

## Migration Guidelines

1. Naming Convention
   - Init scripts: `NNN-description.sql`
   - Migrations: `YYYYMMDD-NN-description.sql`

2. Safety Measures
   - All DDL operations use IF EXISTS/IF NOT EXISTS
   - Column additions must be nullable or have defaults
   - No direct column drops (mark as deprecated)
   - All constraints must be named
   - All operations wrapped in transactions

3. Version Control
   - Each schema tracks its migrations
   - Dependencies are explicitly declared
   - Checksums verify migration integrity

## Adding New Migrations

1. Create Migration File
   ```bash
   # Schema-specific migration
   touch db/schemas/migrations/[schema]/$(date +%Y%m%d)-01-description.sql
   ```

2. Migration Structure
   ```sql
   /*
   Description: Brief description
   Dependencies: List dependencies
   Author: Your name
   Date: YYYY-MM-DD
   */

   BEGIN;
   -- Your SQL here
   COMMIT;

   /*
   Rollback instructions:
   BEGIN;
   -- Rollback SQL here
   COMMIT;
   */
   ```

## Troubleshooting

1. Check Schema Status
   ```sql
   SELECT schemaname, COUNT(*) as table_count 
   FROM pg_tables 
   WHERE schemaname IN ('raw', 'stg', 'prod', 'star', 'fhir')
   GROUP BY schemaname;
   ```

2. View Migration History
   ```sql
   SELECT schema_name, migration_name, applied_at 
   FROM public.schema_versions 
   ORDER BY applied_at;
   ```

3. Check Dependencies
   ```sql
   SELECT * FROM public.schema_dependencies;
   ```

## Safety Features

1. Schema Validation
   - Dependency checking before migrations
   - Table existence verification
   - Constraint validation

2. Error Handling
   - Transactional DDL operations
   - Detailed error logging
   - Rollback instructions

3. Monitoring
   - Migration execution tracking
   - Schema version control
   - Operation logging

## Schema Version Control

The database schema is version controlled and integrated with Git through:

1. Schema Metadata
   - Object definitions and checksums
   - Git commit tracking
   - Change detection
   - Migration history

2. Git Integration
   ```bash
   # Install Git hooks
   ./scripts/install-hooks.sh
   ```

   This sets up:
   - Pre-commit hooks for schema validation
   - Automatic migration generation
   - Schema state tracking

3. Schema Changes
   - Changes detected automatically
   - Migration files generated
   - Git metadata updated
   - Version history maintained

4. Migration Process
   ```sql
   -- Check for changes
   SELECT * FROM public.detect_schema_changes('schema_name');
   
   -- View version history
   SELECT * FROM public.schema_versions 
   ORDER BY applied_at;
   
   -- Check object metadata
   SELECT * FROM public.schema_metadata 
   WHERE schema_name = 'schema_name';
   ```

5. Safety Features
   - Checksum validation
   - Dependency tracking
   - Rollback support
   - Change verification

## Data Exploration

The repository includes tools for safely examining the database contents:

### Quick Start
```bash
# Set database connection
export PGHOST=localhost
export PGPORT=5432
export PGDATABASE=oap_db
export PGUSER=postgres

# Run examination script
./scripts/examine-data.sh
```

### Available Reports

1. Table Overview
   - Table sizes and row counts
   - Index sizes
   - Total storage usage

2. Location Information
   - Location details and types
   - Room assignments
   - Case distribution

3. Provider and Service Information
   - Provider specialties and cases
   - Service utilization
   - Block assignments

4. Case Information
   - Case status distribution
   - Block template usage
   - Case measurements and timings

5. Care Journey Details
   - Transport records
   - Safety notes
   - Milestone completion rates

### Custom Queries

The examination queries are stored in `scripts/examine-tables.sql`. You can:
1. Modify existing queries
2. Add new queries by following the format:
   ```sql
   -- Query Name
   SELECT ...
   FROM ...
   WHERE ...;
   ```

### Data Safety

All examination queries are:
- Read-only operations
- Non-blocking
- Performance optimized
- Index-aware

### Output Format

Results are displayed in a formatted table with:
- Unicode borders
- Aligned columns
- Section headers
- Color coding for readability
