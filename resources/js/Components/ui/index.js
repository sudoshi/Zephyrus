// Export theme providers
export { FlowbiteThemeProvider } from './FlowbiteThemeProvider';
export { 
  NivoThemeProvider, 
  useHealthcareColorSchemes,
  healthcareColorSchemesLight,
  healthcareColorSchemesDark
} from './NivoThemeProvider';

// Export dark mode context and hook from AuthenticatedLayout
export { DarkModeContext, useDarkMode } from '@/Layouts/AuthenticatedLayout';

// Export all Flowbite components
export * from './flowbite';

// Export all chart components
export * from './charts';

/**
 * UI Component Library
 * 
 * This library provides a set of UI components and chart components
 * that are styled to match the healthcare theme.
 * 
 * Usage:
 * 
 * 1. Wrap your application with the theme providers:
 * 
 * ```jsx
 * import { FlowbiteThemeProvider, NivoThemeProvider } from '@/Components/ui';
 * 
 * function App() {
 *   return (
 *     <FlowbiteThemeProvider>
 *       <NivoThemeProvider>
 *         <YourApp />
 *       </NivoThemeProvider>
 *     </FlowbiteThemeProvider>
 *   );
 * }
 * ```
 * 
 * 2. Use the components in your application:
 * 
 * ```jsx
 * import { Card, Button, BarChart } from '@/Components/ui';
 * 
 * function YourComponent() {
 *   const data = [
 *     { name: 'A', value: 10 },
 *     { name: 'B', value: 20 },
 *     { name: 'C', value: 30 },
 *   ];
 * 
 *   return (
 *     <Card title="Example Card">
 *       <BarChart 
 *         data={data} 
 *         keys={['value']} 
 *         indexBy="name" 
 *         colorScheme="primary" 
 *       />
 *       <Button>Click Me</Button>
 *     </Card>
 *   );
 * }
 * ```
 */
