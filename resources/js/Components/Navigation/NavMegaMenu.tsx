import { Fragment } from 'react';
import { Popover, Transition } from '@headlessui/react';
import { ChevronDown } from 'lucide-react';
import type { NavDomain } from '@/config/navigationConfig';
import { MegaMenuPanel } from './MegaMenuPanel';

interface NavMegaMenuProps {
  domain: NavDomain;
  isAdmin: boolean;
  active: boolean;
}

export function NavMegaMenu({ domain, isAdmin, active }: NavMegaMenuProps) {
  const Icon = domain.icon;
  return (
    <Popover className="relative">
      <Popover.Button
        aria-current={active ? 'page' : undefined}
        className={`flex items-center gap-1.5 rounded-md border border-transparent px-3 py-1.5 text-sm font-medium transition-all duration-300 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark ${
          active
            ? 'bg-healthcare-hover text-healthcare-primary dark:bg-healthcare-hover-dark dark:text-healthcare-primary-dark'
            : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
        }`}
      >
        <Icon className="h-4 w-4" />
        <span>{domain.label}</span>
        <ChevronDown className="h-3.5 w-3.5" />
      </Popover.Button>
      <Transition
        as={Fragment}
        enter="transition ease-out duration-100"
        enterFrom="transform opacity-0 scale-95"
        enterTo="transform opacity-100 scale-100"
        leave="transition ease-in duration-75"
        leaveFrom="transform opacity-100 scale-100"
        leaveTo="transform opacity-0 scale-95"
      >
        <Popover.Panel className="absolute left-0 z-[70] mt-2">
          {({ close }) => <MegaMenuPanel domain={domain} isAdmin={isAdmin} onNavigate={close} />}
        </Popover.Panel>
      </Transition>
    </Popover>
  );
}
