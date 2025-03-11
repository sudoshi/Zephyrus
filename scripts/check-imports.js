#!/usr/bin/env node

/**
 * Script to check import patterns in JavaScript files
 * This script validates that imports match the export patterns of the imported files
 */

import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';

// Get current directory in ES modules
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Process files passed as arguments
const filesToCheck = process.argv.slice(2);

let hasErrors = false;

// Helper to find the actual file path from an import path
function resolveImportPath(importPath, currentFilePath) {
  // Handle @ alias (resources/js)
  if (importPath.startsWith('@/')) {
    importPath = path.join(process.cwd(), 'resources/js', importPath.substring(2));
  } else if (importPath.startsWith('./') || importPath.startsWith('../')) {
    importPath = path.join(path.dirname(currentFilePath), importPath);
  } else {
    // External package, we can't check these
    return null;
  }

  // Try different extensions if not specified
  const extensions = ['.js', '.jsx', '.ts', '.tsx'];
  
  // If path already has an extension
  if (extensions.some(ext => importPath.endsWith(ext))) {
    return fs.existsSync(importPath) ? importPath : null;
  }
  
  // Try adding extensions
  for (const ext of extensions) {
    const pathWithExt = `${importPath}${ext}`;
    if (fs.existsSync(pathWithExt)) {
      return pathWithExt;
    }
  }
  
  return null;
}

// Check if a file has default exports
function hasDefaultExport(filePath) {
  if (!fs.existsSync(filePath)) return false;
  
  const content = fs.readFileSync(filePath, 'utf8');
  return content.includes('export default');
}

// Check if a file has a specific named export
function hasNamedExport(filePath, exportName) {
  if (!fs.existsSync(filePath)) return false;
  
  const content = fs.readFileSync(filePath, 'utf8');
  return content.includes(`export const ${exportName}`) || 
         content.includes(`export function ${exportName}`) ||
         content.includes(`export let ${exportName}`) ||
         content.includes(`export var ${exportName}`);
}

filesToCheck.forEach(filePath => {
  const content = fs.readFileSync(filePath, 'utf8');
  
  // Find all import statements
  const importRegex = /import\s+(?:{([^}]+)}|([^;{]+))\s+from\s+['"]([^'"]+)['"]/g;
  let match;
  
  while ((match = importRegex.exec(content)) !== null) {
    const namedImports = match[1]; // {Component1, Component2}
    const defaultImport = match[2]; // DefaultComponent
    const importPath = match[3]; // '@/path/to/module'
    
    // Skip node_modules imports
    if (!importPath.startsWith('@/') && !importPath.startsWith('./') && !importPath.startsWith('../')) {
      continue;
    }
    
    const resolvedPath = resolveImportPath(importPath, filePath);
    
    if (!resolvedPath) {
      console.error(`❌ Error in ${filePath}: Cannot resolve import path: ${importPath}`);
      hasErrors = true;
      continue;
    }
    
    // Check default imports
    if (defaultImport && !defaultImport.includes('as')) {
      if (!hasDefaultExport(resolvedPath)) {
        console.error(`❌ Error in ${filePath}: Default import used but no default export found in ${resolvedPath}`);
        console.error(`   Import statement: import ${defaultImport} from '${importPath}'`);
        console.error(`   Fix: Use named import instead: import { ${defaultImport} } from '${importPath}'`);
        hasErrors = true;
      }
    }
    
    // Check named imports
    if (namedImports) {
      const importNames = namedImports.split(',').map(name => name.trim().split(' as ')[0].trim());
      
      for (const importName of importNames) {
        if (!hasNamedExport(resolvedPath, importName)) {
          console.error(`❌ Error in ${filePath}: Named import '${importName}' not found as export in ${resolvedPath}`);
          console.error(`   Import statement contains: { ${namedImports} }`);
          
          // Check if it might be a default export
          if (hasDefaultExport(resolvedPath)) {
            console.error(`   Fix: The module has a default export. Use: import ${importName} from '${importPath}'`);
          } else {
            console.error(`   Fix: Check the export name in the source file and update your import accordingly`);
          }
          
          hasErrors = true;
        }
      }
    }
  }
  
  if (!hasErrors) {
    console.log(`✅ ${filePath}: Imports look good!`);
  }
});

process.exit(hasErrors ? 1 : 0);
