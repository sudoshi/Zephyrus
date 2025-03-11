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
- **IMPORTANT**: Do NOT include file extensions in import paths (e.g., `from '@/hooks/useMyHook'` NOT `from '@/hooks/useMyHook.js'`)

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
- `import/extensions`: Enforces no file extensions in import paths

## Pre-commit Hooks

We've set up Husky and lint-staged to run checks before allowing commits:

1. **ESLint** checks for syntax and import/export issues
2. **validate-imports.js** verifies that hooks use named exports consistently
3. **fix-imports.js** automatically fixes common import/export pattern issues
4. **remove-extensions.cjs** removes file extensions from hook imports
5. **build:check** runs a quick build to catch any Vite build issues

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

## Utility Scripts

We have several utility scripts to help maintain import/export consistency:

### fix-imports.js

This script analyzes and automatically fixes common import/export issues:

```bash
# Run in check mode (no changes)
npm run fix-imports -- --dry-run

# Apply fixes automatically
npm run fix-imports
```

### validate-imports.js

This script validates imports/exports and flags potential issues:

```bash
npm run validate-imports
```

### remove-extensions.cjs

This script finds and removes `.js` extensions from hook imports:

```bash
# Check for imports with extensions
node scripts/remove-extensions.cjs

# Fix imports by removing extensions
node scripts/remove-extensions.cjs --fix
```

## CI/CD Environment Considerations

To ensure consistent import resolution between local development and CI/CD environments, we've implemented a custom Vite plugin solution:

### Automated Import Resolution

1. **Vite Import Resolver Plugin**
   - Located in `vite.import-resolver.js`
   - Automatically handles extension resolution for hook imports
   - No need for explicit extensions or special cases
   - Works consistently across all environments

2. **How It Works**
   ```javascript
   // Your imports should NOT include extensions
   import { useORUtilizationData } from '@/hooks/useORUtilizationData';
   import { usePatientFlowData } from '@/hooks/usePatientFlowData';
   ```

   The plugin will:
   - Intercept imports from `@/hooks/*`
   - Try resolving with `.js` extension first
   - Fall back to `.jsx` if needed
   - Handle this consistently in both development and production

3. **Vite Configuration**
   ```javascript
   // vite.config.js
   import importResolver from './vite.import-resolver';

   export default defineConfig({
     plugins: [
       importResolver(),  // Our custom import resolution plugin
       // ... other plugins
     ],
     resolve: {
       alias: {
         '@': '/resources/js',
       },
       extensions: ['.js', '.jsx', '.json'],
     },
   });
   ```

### No More Special Cases

- ✅ No need for explicit extensions in imports
- ✅ No need to maintain a list of exceptions
- ✅ No need for CI/CD-specific scripts
- ✅ Consistent behavior across all environments

### Troubleshooting CI/CD Build Failures

1. **Diagnosing Import Resolution Issues**
   - If a build fails in CI but works locally, check for path resolution differences
   - Add debugging steps to your GitHub Actions workflow to list directory contents
   - Verify file case sensitivity (GitHub Actions runs on Linux which is case-sensitive)

2. **Solutions for Import Resolution Failures**
   - Add explicit file extensions for problematic imports as a temporary solution
   - Document these exceptions with clear comments
   - Consider adding the file to a list of known exceptions in this guide

## Common Issues and Solutions

### Issue: "Could not load module X (imported by Y)"

This error occurs when Vite cannot resolve an import. Common causes:

1. **Mismatched export/import types**:
   - Hook exported as: `export const useMyHook = ...`
   - But imported as: `import useMyHook from ...`
   - Solution: Use named import syntax: `import { useMyHook } from ...`

2. **Hook import resolution**:
   - For hooks in `@/hooks/*`, never include file extensions
   - Our Vite plugin will handle extension resolution automatically
   - Example: `import { useMyHook } from '@/hooks/useMyHook'`

3. **Incorrect path**:
   - Double-check that the path is correct
   - Use the `@` alias for imports from the resources/js directory
   - For hooks, ensure they are in the `@/hooks/` directory

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
