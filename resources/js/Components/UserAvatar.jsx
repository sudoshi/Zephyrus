export default function UserAvatar() {
    return (
        <svg 
            width="32" 
            height="32" 
            viewBox="0 0 128 128" 
            xmlns="http://www.w3.org/2000/svg"
            className="rounded-full bg-gray-200"
        >
            <rect width="128" height="128" fill="#f1f5f9"/>
            <circle cx="64" cy="48" r="24" fill="#94a3b8"/>
            <path d="M64 80c-24 0-40 16-40 36v12h80v-12c0-20-16-36-40-36z" fill="#94a3b8"/>
        </svg>
    );
}
