#!/usr/bin/env node

/**
 * This script adds .js extensions to all hook imports for CI/CD compatibility.
 * It handles both named imports and default imports.
 * 
 * Usage:
 *   node scripts/add-extensions-for-ci-v2.cjs [--dry-run]
 * 
 * Options:
 *   --dry-run  Don't make any changes, just report what would be changed
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

// Configuration
const rootDir = path.resolve(__dirname, '..');
const hooksDir = path.join(rootDir, 'resources', 'js', 'hooks');
const dryRun = process.argv.includes('--dry-run');

// Get all hook files (including those without 'use' prefix)
const hookFiles = fs.readdirSync(hooksDir)
  .filter(file => file.endsWith('.js'));

// Create patterns for hook file names without extensions
const hookBaseNames = hookFiles.map(file => file.replace('.js', ''));

// Find all JS/JSX files in the project
const jsFiles = execSync(`find ${rootDir} -type f -name "*.js" -o -name "*.jsx" | grep -v "node_modules"`, { encoding: 'utf8' })
  .trim()
  .split('\n');

let totalFilesModified = 0;
let totalImportsFixed = 0;

// Process each file
jsFiles.forEach(filePath => {
  if (!fs.existsSync(filePath)) return;
  
  const content = fs.readFileSync(filePath, 'utf8');
  let newContent = content;
  let fileModified = false;
  
  // Process each hook file
  hookBaseNames.forEach(hookName => {
    // Pattern for named imports: import { something } from '@/hooks/hookName'
    const namedImportPattern = new RegExp(`(import\\s+{[^}]+}\\s+from\\s+['"])(@/hooks/${hookName})(['"])`, 'g');
    
    // Pattern for default imports: import Something from '@/hooks/hookName'
    const defaultImportPattern = new RegExp(`(import\\s+[^{][^;]+\\s+from\\s+['"])(@/hooks/${hookName})(['"])`, 'g');
    
    // Replace named imports
    const namedResult = newContent.replace(namedImportPattern, (match, prefix, path, suffix) => {
      totalImportsFixed++;
      fileModified = true;
      return `${prefix}${path}.js${suffix}`;
    });
    
    if (namedResult !== newContent) {
      newContent = namedResult;
    }
    
    // Replace default imports
    const defaultResult = newContent.replace(defaultImportPattern, (match, prefix, path, suffix) => {
      totalImportsFixed++;
      fileModified = true;
      return `${prefix}${path}.js${suffix}`;
    });
    
    if (defaultResult !== newContent) {
      newContent = defaultResult;
    }
  });
  
  // If changes were made, write the file
  if (fileModified) {
    totalFilesModified++;
    console.log(`[${dryRun ? 'DRY RUN' : 'FIXING'}] ${filePath}`);
    
    if (!dryRun) {
      fs.writeFileSync(filePath, newContent, 'utf8');
    }
  }
});

console.log(`\n${dryRun ? 'Would fix' : 'Fixed'} ${totalImportsFixed} hook imports across ${totalFilesModified} files.`);

if (dryRun) {
  console.log('\nRun without --dry-run to apply these changes.');
}
