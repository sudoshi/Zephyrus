import React from 'react';

const Form = ({ children, className = '', ...props }) => {
    return (
        <form className={className} {...props}>
            {children}
        </form>
    );
};

const Field = ({ children, className = '' }) => {
    return (
        <div className={`space-y-1 ${className}`}>
            {children}
        </div>
    );
};

const Label = ({ children, htmlFor, className = '' }) => {
    return (
        <label
            htmlFor={htmlFor}
            className={`block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark ${className}`}
        >
            {children}
        </label>
    );
};

const Input = ({ className = '', ...props }) => {
    return (
        <input
            className={`block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark shadow-sm focus:border-healthcare-primary focus:ring-healthcare-primary sm:text-sm ${className}`}
            {...props}
        />
    );
};

Form.Field = Field;
Form.Label = Label;
Form.Input = Input;

export default Form;
