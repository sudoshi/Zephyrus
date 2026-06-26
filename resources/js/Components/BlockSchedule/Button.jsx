import React from 'react';

const Button = ({ children, variant = 'primary', className = '', ...props }) => {
    const baseClasses = 'inline-flex items-center justify-center px-4 py-2 border text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2';
    
    const variantClasses = {
        primary: 'border-transparent text-white bg-healthcare-primary hover:bg-healthcare-primary-hover dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary-hover-dark focus:ring-healthcare-primary',
        secondary: 'border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark bg-healthcare-surface dark:bg-healthcare-surface-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark focus:ring-healthcare-primary'
    };

    return (
        <button
            className={`${baseClasses} ${variantClasses[variant]} ${className}`}
            {...props}
        >
            {children}
        </button>
    );
};

export default Button;
