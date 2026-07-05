import { Popover } from '@headlessui/react';
import { ChevronDown } from 'lucide-react';
import type { NavDomain } from '@/config/navigationConfig';
import { MegaMenuPanel } from './MegaMenuPanel';

interface NavMegaMenuProps {
  domain: NavDomain;
  isAdmin: boolean;
  role?: string | null;
  active: boolean;
}

export function NavMegaMenu({ domain, isAdmin, role, active }: NavMegaMenuProps) {
  const Icon = domain.icon;
  return (
    <Popover>
      <Popover.Button
        aria-current={active ? 'page' : undefined}
        className={`flex items-center gap-1.5 whitespace-nowrap rounded-md border border-transparent px-3 py-1.5 text-base font-medium transition-all duration-300 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark ${
          active
            ? 'bg-healthcare-hover text-healthcare-primary dark:bg-healthcare-hover-dark dark:text-healthcare-primary-dark'
            : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
        }`}
      >
        <Icon className="h-4 w-4" />
        <span>{domain.label}</span>
        <ChevronDown className="h-3.5 w-3.5" />
      </Popover.Button>
      {/*
        `anchor` floats + portals the panel to the document body, so it is never
        clipped by the horizontally-scrolling nav row in TopNavbar. `transition`
        drives the open/close animation via data-[closed] classes (graceful no-op
        if the variant isn't configured).
      */}
      <Popover.Panel
        anchor={{ to: 'bottom start', gap: 8 }}
        transition
        className="z-[70] origin-top transition duration-100 ease-out data-[closed]:scale-95 data-[closed]:opacity-0"
      >
        {({ close }) => <MegaMenuPanel domain={domain} isAdmin={isAdmin} role={role} onNavigate={close} />}
      </Popover.Panel>
    </Popover>
  );
}
