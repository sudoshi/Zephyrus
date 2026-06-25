# Zephyrus Coding Standards

## JavaScript/React Import & Export Standards

### Export Patterns

Choose **one** of the following export patterns for your modules and use it consistently:

#### Option 1: Named Exports (Recommended)

```javascript
// Export individual items
export const MyComponent = () => { /* ... */ };
export const useMyHook = () => { /* ... */ };
export function myUtilityFunction() { /* ... */ }

// Import syntax
import { MyComponent, useMyHook, myUtilityFunction } from '@/path/to/module';
```

#### Option 2: Default Exports

```javascript
// Export a single item as default
const MyComponent = () => { /* ... */ };
export default MyComponent;

// Import syntax
import MyComponent from '@/path/to/module';
```

### Rules to Follow

1. **Be consistent with export types**:
   - For hooks: Use named exports with the prefix `use`
   - For components: Use named exports for multiple components in a file, default exports for single-component files
   - For utility functions: Always use named exports

2. **Import paths**:
   - Always use the `@` alias for imports from the resources/js directory
   - Do not include file extensions in import paths (e.g., `@/hooks/useMyHook` not `@/hooks/useMyHook.js`)

3. **Import organization**:
   - Group imports by type: React/libraries, components, hooks, utilities
   - Sort imports alphabetically within each group

### Example

```javascript
// Good example
import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import { motion } from 'framer-motion';

import ErrorBoundary from '@/Components/ErrorBoundary';
import Panel from '@/Components/ui/Panel';
import TabNavigation from '@/Components/ui/TabNavigation';

import { useAnalytics } from '@/Contexts/AnalyticsContext';
import { usePatientFlowData } from '@/hooks/usePatientFlowData';

import { formatDate } from '@/utils/dateUtils';
import { getChartTheme } from '@/utils/chartTheme';
```

## Chart Styling Standards

All charts in analytics dashboards should follow these guidelines:

1. **Chart Theme**: 
   - Use the shared `getChartTheme` utility from `@/utils/chartTheme.js`
   - Ensure white text for all labels, legends, and tooltips
   - Use grid lines with increased opacity (0.4) and thickness

2. **Chart Containers**:
   - Use dark gray background (`bg-gray-900`)
   - Include padding (`p-4`) and rounded corners (`rounded-lg`)

3. **Dark Mode Support**:
   - Adapt to dark mode using the `useDarkMode` hook
   - Initialize chart theme with current dark mode state

4. **Panel Titles**:
   - Center all panel titles for visual consistency
   - Use `isSubpanel` and `dropLightIntensity` props for visual hierarchy
