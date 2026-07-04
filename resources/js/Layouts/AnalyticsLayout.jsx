// resources/js/Layouts/AnalyticsLayout.jsx
//
// P4b: thin chrome wrapper over the unified DashboardLayout shell (same
// pattern as RTDCPageLayout / TransportLayout). Keeps the Analytics-specific
// chrome — page title + header buttons, the surface card, ErrorBoundary, and
// the Flowbite/Nivo/Analytics providers. The theme providers read the
// shell-level DarkModeContext themselves, so no local dark-mode plumbing.
import React from 'react';
import PropTypes from 'prop-types';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { FlowbiteThemeProvider, NivoThemeProvider } from '@/Components/ui';
import ErrorBoundary from '@/Components/ErrorBoundary';
import { AnalyticsProvider } from '@/Contexts/AnalyticsContext';

export default function AnalyticsLayout({ children, title, headerButtons }) {
  return (
    <DashboardLayout>
      <Head title={title} />
      <PageContentLayout
        title={title}
        headerContent={
          headerButtons ? (
            <div className="flex flex-wrap gap-2 justify-end">{headerButtons}</div>
          ) : null
        }
      >
        <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark overflow-hidden shadow-sm rounded-lg">
          <div className="p-4 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            <ErrorBoundary>
              <FlowbiteThemeProvider>
                <NivoThemeProvider>
                  <AnalyticsProvider>
                    {children}
                  </AnalyticsProvider>
                </NivoThemeProvider>
              </FlowbiteThemeProvider>
            </ErrorBoundary>
          </div>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}

AnalyticsLayout.propTypes = {
  children: PropTypes.node.isRequired,
  // Accepted for page-signature compatibility; the unified shell reads auth
  // from Inertia shared props itself.
  auth: PropTypes.object,
  title: PropTypes.string.isRequired,
  headerButtons: PropTypes.node,
};
