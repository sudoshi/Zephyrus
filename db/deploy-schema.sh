#!/bin/bash

# OR Analytics Platform Schema Deployment Script
# This script deploys the database schema in the correct order

set -e  # Exit on error

# Configuration
DB_HOST=${PGHOST:-localhost}
DB_PORT=${PGPORT:-5432}
DB_NAME=${PGDATABASE:-oap_db}
DB_USER=${PGUSER:-postgres}

# Schema order based on dependencies
SCHEMAS=(
    "raw"    # Raw data imports
    "stg"    # Staging area
    "prod"   # Production application data
    "star"   # Star schema for analytics
    "fhir"   # FHIR standard healthcare data
)

# Text colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Helper functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Get array index
array_index() {
    local value="$1"
    local -a arr=("${SCHEMAS[@]}")
    for i in "${!arr[@]}"; do
        if [[ "${arr[$i]}" = "${value}" ]]; then
            echo "${i}";
            return 0
        fi
    done
    echo "-1"
}

# Function to execute SQL file
execute_sql_file() {
    local file=$1
    log_info "Executing $file..."
    if psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -U "$DB_USER" -f "$file"; then
        log_info "Successfully executed $file"
    else
        log_error "Failed to execute $file"
        exit 1
    fi
}

# Function to verify schema
verify_schema() {
    local schema=$1
    log_info "Verifying $schema schema..."
    psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -U "$DB_USER" << EOF
    SELECT schemaname, tablename 
    FROM pg_tables 
    WHERE schemaname = '$schema' 
    ORDER BY tablename;

    SELECT 'Number of tables in $schema schema: ' || COUNT(*) 
    FROM pg_tables 
    WHERE schemaname = '$schema';
EOF
}

# Function to check if schema exists
check_schema_exists() {
    local schema=$1
    local exists=$(psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -U "$DB_USER" -tAc "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '$schema'")
    [[ $exists -eq 1 ]]
}

# Main deployment process
main() {
    log_info "Starting schema deployment for OR Analytics Platform"
    log_info "Using database: $DB_NAME on $DB_HOST:$DB_PORT"

    # Execute base schema creation first
    execute_sql_file "schemas/init/001-schemas.sql"

    # Execute schema-specific initialization scripts in dependency order
    for schema in "${SCHEMAS[@]}"; do
        local schema_dir="schemas/init/$(printf "%03d" $(($(array_index "$schema")+2)))-${schema}"
        if [ -d "$schema_dir" ]; then
            log_info "Initializing $schema schema..."
            for script in "$schema_dir"/*.sql; do
                if [ -f "$script" ]; then
                    execute_sql_file "$script"
                fi
            done
        fi
    done

    # Execute schema-specific migrations in dependency order
    for schema in "${SCHEMAS[@]}"; do
        local migrations_dir="schemas/migrations/${schema}"
        if [ -d "$migrations_dir" ] && [ "$(ls -A $migrations_dir 2>/dev/null)" ]; then
            log_info "Executing migrations for $schema schema..."
            for migration in "$migrations_dir"/*.sql; do
                if [ -f "$migration" ]; then
                    execute_sql_file "$migration"
                fi
            done
        fi
    done

    # Verify all schemas
    for schema in "${SCHEMAS[@]}"; do
        if check_schema_exists "$schema"; then
            verify_schema "$schema"
        else
            log_warn "Schema $schema not found during verification"
        fi
    done

    log_info "Schema deployment completed successfully"
}

# Script entry point
cd "$(dirname "$0")" # Change to script directory
main "$@"
