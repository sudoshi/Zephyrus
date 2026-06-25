#!/usr/bin/env node

/**
 * Script to check export patterns in JavaScript files
 * This script validates that hooks use named exports consistently
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

// Get current directory in ES modules
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Process files passed as arguments
const filesToCheck = process.argv.slice(2);

let hasErrors = false;

filesToCheck.forEach(filePath => {
  const content = fs.readFileSync(filePath, 'utf8');
  const filename = path.basename(filePath);
  const isHook = filename.startsWith('use');

  // Check for hooks (files starting with 'use')
  if (isHook) {
    // Check if the hook is using named exports
    const hasNamedExport = content.includes('export const ' + path.basename(filePath, path.extname(filePath)));
    const hasDefaultExport = content.includes('export default');

    if (!hasNamedExport && hasDefaultExport) {
      console.error(`❌ Error in ${filePath}: Hooks should use named exports, not default exports.`);
      console.error('   Change: export default useMyHook; → export const useMyHook = ...');
      hasErrors = true;
    } else if (hasNamedExport && hasDefaultExport) {
      console.warn(`⚠️ Warning in ${filePath}: Hook has both named and default exports. Consider standardizing to named exports only.`);
    } else if (!hasNamedExport && !hasDefaultExport) {
      console.error(`❌ Error in ${filePath}: No exports found in hook file.`);
      hasErrors = true;
    } else {
      console.log(`✅ ${filePath}: Exports look good!`);
    }
  }
});

process.exit(hasErrors ? 1 : 0);
