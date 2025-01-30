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

# Function to stop a process by PID file
stop_process() {
    local pid_file=$1
    local process_name=$2
    
    if [ -f "$pid_file" ]; then
        local pid=$(cat "$pid_file")
        if ps -p "$pid" > /dev/null; then
            print_status "$YELLOW" "Stopping $process_name (PID: $pid)..."
            kill "$pid"
            # Wait for process to stop
            for i in {1..5}; do
                if ! ps -p "$pid" > /dev/null; then
                    break
                fi
                sleep 1
            done
            # Force kill if still running
            if ps -p "$pid" > /dev/null; then
                print_status "$YELLOW" "Force stopping $process_name..."
                kill -9 "$pid"
            fi
        fi
        rm "$pid_file"
    fi
}

# Stop Laravel server
stop_process "$LARAVEL_PID_FILE" "Laravel development server"

# Stop Vite server
stop_process "$VITE_PID_FILE" "Vite development server"

# Check for any remaining processes on the ports
if check_port 8000; then
    print_status "$YELLOW" "Cleaning up remaining process on port 8000..."
    lsof -ti :8000 | xargs kill -9 > /dev/null 2>&1
fi

if check_port 5173; then
    print_status "$YELLOW" "Cleaning up remaining process on port 5173..."
    lsof -ti :5173 | xargs kill -9 > /dev/null 2>&1
fi

# Final verification
if ! check_port 8000 && ! check_port 5173; then
    print_status "$GREEN" "Development servers stopped successfully!"
else
    print_status "$RED" "Warning: Some processes may still be running"
    print_status "$YELLOW" "Please check manually with: lsof -i :8000 and lsof -i :5173"
fi

# Clean up PID directory if empty
if [ -d "$PID_DIR" ] && [ -z "$(ls -A $PID_DIR)" ]; then
    rmdir "$PID_DIR"
fi
