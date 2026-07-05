// resources/js/Components/CommandCenter/RoleSwitcher.tsx
import { useRef, type KeyboardEvent } from 'react';
import { useCommandCenterStore, type CommandRole } from '@/stores/commandCenterStore';

interface RoleOption {
  value: CommandRole;
  label: string;
  disabled?: boolean;
}

const ROLES: RoleOption[] = [
  { value: 'command', label: 'Command' },
  { value: 'executive', label: 'Executive' },
  // Service line is a SCOPE, not a persona (P8 spec §P8: "activates it as a real
  // scope"). The real, working mechanism is the mount scope picker's Service
  // lines group (?scope=service_line:*) — a persona tab would only duplicate
  // command's layout. The slot stays reserved here to keep the IA intact.
  { value: 'service-line', label: 'Service Line', disabled: true },
];

export function RoleSwitcher() {
  const role = useCommandCenterStore((s) => s.role);
  const setRole = useCommandCenterStore((s) => s.setRole);
  const tabsRef = useRef<(HTMLButtonElement | null)[]>([]);

  // ARIA tablist roving-tabindex keyboard support: arrow keys move between the
  // selectable tabs (skipping disabled ones), Home/End jump to the ends.
  const selectableIdx = ROLES.map((r, i) => (r.disabled ? -1 : i)).filter((i) => i >= 0);

  const focusRole = (index: number) => {
    setRole(ROLES[index].value);
    tabsRef.current[index]?.focus();
  };

  const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    const activeIndex = ROLES.findIndex((r) => r.value === role);
    const pos = selectableIdx.indexOf(activeIndex);
    if (pos === -1) return;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
      e.preventDefault();
      focusRole(selectableIdx[(pos + 1) % selectableIdx.length]);
    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
      e.preventDefault();
      focusRole(selectableIdx[(pos - 1 + selectableIdx.length) % selectableIdx.length]);
    } else if (e.key === 'Home') {
      e.preventDefault();
      focusRole(selectableIdx[0]);
    } else if (e.key === 'End') {
      e.preventDefault();
      focusRole(selectableIdx[selectableIdx.length - 1]);
    }
  };

  return (
    <div role="tablist" aria-label="Dashboard view" onKeyDown={onKeyDown}
         className="inline-flex rounded-md p-0.5
                    bg-healthcare-background dark:bg-healthcare-background-dark
                    border border-healthcare-border dark:border-healthcare-border-dark
                    transition-colors duration-300">
      {ROLES.map((r, i) => {
        const active = r.value === role;
        return (
          <button key={r.value} type="button" role="tab"
                  ref={(el) => { tabsRef.current[i] = el; }}
                  aria-selected={active}
                  aria-disabled={r.disabled || undefined}
                  disabled={r.disabled}
                  // Roving tabindex: only the active tab is in the tab order.
                  tabIndex={active ? 0 : -1}
                  onClick={() => !r.disabled && setRole(r.value)}
                  title={r.disabled ? 'Service line is a scope — mount one from the scope picker' : undefined}
                  className={[
                    'rounded px-3 py-1 text-xs transition-colors duration-300',
                    active
                      ? 'bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                      : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark',
                    r.disabled ? 'cursor-not-allowed opacity-50' : '',
                  ].join(' ')}>
            {r.label}
            {r.disabled ? <span className="ml-1 text-xs uppercase tracking-wide opacity-70">soon</span> : null}
          </button>
        );
      })}
    </div>
  );
}
