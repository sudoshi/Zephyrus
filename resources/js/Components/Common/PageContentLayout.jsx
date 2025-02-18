import React from 'react';

const PageContentLayout = ({ title, subtitle, headerContent, children }) => {
  return (
    <div className="p-6">
      <div className="flex justify-between items-start mb-6">
        <div>
          <h1 className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {title}
          </h1>
          <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {subtitle}
          </p>
        </div>
        {headerContent}
      </div>
      {children}
    </div>
  );
};

export default PageContentLayout;
