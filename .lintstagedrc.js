export default {
  // Run ESLint on JavaScript and JSX files
  "resources/js/**/*.{js,jsx}": [
    "eslint --fix",
    "prettier --write"
  ],
  // Check for import/export issues in specific files
  "resources/js/hooks/**/*.{js,jsx}": [
    "node scripts/check-exports.js"
  ],
  "resources/js/Components/**/*.{js,jsx}": [
    "node scripts/check-imports.js"
  ]
};
