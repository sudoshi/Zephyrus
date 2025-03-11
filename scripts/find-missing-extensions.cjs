#!/usr/bin/env node

/**
 * Script to find all imports with file extensions and remove them
 * This specifically targets hook imports that have .js extensions
 * (Project standard is to NOT include file extensions in imports)
 */

const fs = require('fs');
const path = require('path');
const glob = require('glob');

// Create a simple color function since chalk might have compatibility issues
const colors = {
  cyan: (text) => '\x1b[36m' + text + '\x1b[0m',
  yellow: (text) => '\x1b[33m' + text + '\x1b[0m',
  green: (text) => '\x1b[32m' + text + '\x1b[0m',
  red: (text) => '\x1b[31m' + text + '\x1b[0m',
  reset: '\x1b[0m'
};

// Configuration
const JS_ROOT = path.join(process.cwd(), 'resources', 'js');
const HOOKS_DIR = path.join(JS_ROOT, 'hooks');
const FIX_MODE = process.argv.includes('--fix');

console.log(colors.cyan('Zephyrus Extension Remover'));
console.log(colors.cyan('========================='));

// Get all hook files
const hookFiles = glob.sync(`${HOOKS_DIR}/*.js`);
const hookNames = hookFiles.map(file => path.basename(file, '.js'));

console.log(`Found ${hookNames.length} hook files to check for imports`);

// Get all JS/JSX files
const jsFiles = glob.sync(`${JS_ROOT}/**/*.{js,jsx}`);

// Stats
const stats = {
  filesChecked: 0,
  extensionsFound: 0,
  filesFixed: 0
};

// Track files with issues for reporting
const filesWithIssues = [];

// Check all JS files for imports WITH extensions (we want to remove them)
jsFiles.forEach(filePath => {
  // Skip the hook files themselves
  if (hookFiles.includes(filePath)) {
    return;
  }
  
  stats.filesChecked++;
  const content = fs.readFileSync(filePath, 'utf8');
  let newContent = content;
  let fileModified = false;
  
  // Look for hook imports without .js extension
  hookNames.forEach(hookName => {
    // Match imports like: from '@/hooks/useHookName' (without .js)
    const importRegex = new RegExp(`from\\s+['"]@/hooks/${hookName}['"]`, 'g');
    if (content.match(importRegex)) {
      console.log(colors.yellow(`⚠️ Missing extension in: ${filePath}`));
      console.log(`   Import: @/hooks/${hookName} should be @/hooks/${hookName}.js`);
      stats.issuesFound++;
      
      if (!filesWithIssues.includes(filePath)) {
        filesWithIssues.push(filePath);
      }
      
      if (FIX_MODE) {
        // Replace with correct import path WITHOUT .js extension
        newContent = newContent.replace(
          importRegex, 
          `from '@/hooks/${hookName}'`
        );
        fileModified = true;
      }
    }
  });
  
  // Save changes if in fix mode and file was modified
  if (FIX_MODE && fileModified) {
    fs.writeFileSync(filePath, newContent, 'utf8');
    console.log(colors.green(`✅ Fixed imports in: ${filePath}`));
    stats.filesFixed++;
  }
});

// Print summary
console.log('\n' + colors.cyan('Summary:'));
console.log(colors.cyan('========'));
console.log(`Files checked: ${stats.filesChecked}`);
console.log(`Files with extensions: ${filesWithIssues.length}`);
console.log(`Total extensions found: ${stats.extensionsFound}`);

if (FIX_MODE) {
  console.log(`Files fixed: ${stats.filesFixed}`);
} else {
  console.log(colors.yellow('\nRun with --fix to automatically remove extensions'));
}

// List all files with issues
if (filesWithIssues.length > 0) {
  console.log('\n' + colors.cyan('Files with missing extensions:'));
  console.log(colors.cyan('============================='));
  filesWithIssues.forEach(file => {
    console.log(`- ${file}`);
  });
}

// Exit with error code if extensions were found and not fixed
if (stats.extensionsFound > 0 && !FIX_MODE) {
  process.exit(1);
}

console.log(colors.green('\nDone!'));
