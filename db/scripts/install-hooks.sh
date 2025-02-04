#!/bin/bash

# Install Git hooks for database schema versioning
# This script:
# 1. Creates symlinks to Git hooks
# 2. Makes hooks executable
# 3. Validates hook installation

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

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

# Get repository root
REPO_ROOT=$(git rev-parse --show-toplevel)
HOOKS_DIR="$REPO_ROOT/.git/hooks"
SOURCE_DIR="$REPO_ROOT/db/scripts/git-hooks"

# Ensure hooks directory exists
mkdir -p "$HOOKS_DIR"

# Install hooks
install_hook() {
    local hook=$1
    local source="$SOURCE_DIR/$hook"
    local target="$HOOKS_DIR/$hook"
    
    if [[ -f "$source" ]]; then
        # Remove existing hook if it's not a symlink to our hook
        if [[ -f "$target" && ! -L "$target" ]]; then
            log_warn "Backing up existing $hook hook to ${target}.bak"
            mv "$target" "${target}.bak"
        fi
        
        # Create symlink
        ln -sf "$source" "$target"
        chmod +x "$source"
        log_info "Installed $hook hook"
    else
        log_error "Source hook $hook not found"
        return 1
    fi
}

# Install all hooks
main() {
    log_info "Installing Git hooks for database schema versioning..."
    
    # Install pre-commit hook
    install_hook "pre-commit"
    
    # Verify installation
    if [[ -x "$HOOKS_DIR/pre-commit" ]]; then
        log_info "Hooks installed successfully"
        
        # Initial schema capture
        read -p "Capture initial schema state? [Y/n] " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            for schema in raw stg prod star fhir; do
                psql -c "SELECT * FROM public.capture_schema_state('$schema')"
            done
            log_info "Initial schema state captured"
        fi
    else
        log_error "Hook installation failed"
        exit 1
    fi
}

# Run main function
main
