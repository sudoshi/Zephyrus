import { Icon } from '@iconify/react';
import { useState, type ChangeEvent } from 'react';

export interface AuthFieldProps {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  icon: string;
  type?: 'text' | 'email' | 'tel' | 'password';
  placeholder?: string;
  autoComplete?: string;
  autoFocus?: boolean;
  required?: boolean;
  revealable?: boolean;
  optional?: boolean;
  error?: string;
}

export function AuthField({
  id, label, value, onChange, icon, type = 'text', placeholder,
  autoComplete, autoFocus, required, revealable, optional, error,
}: AuthFieldProps) {
  const [revealed, setRevealed] = useState(false);
  const inputType = revealable ? (revealed ? 'text' : 'password') : type;

  return (
    <div>
      <label htmlFor={id} className="block text-xs font-medium text-slate-400 mb-1.5">
        {label}
        {optional && <span className="text-slate-500"> (optional)</span>}
      </label>
      <div className="relative">
        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
          <Icon icon={icon} className="w-[18px] h-[18px] text-slate-500" />
        </div>
        <input
          id={id}
          type={inputType}
          value={value}
          onChange={(e: ChangeEvent<HTMLInputElement>) => onChange(e.target.value)}
          placeholder={placeholder}
          required={required}
          autoFocus={autoFocus}
          autoComplete={autoComplete}
          className={[
            'w-full rounded-xl border bg-white/[0.04] py-3 pl-11 text-sm text-slate-100',
            'placeholder-slate-500 outline-none transition-colors',
            revealable ? 'pr-11' : 'pr-4',
            error ? 'border-red-500/50' : 'border-white/10',
            'hover:border-indigo-400/60 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/25',
          ].join(' ')}
        />
        {revealable && (
          <button
            type="button"
            tabIndex={-1}
            onClick={() => setRevealed((v) => !v)}
            aria-label={revealed ? 'Hide password' : 'Show password'}
            className="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-500 hover:text-slate-300 transition-colors"
          >
            <Icon icon={revealed ? 'lucide:eye-off' : 'lucide:eye'} className="w-[18px] h-[18px]" />
          </button>
        )}
      </div>
      {error && <p className="mt-1.5 text-xs text-red-400">{error}</p>}
    </div>
  );
}
