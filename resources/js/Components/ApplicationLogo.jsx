export default function ApplicationLogo({ variant = 'full', ...props }) {
    const logoSrc = variant === 'icon'
        ? '/images/zephyrus-icon.png'
        : '/images/FullLogo_Transparent.png';

    return (
        <img
            src={logoSrc}
            alt="Zephyrus"
            {...props}
        />
    );
}
