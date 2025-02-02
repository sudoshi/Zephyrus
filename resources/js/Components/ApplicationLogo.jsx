export default function ApplicationLogo({ variant = 'full', ...props }) {
    const logoSrc = variant === 'icon' 
        ? '/images/IconOnly_Transparent.png'
        : '/images/FullLogo_Transparent.png';

    return (
        <img
            src={logoSrc}
            alt="OR Analytics Platform Logo"
            {...props}
        />
    );
}
