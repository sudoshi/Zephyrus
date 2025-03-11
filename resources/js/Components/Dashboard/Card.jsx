import React from 'react';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';

const Card = ({ children, className = '' }) => {
    const [isDarkMode] = useDarkMode();
    const colors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];

    return (
        <div
            className={`
                rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark
                border border-healthcare-border dark:border-healthcare-border-dark
                shadow-sm hover:shadow-md dark:shadow-none
                dark:hover:shadow-[0_4px_12px_rgba(0,0,0,0.25)]
                transition-all duration-300
                overflow-hidden
                ${className}
            `}
        style={{
            backgroundImage: isDarkMode 
                ? 'linear-gradient(to bottom, rgba(255, 255, 255, 0.1), transparent)'
                : 'linear-gradient(to bottom, rgba(255, 255, 255, 0.5), rgba(255, 255, 255, 0))'
        }}
        >
            {children}
        </div>
    );
};

Card.Header = ({ children, className = '' }) => {
    return (
        <div
            className={`
                border-b border-healthcare-border dark:border-healthcare-border-dark
                p-6 transition-colors duration-300
                ${className}
            `}
        >
            {children}
        </div>
    );
};

Card.Title = ({ children, className = '' }) => {
    return (
        <h3
            className={`
                text-lg font-medium leading-6
                text-healthcare-text-primary dark:text-healthcare-text-primary-dark
                transition-colors duration-300
                ${className}
            `}
        >
            {children}
        </h3>
    );
};

Card.Description = ({ children, className = '' }) => {
    return (
        <p
            className={`
                mt-1 text-sm
                text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark
                transition-colors duration-300
                ${className}
            `}
        >
            {children}
        </p>
    );
};

Card.Content = ({ children, className = '' }) => {
    return (
        <div className={`p-6 overflow-x-auto ${className}`}>
            {children}
        </div>
    );
};

Card.Item = ({ title, subtitle, meta, className = '' }) => {
    return (
        <div
            className={`
                flex items-center justify-between rounded-md p-4
                hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark
                transition-colors duration-300
                ${className}
            `}
        >
            <div>
                <h4
                    className={`
                        font-medium
                        text-healthcare-text-primary dark:text-healthcare-text-primary-dark
                        transition-colors duration-300
                    `}
                >
                    {title}
                </h4>
                {subtitle && (
                    <p
                        className={`
                            text-sm
                            text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark
                            transition-colors duration-300
                        `}
                    >
                        {subtitle}
                    </p>
                )}
            </div>
            {meta && <div>{meta}</div>}
        </div>
    );
};

export default Card;
