import { Fragment } from 'react';
import { Link, router } from '@inertiajs/react';
import { Menu, Transition } from '@headlessui/react';
import { ChevronDown, LogOut, User, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import UserAvatar from '@/Components/UserAvatar';

export interface UserMenuItem {
  readonly label: string;
  readonly icon: LucideIcon;
  readonly href?: string;
  readonly action?: 'logout';
}

export function getUserMenuItems(isAdmin: boolean): UserMenuItem[] {
  const items: UserMenuItem[] = [{ label: 'Profile', icon: User, href: '/profile' }];
  if (isAdmin) {
    items.push({ label: 'User Management', icon: Users, href: '/users' });
  }
  items.push({ label: 'Logout', icon: LogOut, action: 'logout' });
  return items;
}

interface UserMenuProps {
  isAdmin: boolean;
}

export function UserMenu({ isAdmin }: UserMenuProps) {
  const items = getUserMenuItems(isAdmin);

  return (
    <Menu as="div" className="relative z-[75]">
      <Menu.Button className="flex items-center space-x-2 rounded-md border border-transparent p-2 transition-all duration-300 hover:border-healthcare-border hover:bg-healthcare-hover dark:hover:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark">
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
        <Menu.Items className="absolute right-0 z-[70] mt-2 w-48 rounded-lg border border-healthcare-border bg-healthcare-surface py-1 shadow-lg dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          {items.map((item) => (
            <Menu.Item key={item.label}>
              {({ active }) => {
                const Icon = item.icon;
                const className = `flex w-full items-center px-4 py-2 text-base/[18px] transition-all duration-300 ${
                  active
                    ? 'bg-healthcare-hover text-healthcare-text-primary dark:bg-healthcare-hover-dark dark:text-healthcare-text-primary-dark'
                    : 'text-healthcare-text-secondary hover:bg-healthcare-hover/50 dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark/50'
                }`;
                if (item.action === 'logout') {
                  return (
                    <button type="button" onClick={() => router.post('/logout')} className={className}>
                      <Icon className="mr-2 h-4 w-4" />
                      {item.label}
                    </button>
                  );
                }
                return (
                  <Link href={item.href as string} className={className}>
                    <Icon className="mr-2 h-4 w-4" />
                    {item.label}
                  </Link>
                );
              }}
            </Menu.Item>
          ))}
        </Menu.Items>
      </Transition>
    </Menu>
  );
}
