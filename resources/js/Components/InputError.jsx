export default function InputError({ message, className = '', ...props }) {
    return message ? (
        <p
            {...props}
            className={'text-sm text-healthcare-critical dark:text-healthcare-critical-dark ' + className}
        >
            {message}
        </p>
    ) : null;
}
