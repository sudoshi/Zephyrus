import React from 'react';

const Panel = ({ 
  children, 
  title, 
  isSubpanel = false, 
  dropLightIntensity = 'medium', // 'subtle', 'medium', 'strong'
  className = "", 
  titleClassName = "" 
}) => {
  // Define gradient and shadow styles based on intensity
  const getDropLightStyles = () => {
    if (!isSubpanel) return '';
    
    const intensityStyles = {
      subtle: {
        light: 'from-white to-gray-50 shadow-sm',
        dark: 'dark:from-gray-800 dark:to-gray-850 dark:shadow-gray-900/10'
      },
      medium: {
        light: 'from-white to-gray-100 shadow-md',
        dark: 'dark:from-gray-800 dark:to-gray-850 dark:shadow-gray-900/20'
      },
      strong: {
        light: 'from-white to-gray-150 shadow-lg',
        dark: 'dark:from-gray-800 dark:to-gray-750 dark:shadow-gray-900/30'
      }
    };

    const style = intensityStyles[dropLightIntensity] || intensityStyles.medium;
    return `bg-gradient-to-b ${style.light} ${style.dark} border border-gray-100 dark:border-gray-700`;
  };

  return (
    <div 
      className={`
        bg-white dark:bg-gray-800 rounded-lg 
        ${isSubpanel 
          ? getDropLightStyles()
          : 'shadow'
        } 
        p-4 
        ${className}
      `}
    >
      {title && (
        <h2 className={`text-lg font-semibold mb-4 dark:text-white ${titleClassName}`}>
          {title}
        </h2>
      )}
      {children}
    </div>
  );
};

export default Panel;
