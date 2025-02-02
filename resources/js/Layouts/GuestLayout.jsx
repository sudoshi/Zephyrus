import { Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { useDarkMode } from '@/hooks/useDarkMode';

export default function GuestLayout({ children }) {
    const [isDarkMode] = useDarkMode();

    return (
        <div className="flex min-h-screen flex-col items-center bg-healthcare-background dark:bg-healthcare-background-dark pt-[30px] transition-colors duration-300">
            <div className="relative">
                <Link href="/" className="block">
                    <div className="relative">
                        <ApplicationLogo 
                            variant="full"
                            className="h-[300px] w-auto text-healthcare-info dark:text-healthcare-info-dark transition-colors duration-300"
                        />
                        <div className="absolute inset-0 bg-healthcare-info dark:bg-healthcare-info-dark opacity-20 dark:opacity-10 blur-lg rounded-full transition-all duration-300"></div>
                    </div>
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-healthcare-surface dark:bg-healthcare-surface-dark px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg border border-healthcare-border dark:border-healthcare-border-dark transition-all duration-300">
                {children}
            </div>
        </div>
    );
}
