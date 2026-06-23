import { useEffect, type ReactNode } from 'react';
import { motion } from 'framer-motion';
import { AuthBackground } from '@/Components/Auth/AuthBackground';
import { AuthHero } from '@/Components/Auth/AuthHero';
import { AuthFormPanel } from '@/Components/Auth/AuthFormPanel';

interface AuthLayoutProps {
  children: ReactNode;
}

export default function AuthLayout({ children }: AuthLayoutProps) {
  // Guest pages are dark-only. We add the class WITHOUT writing localStorage,
  // so the user's stored theme preference for the authenticated app is preserved
  // (the authenticated layout re-applies it on mount).
  useEffect(() => {
    document.documentElement.classList.add('dark');
  }, []);

  return (
    <div className="relative min-h-screen overflow-hidden bg-[#0a0f1f] text-slate-100">
      <AuthBackground />

      <div className="relative z-10 flex min-h-screen flex-col lg:flex-row">
        {/* Left — brand hero */}
        <div className="flex flex-1 items-center justify-center px-6 pt-10 lg:p-12">
          <AuthHero />
        </div>

        {/* Right — form panel */}
        <div className="flex flex-1 items-center justify-center px-6 pb-12 pt-6 lg:p-12">
          <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, ease: [0.16, 1, 0.3, 1], delay: 0.15 }}
            className="flex w-full justify-center"
          >
            <AuthFormPanel>{children}</AuthFormPanel>
          </motion.div>
        </div>
      </div>
    </div>
  );
}
