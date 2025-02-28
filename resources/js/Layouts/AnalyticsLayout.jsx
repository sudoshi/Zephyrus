import React from 'react';
import PropTypes from 'prop-types';
import { Head } from '@inertiajs/react';
import AuthenticatedLayout, { useDarkMode } from '@/Layouts/AuthenticatedLayout';
import { FlowbiteThemeProvider, NivoThemeProvider } from '@/Components/ui';
import ErrorBoundary from '@/Components/ErrorBoundary';
import { AnalyticsProvider } from '@/contexts/AnalyticsContext';

export default function AnalyticsLayout({ children, auth, title, headerButtons }) {
  return (
    <AuthenticatedLayout
      user={auth.user}
      header={
        <div className="flex items-center justify-between w-full">
          <h2 className="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{title}</h2>
          {headerButtons && <div className="flex flex-wrap gap-2 ml-auto">{headerButtons}</div>}
        </div>
      }
    >
      <Head title={title} />
      
      <div className="py-6">
        <div className="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
          <div className="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div className="p-6 text-gray-900 dark:text-gray-100">
              <ErrorBoundary>
                <AnalyticsLayoutContent>
                  {children}
                </AnalyticsLayoutContent>
              </ErrorBoundary>
            </div>
          </div>
        </div>
      </div>
    </AuthenticatedLayout>
  );
}

// This component uses the DarkModeContext from AuthenticatedLayout
function AnalyticsLayoutContent({ children }) {
  // Get dark mode state from context
  const { isDarkMode } = useDarkMode();
  
  return (
    <FlowbiteThemeProvider isDarkMode={isDarkMode}>
      <NivoThemeProvider isDarkMode={isDarkMode}>
        <AnalyticsProvider>
          {children}
        </AnalyticsProvider>
      </NivoThemeProvider>
    </FlowbiteThemeProvider>
  );
}

AnalyticsLayoutContent.propTypes = {
  children: PropTypes.node.isRequired,
};

AnalyticsLayout.propTypes = {
  children: PropTypes.node.isRequired,
  auth: PropTypes.shape({
    user: PropTypes.shape({
      id: PropTypes.number,
      name: PropTypes.string,
      email: PropTypes.string,
    }).isRequired,
  }).isRequired,
  title: PropTypes.string.isRequired,
  headerButtons: PropTypes.node,
};
