# Zephyrus Import/Export Pattern Guide

This guide explains the tools and processes we've set up to prevent import/export mismatches like the one that caused our GitHub Actions build failure.

## Table of Contents

1. [Coding Standards](#coding-standards)
2. [ESLint Rules](#eslint-rules)
3. [Pre-commit Hooks](#pre-commit-hooks)
4. [Fix-Imports Script](#fix-imports-script)
5. [Common Issues and Solutions](#common-issues-and-solutions)

## Coding Standards

We've established consistent patterns for exports and imports in our codebase. These standards are documented in `docs/coding-standards.md`. Here's a quick summary:

### For Hooks:
- Always use named exports: `export const useMyHook = () => { ... }`
- Import using named import syntax: `import { useMyHook } from '@/hooks/useMyHook'`

### For Components:
- Use named exports for multiple components in a file
- Use default exports for single-component files
- Be consistent with your import syntax to match the export type

## ESLint Rules

We've configured ESLint with rules that will catch import/export mismatches:

```bash
# Run ESLint to check for issues
npm run lint
```

Key rules that help prevent import/export issues:
- `import/named`: Ensures named imports correspond to named exports
- `import/default`: Ensures default imports correspond to default exports
- `import/namespace`: Ensures namespace imports are valid
- `import/export`: Reports any invalid exports

## Pre-commit Hooks

We've set up Husky and lint-staged to run checks before allowing commits:

1. **ESLint** checks for syntax and import/export issues
2. **check-exports.js** verifies that hooks use named exports consistently
3. **check-imports.js** verifies that imports match the export patterns
4. **build:check** runs a quick build to catch any Vite build issues

These checks run automatically when you commit changes, preventing problematic code from being committed.

## Fix-Imports Script

We've created a script that can automatically fix import/export issues in the codebase:

```bash
# Run in dry-run mode to see what would be changed
npm run fix-imports:dry

# Run to actually fix the issues
npm run fix-imports
```

This script:
1. Finds all hook files and ensures they use named exports
2. Finds all imports of those hooks and ensures they use the correct import syntax
3. Generates a report of issues found and fixed

## Common Issues and Solutions

### Issue: "Could not load module X (imported by Y)"

This error occurs when Vite cannot resolve an import. Common causes:

1. **Mismatched export/import types**:
   - Hook exported as: `export const useMyHook = ...`
   - But imported as: `import useMyHook from ...`
   - Solution: Use named import syntax: `import { useMyHook } from ...`

2. **Missing file extension**:
   - Some build tools require file extensions in import paths
   - Solution: Add the extension: `import { x } from './y.js'`

3. **Incorrect path**:
   - Double-check that the path is correct
   - Use the `@` alias for imports from the resources/js directory

### Issue: "Export 'X' not found"

This occurs when you try to import something that isn't exported with that name:

1. **Check the export in the source file**:
   - Is it exported as named or default?
   - Does the name match exactly?

2. **Fix the import**:
   - For named exports: `import { X } from './y'`
   - For default exports: `import X from './y'`

## Best Practices

1. **Be consistent**: Choose one export pattern for similar types of modules
2. **Run the linter regularly**: `npm run lint`
3. **Use the fix-imports script**: `npm run fix-imports`
4. **Check the build before committing**: `npm run build:check`

By following these guidelines and using the tools we've set up, we can prevent import/export mismatches from causing build failures in the future.
