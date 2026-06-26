export default function ApplicationLogo({ variant: _variant = 'full', ...props }) {
    // Square brand icon retired (2026-06-26) — always render the full wordmark logo.
    return (
        <img
            src="/images/FullLogo_Transparent.png"
            alt="Zephyrus"
            {...props}
        />
    );
}
