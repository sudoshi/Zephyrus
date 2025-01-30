import React from 'react';

const Card = ({ children, className = '' }) => {
    return (
        <div className={`rounded-lg bg-white shadow-sm ${className}`}>
            {children}
        </div>
    );
};

Card.Header = ({ children, className = '' }) => {
    return (
        <div className={`border-b border-gray-200 p-6 ${className}`}>
            {children}
        </div>
    );
};

Card.Title = ({ children, className = '' }) => {
    return (
        <h3 className={`text-lg font-medium leading-6 text-gray-900 ${className}`}>
            {children}
        </h3>
    );
};

Card.Description = ({ children, className = '' }) => {
    return (
        <p className={`mt-1 text-sm text-gray-500 ${className}`}>
            {children}
        </p>
    );
};

Card.Content = ({ children, className = '' }) => {
    return (
        <div className={`p-6 ${className}`}>
            {children}
        </div>
    );
};

Card.Item = ({ title, subtitle, meta, className = '' }) => {
    return (
        <div className={`flex items-center justify-between rounded-md p-4 ${className}`}>
            <div>
                <h4 className="font-medium text-gray-900">{title}</h4>
                {subtitle && <p className="text-sm text-gray-500">{subtitle}</p>}
            </div>
            {meta && <div>{meta}</div>}
        </div>
    );
};

export default Card;
