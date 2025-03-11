#!/usr/bin/env node

/**
 * Script to analyze and fix common import/export issues in the codebase
 * This script will:
 * 1. Find all hook files and ensure they use named exports
 * 2. Find all imports of those hooks and ensure they use the correct import syntax
 * 3. Generate a report of issues found and fixed
 */

import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';

// Get current directory in ES modules
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Configuration
const JS_ROOT = path.join(process.cwd(), 'resources', 'js');
const HOOKS_DIR = path.join(JS_ROOT, 'hooks');
const COMPONENTS_DIR = path.join(JS_ROOT, 'Components');
const DRY_RUN = process.argv.includes('--dry-run');

// Stats
const stats = {
  hooksAnalyzed: 0,
  hooksFixed: 0,
  filesWithImportsAnalyzed: 0,
  importsFixed: 0
};

// Colors for console output
const colors = {
  reset: '\x1b[0m',
  red: '\x1b[31m',
  green: '\x1b[32m',
  yellow: '\x1b[33m',
  blue: '\x1b[34m',
  magenta: '\x1b[35m',
  cyan: '\x1b[36m'
};

console.log(`${colors.cyan}Zephyrus Import/Export Fixer${colors.reset}`);
console.log(`${colors.cyan}=============================${colors.reset}`);

if (DRY_RUN) {
  console.log(`${colors.yellow}Running in DRY RUN mode - no changes will be made${colors.reset}\n`);
}

// Step 1: Find all hook files and analyze their export pattern
console.log(`${colors.blue}Step 1: Analyzing hooks export patterns...${colors.reset}`);

// Get all hook files
const getHookFiles = () => {
  try {
    const files = execSync(`find ${HOOKS_DIR} -name "use*.js" -type f`, { encoding: 'utf8' });
    return files.trim().split('\n').filter(Boolean);
  } catch (error) {
    console.error(`${colors.red}Error finding hook files:${colors.reset}`, error.message);
    return [];
  }
};

const hookFiles = getHookFiles();
console.log(`Found ${hookFiles.length} hook files`);

// Check and fix hook exports
hookFiles.forEach(filePath => {
  stats.hooksAnalyzed++;
  const content = fs.readFileSync(filePath, 'utf8');
  const filename = path.basename(filePath);
  const hookName = path.basename(filePath, path.extname(filePath));
  
  // Check if the hook is using named exports
  const hasNamedExport = content.includes(`export const ${hookName}`);
  const hasDefaultExport = content.includes('export default');
  
  if (!hasNamedExport && hasDefaultExport) {
    console.log(`${colors.yellow}⚠️ Hook using default export:${colors.reset} ${filePath}`);
    
    // Fix: Convert default export to named export
    let newContent;
    
    if (content.includes(`export default function ${hookName}`)) {
      // Case: export default function useHook() { ... }
      newContent = content.replace(
        `export default function ${hookName}`,
        `export const ${hookName} = function`
      );
    } else if (content.includes(`export default ${hookName}`)) {
      // Case: const useHook = () => { ... }; export default useHook;
      newContent = content.replace(
        `export default ${hookName}`,
        `export const ${hookName} = ${hookName}`
      ).replace(
        `const ${hookName}`, 
        `const ${hookName}Impl`
      ).replace(
        `export const ${hookName} = ${hookName}`,
        `export const ${hookName} = ${hookName}Impl`
      );
    } else if (content.includes('export default (')) {
      // Case: export default (props) => { ... }
      newContent = content.replace(
        'export default (',
        `export const ${hookName} = (`
      );
    } else {
      console.log(`${colors.red}❌ Could not automatically fix export in:${colors.reset} ${filePath}`);
      console.log(`   Manual review required`);
      return;
    }
    
    if (!DRY_RUN) {
      fs.writeFileSync(filePath, newContent);
      stats.hooksFixed++;
      console.log(`${colors.green}✅ Fixed export in:${colors.reset} ${filePath}`);
    } else {
      console.log(`${colors.green}✅ Would fix export in:${colors.reset} ${filePath} (dry run)`);
    }
  } else if (hasNamedExport && hasDefaultExport) {
    console.log(`${colors.yellow}⚠️ Hook has both named and default exports:${colors.reset} ${filePath}`);
    console.log(`   Manual review recommended`);
  } else if (!hasNamedExport && !hasDefaultExport) {
    console.log(`${colors.red}❌ No exports found in hook file:${colors.reset} ${filePath}`);
  } else {
    console.log(`${colors.green}✓ Hook export looks good:${colors.reset} ${filePath}`);
  }
});

// Step 2: Find all files that import hooks and fix their import statements
console.log(`\n${colors.blue}Step 2: Analyzing and fixing hook imports...${colors.reset}`);

// Get all JS/JSX files that might import hooks
const getJsFiles = () => {
  try {
    const files = execSync(`find ${JS_ROOT} -name "*.js" -o -name "*.jsx" | grep -v "node_modules"`, { encoding: 'utf8' });
    return files.trim().split('\n').filter(Boolean);
  } catch (error) {
    console.error(`${colors.red}Error finding JS files:${colors.reset}`, error.message);
    return [];
  }
};

const jsFiles = getJsFiles();
console.log(`Found ${jsFiles.length} JavaScript/JSX files to check`);

// Map of hook names to their correct import pattern
const hookImportMap = {};

// Build a map of hook names to their correct import pattern
hookFiles.forEach(filePath => {
  const content = fs.readFileSync(filePath, 'utf8');
  const hookName = path.basename(filePath, path.extname(filePath));
  const relativePath = path.relative(JS_ROOT, filePath).replace(/\.js$/, '');
  const importPath = `@/${relativePath}`;
  
  const hasNamedExport = content.includes(`export const ${hookName}`);
  const hasDefaultExport = content.includes('export default');
  
  if (hasNamedExport) {
    hookImportMap[hookName] = {
      pattern: 'named',
      importStatement: `import { ${hookName} } from '${importPath}';`
    };
  } else if (hasDefaultExport) {
    hookImportMap[hookName] = {
      pattern: 'default',
      importStatement: `import ${hookName} from '${importPath}';`
    };
  }
});

// Check and fix imports in all JS files
jsFiles.forEach(filePath => {
  const content = fs.readFileSync(filePath, 'utf8');
  let newContent = content;
  let fileModified = false;
  
  // Skip hook files themselves
  if (hookFiles.includes(filePath)) {
    return;
  }
  
  stats.filesWithImportsAnalyzed++;
  
  // Find all import statements
  const importRegex = /import\s+(?:{([^}]+)}|([^;{]+))\s+from\s+['"]([^'"]+)['"]/g;
  let match;
  
  while ((match = importRegex.exec(content)) !== null) {
    const namedImports = match[1]; // {Component1, Component2}
    const defaultImport = match[2]; // DefaultComponent
    const importPath = match[3]; // '@/path/to/module'
    const fullImportStatement = match[0];
    
    // Skip non-hook imports
    if (!importPath.includes('/hooks/use')) {
      continue;
    }
    
    // Extract hook name from path
    const hookName = path.basename(importPath);
    
    // Check if we know this hook
    if (hookImportMap[hookName]) {
      const correctPattern = hookImportMap[hookName].pattern;
      const correctImport = hookImportMap[hookName].importStatement;
      
      // Check if import pattern matches export pattern
      if (correctPattern === 'named' && defaultImport && !defaultImport.includes(' as ')) {
        console.log(`${colors.yellow}⚠️ Incorrect import in:${colors.reset} ${filePath}`);
        console.log(`   Current: ${fullImportStatement}`);
        console.log(`   Should be: ${correctImport}`);
        
        if (!DRY_RUN) {
          newContent = newContent.replace(fullImportStatement, correctImport);
          fileModified = true;
          stats.importsFixed++;
        } else {
          console.log(`${colors.green}✅ Would fix import in:${colors.reset} ${filePath} (dry run)`);
        }
      } else if (correctPattern === 'default' && namedImports && namedImports.includes(hookName)) {
        console.log(`${colors.yellow}⚠️ Incorrect import in:${colors.reset} ${filePath}`);
        console.log(`   Current: ${fullImportStatement}`);
        console.log(`   Should be: ${correctImport}`);
        
        if (!DRY_RUN) {
          newContent = newContent.replace(fullImportStatement, correctImport);
          fileModified = true;
          stats.importsFixed++;
        } else {
          console.log(`${colors.green}✅ Would fix import in:${colors.reset} ${filePath} (dry run)`);
        }
      }
    }
  }
  
  // Save changes if file was modified
  if (fileModified && !DRY_RUN) {
    fs.writeFileSync(filePath, newContent);
    console.log(`${colors.green}✅ Fixed imports in:${colors.reset} ${filePath}`);
  }
});

// Print summary
console.log(`\n${colors.cyan}Summary:${colors.reset}`);
console.log(`${colors.cyan}========${colors.reset}`);
console.log(`Hooks analyzed: ${stats.hooksAnalyzed}`);
console.log(`Hooks fixed: ${DRY_RUN ? '0 (dry run)' : stats.hooksFixed}`);
console.log(`Files with imports analyzed: ${stats.filesWithImportsAnalyzed}`);
console.log(`Imports fixed: ${DRY_RUN ? '0 (dry run)' : stats.importsFixed}`);

if (DRY_RUN) {
  console.log(`\n${colors.yellow}This was a dry run. Run without --dry-run to apply changes.${colors.reset}`);
}

console.log(`\n${colors.green}Done!${colors.reset}`);
