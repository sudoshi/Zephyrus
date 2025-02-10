#!/bin/bash

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    local color=$1
    local message=$2
    echo -e "${color}${message}${NC}"
}

# Function to check if a port is in use
check_port() {
    local port=$1
    if lsof -i :$port > /dev/null; then
        return 0
    else
        return 1
    fi
}

# Store the script's directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PID_DIR="${SCRIPT_DIR}/storage/pids"
LARAVEL_PID_FILE="${PID_DIR}/laravel.pid"
VITE_PID_FILE="${PID_DIR}/vite.pid"

# Create pids directory if it doesn't exist
mkdir -p "${PID_DIR}"

# Check if PostgreSQL is running
if ! pg_isready > /dev/null 2>&1; then
    print_status "$RED" "Error: PostgreSQL is not running"
    exit 1
fi

# Check if .env file exists
if [ ! -f "${SCRIPT_DIR}/.env" ]; then
    print_status "$RED" "Error: .env file not found"
    exit 1
fi

# Check if required ports are available
if check_port 8000; then
    print_status "$RED" "Error: Port 8000 is already in use"
    exit 1
fi

if check_port 5173; then
    print_status "$RED" "Error: Port 5173 is already in use"
    exit 1
fi

# Install dependencies if needed
if [ ! -d "${SCRIPT_DIR}/vendor" ]; then
    print_status "$YELLOW" "Installing PHP dependencies..."
    composer install
fi

if [ ! -d "${SCRIPT_DIR}/node_modules" ]; then
    print_status "$YELLOW" "Installing Node.js dependencies..."
    npm install
fi

# Clear Laravel caches
print_status "$YELLOW" "Clearing Laravel caches..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Run database migrations
# print_status "$YELLOW" "Running database migrations..."
# php artisan migrate

# Start Laravel development server
print_status "$YELLOW" "Starting Laravel development server..."
php artisan serve > /dev/null 2>&1 & echo $! > "${LARAVEL_PID_FILE}"

# Start Vite development server
print_status "$YELLOW" "Starting Vite development server..."
npm run dev > /dev/null 2>&1 & echo $! > "${VITE_PID_FILE}"

# Wait for servers to start
sleep 3

# Check if servers are running
if ! check_port 8000; then
    print_status "$RED" "Error: Laravel development server failed to start"
    exit 1
fi

if ! check_port 5173; then
    print_status "$RED" "Error: Vite development server failed to start"
    exit 1
fi

print_status "$GREEN" "Development servers started successfully!"
print_status "$GREEN" "Laravel: http://localhost:8000"
print_status "$GREEN" "Vite: http://localhost:5173"
print_status "$YELLOW" "Use ./stop-dev.sh to stop the servers"
