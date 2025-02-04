#!/bin/bash

# OR Analytics Platform Data Examination Script
# This script runs queries to examine the contents of the prod schema tables

set -e  # Exit on error

# Configuration
DB_HOST=${PGHOST:-localhost}
DB_PORT=${PGPORT:-5432}
DB_NAME=${PGDATABASE:-oap_db}
DB_USER=${PGUSER:-postgres}

# Text colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Helper functions
print_section() {
    echo -e "\n${BLUE}=== $1 ===${NC}\n"
}

print_subsection() {
    echo -e "\n${YELLOW}--- $1 ---${NC}\n"
}

# Function to execute a named query from examine-tables.sql
execute_query() {
    local section=$1
    local query_name=$2
    
    print_subsection "$section"
    
    # Extract and execute the query between the specified comment and the next comment
    sed -n "/-- $query_name/,/-- /p" "$(dirname "$0")/examine-tables.sql" |
        grep -v "^--" |
        psql -h "$DB_HOST" -p "$DB_PORT" -d "$DB_NAME" -U "$DB_USER" \
             -X \
             --pset="footer=off" \
             --pset="border=2" \
             --pset="format=aligned" \
             --pset="linestyle=unicode"
}

main() {
    print_section "OR Analytics Platform Data Examination"
    echo "Database: $DB_NAME on $DB_HOST:$DB_PORT"
    echo "Examining contents of prod schema..."
    
    # Table Overview
    execute_query "Table Overview" "Get overview of all tables"
    
    # Location Information
    execute_query "Location Information" "Examine locations"
    execute_query "Room Information" "Examine rooms"
    
    # Provider and Service Information
    execute_query "Provider Information" "Examine providers"
    execute_query "Service Information" "Examine services"
    
    # Case Information
    execute_query "Case Distribution" "Examine case distribution"
    execute_query "Block Templates" "Examine block templates"
    
    # Case Details
    execute_query "Case Measurements" "Examine case measurements"
    execute_query "Case Resources" "Examine case resources"
    execute_query "Case Safety Notes" "Examine case safety notes"
    execute_query "Case Timings" "Examine case timings"
    
    # Transport and Journey Information
    execute_query "Case Transport" "Examine case transport"
    execute_query "Care Journey Milestones" "Examine care journey milestones"
    
    print_section "Examination Complete"
}

# Script entry point
cd "$(dirname "$0")" # Change to script directory
main "$@"
