export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-healthcare-border dark:border-healthcare-border-dark text-healthcare-primary shadow-sm focus:ring-healthcare-primary ' +
                className
            }
        />
    );
}
