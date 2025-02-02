import React from 'react';

const PageContentLayout = ({ title, subtitle, children }) => {
  return (
    <div className="p-6 max-w-full overflow-x-hidden">
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
          {title}
        </h1>
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
          {subtitle}
        </p>
      </div>
      {children}
    </div>
  );
};

export default PageContentLayout;
