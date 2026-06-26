export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            className={
                `inline-flex items-center rounded-md border border-transparent bg-healthcare-primary dark:bg-healthcare-primary-dark px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-healthcare-primary-hover dark:hover:bg-healthcare-primary-hover-dark focus:bg-healthcare-primary-hover dark:focus:bg-healthcare-primary-hover-dark focus:outline-none focus:ring-2 focus:ring-healthcare-primary focus:ring-offset-2 active:bg-healthcare-primary-hover dark:active:bg-healthcare-primary-hover-dark ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
