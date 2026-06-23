import type { ReactNode } from 'react';

export interface AuthFormPanelProps {
  children: ReactNode;
}

export function AuthFormPanel({ children }: AuthFormPanelProps) {
  return (
    <div className="relative w-full max-w-[420px]">
      {/* Shimmer border — rotating conic gradient behind a clipped panel */}
      <div className="pointer-events-none absolute -inset-[2px] z-0 overflow-hidden rounded-[26px]">
        <div
          className="auth-shimmer__spin absolute -inset-1/2"
          style={{
            background:
              'conic-gradient(from 0deg, transparent 0%, transparent 20%,'
              + 'rgba(99,102,241,.6) 28%, rgba(99,102,241,.2) 35%, transparent 42%,'
              + 'transparent 55%, rgba(6,182,212,.55) 62%, rgba(6,182,212,.15) 70%,'
              + 'transparent 78%, transparent 100%)',
            animation: 'auth-shimmer-rotate 6s linear infinite',
          }}
        />
        <div className="absolute inset-[2px] rounded-[24px] bg-[#0b1120]/95" />
      </div>

      {/* Inner glass panel */}
      <div className="relative z-10 rounded-[24px] border border-white/[0.06] bg-[#0b1120]/65 p-7 backdrop-blur-2xl sm:p-8">
        {children}
      </div>
    </div>
  );
}
