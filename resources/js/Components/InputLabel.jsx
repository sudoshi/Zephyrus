export default function InputLabel({
    value,
    className = '',
    children,
    ...props
}) {
    return (
        <label
            {...props}
            className={
                `block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark ` +
                className
            }
        >
            {value ? value : children}
        </label>
    );
}
