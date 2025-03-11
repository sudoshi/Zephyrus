#!/usr/bin/env node

/**
 * Script to find all imports that are missing file extensions
 * This specifically targets hook imports that don't have .js extensions
 */

const fs = require('fs');
const path = require('path');
const glob = require('glob');
const chalk = require('chalk');

// Configuration
const JS_ROOT = path.join(process.cwd(), 'resources', 'js');
const HOOKS_DIR = path.join(JS_ROOT, 'hooks');
const FIX_MODE = process.argv.includes('--fix');

console.log(chalk.cyan('Zephyrus Missing Extensions Finder'));
console.log(chalk.cyan('==================================='));

// Get all hook files
const hookFiles = glob.sync(`${HOOKS_DIR}/*.js`);
const hookNames = hookFiles.map(file => path.basename(file, '.js'));

console.log(`Found ${hookNames.length} hook files to check for imports`);

// Get all JS/JSX files
const jsFiles = glob.sync(`${JS_ROOT}/**/*.{js,jsx}`);

// Stats
const stats = {
  filesChecked: 0,
  issuesFound: 0,
  filesFixed: 0
};

// Track files with issues for reporting
const filesWithIssues = [];

// Check all JS files for imports without extensions
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
      console.log(chalk.yellow(`⚠️ Missing extension in: ${filePath}`));
      console.log(`   Import: @/hooks/${hookName} should be @/hooks/${hookName}.js`);
      stats.issuesFound++;
      
      if (!filesWithIssues.includes(filePath)) {
        filesWithIssues.push(filePath);
      }
      
      if (FIX_MODE) {
        // Replace with correct import path including .js extension
        newContent = newContent.replace(
          importRegex, 
          `from '@/hooks/${hookName}.js'`
        );
        fileModified = true;
      }
    }
  });
  
  // Save changes if in fix mode and file was modified
  if (FIX_MODE && fileModified) {
    fs.writeFileSync(filePath, newContent, 'utf8');
    console.log(chalk.green(`✅ Fixed imports in: ${filePath}`));
    stats.filesFixed++;
  }
});

// Print summary
console.log('\n' + chalk.cyan('Summary:'));
console.log(chalk.cyan('========'));
console.log(`Files checked: ${stats.filesChecked}`);
console.log(`Files with missing extensions: ${filesWithIssues.length}`);
console.log(`Total issues found: ${stats.issuesFound}`);

if (FIX_MODE) {
  console.log(`Files fixed: ${stats.filesFixed}`);
} else {
  console.log(chalk.yellow('\nRun with --fix to automatically add missing extensions'));
}

// List all files with issues
if (filesWithIssues.length > 0) {
  console.log('\n' + chalk.cyan('Files with missing extensions:'));
  console.log(chalk.cyan('============================='));
  filesWithIssues.forEach(file => {
    console.log(`- ${file}`);
  });
}

// Exit with error code if issues were found and not fixed
if (stats.issuesFound > 0 && !FIX_MODE) {
  process.exit(1);
}

console.log(chalk.green('\nDone!'));
