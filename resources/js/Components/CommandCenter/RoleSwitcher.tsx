// resources/js/Components/CommandCenter/RoleSwitcher.tsx
import { useCommandCenterStore, type CommandRole } from '@/stores/commandCenterStore';

const ROLES: { value: CommandRole; label: string }[] = [
  { value: 'command', label: 'Command' },
  { value: 'executive', label: 'Executive' },
  { value: 'service-line', label: 'Service Line' },
];

export function RoleSwitcher() {
  const role = useCommandCenterStore((s) => s.role);
  const setRole = useCommandCenterStore((s) => s.setRole);

  return (
    <div role="tablist" aria-label="Dashboard view"
         className="inline-flex rounded-md p-0.5
                    bg-healthcare-background dark:bg-healthcare-background-dark
                    border border-healthcare-border dark:border-healthcare-border-dark
                    transition-colors duration-300">
      {ROLES.map((r) => {
        const active = r.value === role;
        const comingSoon = r.value === 'service-line';
        return (
          <button key={r.value} type="button" role="tab" aria-selected={active}
                  onClick={() => setRole(r.value)}
                  title={comingSoon ? 'Service Line scoping — coming soon' : undefined}
                  className={[
                    'rounded px-3 py-1 text-xs transition-colors duration-300',
                    active
                      ? 'bg-healthcare-surface dark:bg-healthcare-surface-dark shadow-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                      : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark',
                    comingSoon ? 'opacity-60' : '',
                  ].join(' ')}>
            {r.label}
          </button>
        );
      })}
    </div>
  );
}
