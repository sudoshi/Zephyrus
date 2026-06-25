#!/usr/bin/env node

/**
 * This script adds .js extensions to hook imports for CI/CD compatibility.
 * It's designed to be run in the CI environment before the build step.
 * 
 * Usage:
 *   node scripts/add-extensions-for-ci.cjs [--dry-run]
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

// Get all hook files
const hookFiles = fs.readdirSync(hooksDir)
  .filter(file => file.endsWith('.js') && file.startsWith('use'));

// Create a regex to match imports from hooks directory without .js extension
const hookImportRegex = new RegExp(
  `import\\s+(?:{[^}]+})\\s+from\\s+['"](@/hooks/)(${hookFiles.map(file => file.replace('.js', '')).join('|')})['"]`,
  'g'
);

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
  
  // Replace hook imports without .js extension
  const newContent = content.replace(hookImportRegex, (match, prefix, hookName) => {
    totalImportsFixed++;
    return `import ${match.split('import')[1].split('from')[0]}from '${prefix}${hookName}.js'`;
  });
  
  // If changes were made, write the file
  if (content !== newContent) {
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
