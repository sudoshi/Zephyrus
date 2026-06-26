export default function SecondaryButton({
    type = 'button',
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            type={type}
            className={
                `inline-flex items-center rounded-md border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark px-4 py-2 text-xs font-semibold uppercase tracking-widest text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark shadow-sm transition duration-150 ease-in-out hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark focus:outline-none focus:ring-2 focus:ring-healthcare-primary focus:ring-offset-2 disabled:opacity-25 ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
