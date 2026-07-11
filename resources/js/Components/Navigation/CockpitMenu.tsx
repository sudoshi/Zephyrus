import { Popover } from '@headlessui/react';
import { ChevronDown, Gauge } from 'lucide-react';
import { RoleSwitcher } from '@/Components/CommandCenter/RoleSwitcher';
import { ScopePicker } from '@/Components/cockpit/ScopePicker';

interface CockpitMenuProps {
  active: boolean;
  scopeToken: string | null;
}

/**
 * Cockpit owns its view controls. Command and Executive are persona views;
 * Service Line is a real mount scope, selected from the same governed scope
 * catalog used by the cockpit header rather than represented by a fake persona.
 */
export function CockpitMenu({ active, scopeToken }: CockpitMenuProps) {
  return (
    <Popover>
      <Popover.Button
        aria-current={active ? 'page' : undefined}
        className={`flex items-center gap-1.5 whitespace-nowrap rounded-md border border-transparent px-3 py-1.5 text-sm font-medium transition-colors hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark ${
          active
            ? 'bg-healthcare-hover text-healthcare-primary dark:bg-healthcare-hover-dark dark:text-healthcare-primary-dark'
            : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
        }`}
      >
        <Gauge className="h-4 w-4" aria-hidden="true" />
        <span>Cockpit</span>
        <ChevronDown className="h-3.5 w-3.5" aria-hidden="true" />
      </Popover.Button>
      <Popover.Panel
        anchor={{ to: 'bottom start', gap: 8 }}
        transition
        className="z-[70] w-64 origin-top rounded-lg border border-healthcare-border bg-healthcare-surface p-3 shadow-xl transition duration-100 ease-out data-[closed]:scale-95 data-[closed]:opacity-0 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
      >
        <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Cockpit views
        </p>
        <div className="mt-2">
          <RoleSwitcher />
        </div>
        <div className="mt-3 border-t border-healthcare-border pt-3 dark:border-healthcare-border-dark">
          <label className="block text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Service Line
            <ScopePicker activeToken={scopeToken} className="mt-1.5 w-full" />
          </label>
        </div>
      </Popover.Panel>
    </Popover>
  );
}
