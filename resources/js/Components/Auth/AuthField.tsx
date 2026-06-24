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
    <div className="za-field">
      <label htmlFor={id}>
        {label}
        {optional && <span className="za-opt"> (optional)</span>}
      </label>
      <div className={`za-input-wrap${error ? ' za-invalid' : ''}`}>
        <span className="za-lead-icon">
          <Icon icon={icon} width="18" height="18" />
        </span>
        <input
          id={id}
          type={inputType}
          value={value}
          onChange={(e: ChangeEvent<HTMLInputElement>) => onChange(e.target.value)}
          placeholder={placeholder}
          required={required}
          autoFocus={autoFocus}
          autoComplete={autoComplete}
          className={revealable ? 'za-has-trail' : undefined}
        />
        {revealable && (
          <button
            type="button"
            tabIndex={-1}
            className="za-trail-btn"
            aria-label={revealed ? 'Hide password' : 'Show password'}
            onClick={() => setRevealed((v) => !v)}
          >
            <Icon icon={revealed ? 'lucide:eye-off' : 'lucide:eye'} width="18" height="18" />
          </button>
        )}
      </div>
      {error && <p className="za-field-error">{error}</p>}
    </div>
  );
}
