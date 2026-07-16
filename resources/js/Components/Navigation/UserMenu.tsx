import { Fragment } from 'react';
import { Link, router } from '@inertiajs/react';
import { Menu, Transition } from '@headlessui/react';
import {
  Building2,
  ChevronDown,
  LayoutDashboard,
  LogOut,
  ScrollText,
  Settings,
  ShieldCheck,
  User,
  Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import UserAvatar from '@/Components/UserAvatar';
import type { NavigationAccess } from '@/config/navigationConfig';

export interface UserMenuItem {
  readonly label: string;
  readonly icon: LucideIcon;
  readonly href?: string;
  readonly action?: 'logout';
  readonly section: 'account' | 'administration';
}

export function getUserMenuItems(access: NavigationAccess): UserMenuItem[] {
  const items: UserMenuItem[] = [
    { label: 'Profile', icon: User, href: '/profile', section: 'account' },
  ];
  if (access.isAdmin || access.can?.view_administration) {
    items.push({
      label: 'Administration Overview',
      icon: LayoutDashboard,
      href: '/admin',
      section: 'administration',
    });
  }
  if (access.isAdmin || access.can?.view_user_audit) {
    items.push({
      label: 'User Audit',
      icon: ScrollText,
      href: '/admin/user-audit',
      section: 'administration',
    });
  }
  if (access.isAdmin || access.can?.view_access_reviews) {
    items.push({
      label: 'Access Reviews',
      icon: ShieldCheck,
      href: '/admin/access-reviews',
      section: 'administration',
    });
  }
  if (access.isAdmin) {
    items.push(
      { label: 'User Management', icon: Users, href: '/users', section: 'administration' },
      {
        label: 'Cockpit Thresholds',
        icon: Settings,
        href: '/admin/cockpit/thresholds',
        section: 'administration',
      },
    );
  }
  if (access.can?.view_enterprise_setup) {
    items.push({
      label: 'Enterprise Setup',
      icon: Building2,
      href: '/admin/enterprise-setup',
      section: 'administration',
    });
  }
  items.push({ label: 'Logout', icon: LogOut, action: 'logout', section: 'account' });
  return items;
}

interface UserMenuProps {
  access: NavigationAccess;
}

export function UserMenu({ access }: UserMenuProps) {
  const items = getUserMenuItems(access);
  let renderedAdministrationHeading = false;

  return (
    <Menu as="div" className="relative z-[75]">
      <Menu.Button
        aria-label="User menu"
        className="flex items-center space-x-2 rounded-md border border-transparent p-2 transition-all duration-300 hover:border-healthcare-border hover:bg-healthcare-hover dark:hover:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark"
      >
        <UserAvatar />
        <ChevronDown className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
      </Menu.Button>
      <Transition
        as={Fragment}
        enter="transition ease-out duration-100"
        enterFrom="transform opacity-0 scale-95"
        enterTo="transform opacity-100 scale-100"
        leave="transition ease-in duration-75"
        leaveFrom="transform opacity-100 scale-100"
        leaveTo="transform opacity-0 scale-95"
      >
        <Menu.Items className="absolute right-0 z-[70] mt-2 w-56 rounded-md border border-healthcare-border bg-healthcare-surface py-1 shadow-lg dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          {items.map((item) => {
            const showAdministrationHeading =
              item.section === 'administration' && !renderedAdministrationHeading;
            if (showAdministrationHeading) renderedAdministrationHeading = true;

            return (
              <Fragment key={item.label}>
                {showAdministrationHeading && (
                  <div className="mt-1 border-t border-healthcare-border px-4 pb-1 pt-2 text-xs font-medium uppercase text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                    Administration
                  </div>
                )}
                <Menu.Item>
                  {({ active }) => {
                    const Icon = item.icon;
                    const className = `flex w-full items-center px-4 py-2 text-sm transition-colors ${
                      active
                        ? 'bg-healthcare-hover text-healthcare-text-primary dark:bg-healthcare-hover-dark dark:text-healthcare-text-primary-dark'
                        : 'text-healthcare-text-secondary hover:bg-healthcare-hover/50 dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark/50'
                    }`;
                    if (item.action === 'logout') {
                      return (
                        <button type="button" onClick={() => router.post('/logout')} className={className}>
                          <Icon className="mr-2 h-4 w-4" aria-hidden="true" />
                          {item.label}
                        </button>
                      );
                    }
                    return (
                      <Link href={item.href as string} className={className}>
                        <Icon className="mr-2 h-4 w-4" aria-hidden="true" />
                        {item.label}
                      </Link>
                    );
                  }}
                </Menu.Item>
              </Fragment>
            );
          })}
        </Menu.Items>
      </Transition>
    </Menu>
  );
}
