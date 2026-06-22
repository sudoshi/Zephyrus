// resources/js/Components/CommandCenter/RoleSwitcher.tsx
import { useCommandCenterStore, type CommandRole } from '@/stores/commandCenterStore';

const ROLES: { value: CommandRole; label: string }[] = [
  { value: 'command', label: 'Command' },
  { value: 'executive', label: 'Executive' },
  { value: 'service-line', label: 'Service-line' },
];

export function RoleSwitcher() {
  const role = useCommandCenterStore((s) => s.role);
  const setRole = useCommandCenterStore((s) => s.setRole);

  return (
    <div role="tablist" aria-label="Dashboard view" className="inline-flex rounded-md p-0.5"
         style={{ background: 'var(--surface-raised)' }}>
      {ROLES.map((r) => {
        const active = r.value === role;
        return (
          <button key={r.value} type="button" role="tab" aria-selected={active}
                  onClick={() => setRole(r.value)}
                  className="rounded px-3 py-1 text-xs"
                  style={{
                    background: active ? 'var(--surface-elevated)' : 'transparent',
                    color: active ? 'var(--text-primary)' : 'var(--text-muted)',
                  }}>
            {r.label}
          </button>
        );
      })}
    </div>
  );
}
