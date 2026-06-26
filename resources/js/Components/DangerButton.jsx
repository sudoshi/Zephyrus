export default function DangerButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            className={
                `inline-flex items-center rounded-md border border-transparent bg-healthcare-critical dark:bg-healthcare-critical-dark px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-healthcare-critical-dark dark:hover:bg-healthcare-critical focus:outline-none focus:ring-2 focus:ring-healthcare-critical focus:ring-offset-2 active:bg-healthcare-critical-dark dark:active:bg-healthcare-critical ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
