import React from 'react';
import { Surface } from '@/Components/ui/Surface';

// Card root delegates to the single canonical surface primitive (Surface) so
// the gold-standard treatment is defined in exactly one place. The Header /
// Title / Description / Content / Item sub-components below are layout helpers.
const Card = ({ children, className = '' }) => {
    return (
        <Surface className={className}>
            {children}
        </Surface>
    );
};

Card.Header = ({ children, className = '' }) => {
    return (
        <div
            className={`
                border-b border-healthcare-border dark:border-healthcare-border-dark
                p-4 transition-colors duration-300
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
                text-base font-semibold leading-6
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
        <div className={`p-4 overflow-x-auto ${className}`}>
            {children}
        </div>
    );
};

Card.Item = ({ title, subtitle, meta, className = '' }) => {
    return (
        <div
            className={`
                flex items-center justify-between rounded-md p-3
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
