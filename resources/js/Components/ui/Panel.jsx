import React from 'react';
import { Surface } from '@/Components/ui/Surface';

// Title/header convenience wrapper over the single canonical surface (Surface).
// Previously rolled its own bg-white/bg-gray-800 + drop-light gradient surface;
// it now renders the canon treatment so all 40+ importers are consistent with
// every other card/panel in the app. `isSubpanel` / `dropLightIntensity` are
// retained as accepted (no-op) props for backward compatibility — there is now
// one surface treatment, not three intensity variants.
const Panel = ({
  children,
  title,
  isSubpanel = false,
  dropLightIntensity = 'medium',
  className = "",
  titleClassName = "",
  headerRight = null,
  headerContent = null,
}) => {
  return (
    <Surface className={`p-4 ${className}`}>
      {title && (
        <div className="flex justify-between items-center mb-3">
          <h2 className={`text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark ${titleClassName}`}>
            {title}
          </h2>
          {headerRight && (
            <div className="flex items-center">
              {headerRight}
            </div>
          )}
        </div>
      )}

      {headerContent && (
        <div className="mb-4">
          {headerContent}
        </div>
      )}
      {children}
    </Surface>
  );
};

export default Panel;
