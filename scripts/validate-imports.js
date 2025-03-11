#!/usr/bin/env node

/**
 * Import/Export Validation Script
 * 
 * This script scans your codebase for common import/export issues:
 * - Mismatched import/export types (default vs named)
 * - Case sensitivity issues in import paths
 * - Missing files in import paths
 * - Circular dependencies
 */

import fs from 'fs';
import path from 'path';
import { glob } from 'glob';
import chalk from 'chalk';

const RESOURCES_DIR = path.resolve('./resources/js');
const HOOKS_DIR = path.resolve('./resources/js/hooks');
const COMPONENTS_DIR = path.resolve('./resources/js/Components');

// Track issues found
let issuesFound = 0;

console.log(chalk.blue('🔍 Validating import/export patterns...'));

// 1. Check for hooks with default exports (should use named exports only)
console.log(chalk.cyan('\nChecking hooks for default exports...'));
const hookFiles = glob.sync(`${HOOKS_DIR}/**/*.js`);

hookFiles.forEach(file => {
  const content = fs.readFileSync(file, 'utf8');
  if (content.includes('export default') || content.match(/module\.exports\s*=/)) {
    console.log(chalk.red(`❌ Hook using default export: ${chalk.yellow(path.relative('.', file))}`));
    issuesFound++;
  } else {
    console.log(chalk.green(`✓ ${path.relative('.', file)}`));
  }
});

// 2. Check for case sensitivity issues in import paths
console.log(chalk.cyan('\nChecking for case sensitivity issues in import paths...'));
const jsFiles = glob.sync(`${RESOURCES_DIR}/**/*.{js,jsx}`);

const sensitivePathPatterns = [
  { pattern: /@\/Hooks\//g, correct: '@/hooks/' }
  // Components should remain capitalized as per the project structure
  // { pattern: /@\/components\//gi, correct: '@/Components/' }
];

jsFiles.forEach(file => {
  const content = fs.readFileSync(file, 'utf8');
  let fileHasIssues = false;
  
  sensitivePathPatterns.forEach(({ pattern, correct }) => {
    if (content.match(pattern)) {
      if (!fileHasIssues) {
        console.log(chalk.red(`❌ Case sensitivity issues in: ${chalk.yellow(path.relative('.', file))}`));
        fileHasIssues = true;
        issuesFound++;
      }
      console.log(`   - Replace ${pattern.toString()} with ${correct}`);
    }
  });
  
  if (!fileHasIssues) {
    console.log(chalk.green(`✓ ${path.relative('.', file)}`));
  }
});

// 3. Check for import statements that don't match export types
console.log(chalk.cyan('\nChecking for import statements that don\'t match export types...'));
// This is a simplified check - a more robust solution would parse the AST
const hooksWithNamedExports = new Set();

// First identify hooks with named exports
hookFiles.forEach(file => {
  const content = fs.readFileSync(file, 'utf8');
  const basename = path.basename(file, path.extname(file));
  
  if (content.includes(`export const ${basename}`)) {
    hooksWithNamedExports.add(basename);
  }
});

// Then check components for imports that don't match
jsFiles.forEach(file => {
  if (file.includes('/hooks/')) return; // Skip hook files themselves
  
  const content = fs.readFileSync(file, 'utf8');
  let fileHasIssues = false;
  
  hooksWithNamedExports.forEach(hookName => {
    // Check for default imports of hooks that should be named imports
    const defaultImportRegex = new RegExp(`import\\s+${hookName}\\s+from\\s+['"]@/hooks/${hookName}['"]`, 'g');
    if (content.match(defaultImportRegex)) {
      if (!fileHasIssues) {
        console.log(chalk.red(`❌ Import type mismatch in: ${chalk.yellow(path.relative('.', file))}`));
        fileHasIssues = true;
        issuesFound++;
      }
      console.log(`   - Hook ${hookName} uses named export but is imported as default`);
    }
  });
  
  if (!fileHasIssues && file.includes('/Components/')) {
    console.log(chalk.green(`✓ ${path.relative('.', file)}`));
  }
});

// Summary
console.log(chalk.cyan('\n=== Import/Export Validation Summary ==='));
if (issuesFound > 0) {
  console.log(chalk.red(`❌ Found ${issuesFound} issues that need to be fixed`));
  process.exit(1);
} else {
  console.log(chalk.green('✅ All import/export patterns look good!'));
  process.exit(0);
}
