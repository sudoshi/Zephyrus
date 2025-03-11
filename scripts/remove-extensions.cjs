#!/usr/bin/env node

/**
 * Script to remove .js extensions from hook imports
 * This script will revert the changes made by find-missing-extensions.cjs
 */

const fs = require('fs');
const path = require('path');
const glob = require('glob');

// Configuration
const JS_ROOT = path.join(process.cwd(), 'resources', 'js');
const HOOKS_DIR = path.join(JS_ROOT, 'hooks');
const DRY_RUN = process.argv.includes('--dry-run');

// Output styling
const colors = {
  cyan: (text) => '\x1b[36m' + text + '\x1b[0m',
  yellow: (text) => '\x1b[33m' + text + '\x1b[0m',
  green: (text) => '\x1b[32m' + text + '\x1b[0m',
  red: (text) => '\x1b[31m' + text + '\x1b[0m',
  reset: '\x1b[0m'
};

console.log(colors.cyan('Zephyrus Extension Remover'));
console.log(colors.cyan('==========================='));
console.log(`Mode: ${DRY_RUN ? colors.yellow('Dry Run (no changes will be made)') : colors.green('Live Run (changes will be applied)')}`);

// Get all hook files
const hookFiles = glob.sync(`${HOOKS_DIR}/*.js`);
const hookNames = hookFiles.map(file => path.basename(file, '.js'));

console.log(`Found ${hookNames.length} hook files to check for imports`);

// Get all JS/JSX files
const jsFiles = glob.sync(`${JS_ROOT}/**/*.{js,jsx}`);

// Stats
const stats = {
  filesChecked: 0,
  filesModified: 0,
  importsFixed: 0
};

// Check all JS files for imports with .js extensions
jsFiles.forEach(filePath => {
  // Skip the hook files themselves
  if (hookFiles.includes(filePath)) {
    return;
  }
  
  stats.filesChecked++;
  const content = fs.readFileSync(filePath, 'utf8');
  let newContent = content;
  let fileModified = false;
  
  // Look for hook imports with .js extension
  hookNames.forEach(hookName => {
    // Match imports like: from '@/hooks/useHookName.js'
    const importRegex = new RegExp(`from\\s+['"]@/hooks/${hookName}\\.js['"]`, 'g');
    if (content.match(importRegex)) {
      console.log(colors.yellow(`🔧 Found extension to remove in: ${filePath}`));
      console.log(`   Import: @/hooks/${hookName}.js -> @/hooks/${hookName}`);
      
      // Replace with correct import path without .js extension
      newContent = newContent.replace(
        importRegex, 
        `from '@/hooks/${hookName}'`
      );
      fileModified = true;
      stats.importsFixed++;
    }
  });
  
  // Save changes if file was modified
  if (fileModified) {
    stats.filesModified++;
    if (!DRY_RUN) {
      fs.writeFileSync(filePath, newContent, 'utf8');
      console.log(colors.green(`✅ Removed extensions in: ${filePath}`));
    } else {
      console.log(colors.yellow(`⏩ Would remove extensions in: ${filePath} (dry run)`));
    }
  }
});

// Print summary
console.log('\n' + colors.cyan('Summary:'));
console.log(colors.cyan('========'));
console.log(`Files checked: ${stats.filesChecked}`);
console.log(`Files with extensions: ${stats.filesModified}`);
console.log(`Total import paths fixed: ${stats.importsFixed}`);

if (DRY_RUN) {
  console.log(colors.yellow('\nThis was a dry run. No changes were made.'));
  console.log(colors.yellow('Run without --dry-run to apply the changes.'));
} else {
  console.log(colors.green('\nAll changes applied successfully!'));
}

console.log(colors.green('\nDone!'));
