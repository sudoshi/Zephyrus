import { Popover } from '@headlessui/react';
import { ChevronDown } from 'lucide-react';
import type { NavigationAccess, NavSection } from '@/config/navigationConfig';
import { SectionMenuPanel } from './SectionMenuPanel';

interface NavSectionMenuProps {
  section: NavSection;
  access: NavigationAccess;
  url: string;
  active: boolean;
}

export function NavSectionMenu({ section, access, url, active }: NavSectionMenuProps) {
  const Icon = section.icon;

  return (
    <Popover>
      <Popover.Button
        aria-current={active ? 'page' : undefined}
        className={`flex items-center gap-1.5 whitespace-nowrap rounded-md border border-transparent px-3 py-1.5 text-sm font-medium transition-colors hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark ${
          active
            ? 'bg-healthcare-hover text-healthcare-primary dark:bg-healthcare-hover-dark dark:text-healthcare-text-primary-dark'
            : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
        }`}
      >
        <Icon className="h-4 w-4" aria-hidden="true" />
        <span>{section.title}</span>
        <ChevronDown className="h-3.5 w-3.5" aria-hidden="true" />
      </Popover.Button>
      <Popover.Panel
        anchor={{ to: 'bottom start', gap: 8 }}
        transition
        className="z-[70] origin-top transition duration-100 ease-out data-[closed]:scale-95 data-[closed]:opacity-0"
      >
        {({ close }) => (
          <SectionMenuPanel section={section} access={access} url={url} onNavigate={close} />
        )}
      </Popover.Panel>
    </Popover>
  );
}
