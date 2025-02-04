import React from 'react';
import { Link } from '@inertiajs/react';
import { Menu, Transition } from '@headlessui/react';
import { Fragment } from 'react';
import { Icon } from '@iconify/react';
import UserAvatar from '@/Components/UserAvatar';
import DarkModeToggle from '@/Components/Common/DarkModeToggle';
import { useDashboard } from '@/Contexts/DashboardContext';

const TopNavigation = ({ isDarkMode, setIsDarkMode }) => {
    const { currentSection, navigationItems, dashboardItems } = useDashboard();
    
    const mainNavigation = [
        {
            name: 'Dashboard',
            href: route(`dashboard.${currentSection.toLowerCase()}`),
            icon: 'heroicons:home',
            dropdownItems: dashboardItems
        },
        {
            name: 'Analytics',
            href: '#',
            icon: 'heroicons:chart-bar',
            dropdownItems: navigationItems[currentSection.toLowerCase()]?.analytics || []
        },
        {
            name: 'Operations',
            href: '#',
            icon: 'heroicons:cog-6-tooth',
            dropdownItems: navigationItems[currentSection.toLowerCase()]?.operations || []
        },
        {
            name: 'Predictions',
            href: '#',
            icon: 'heroicons:chart-bar-square',
            dropdownItems: navigationItems[currentSection.toLowerCase()]?.predictions || []
        }
    ];

    return (
        <nav className="bg-healthcare-surface dark:bg-healthcare-surface-dark border-b border-healthcare-border dark:border-healthcare-border-dark transition-colors duration-300">
            <div className="max-w-full mx-auto px-4 relative">
                <div className="flex justify-between h-16">
                    {/* Logo and Brand */}
                    <div className="flex items-center">
                        <div className="flex items-center space-x-2">
                            <div className="relative">
                                <img 
                                    src="/images/IconOnly_Transparent.png"
                                    alt="OR Analytics Platform Logo"
                                    className="h-[60px] w-auto"
                                />
                            </div>
                            <div>
                                <h2 className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                    ZephyrusOR
                                </h2>
                            </div>
                        </div>
                    </div>

                    {/* Centered Navigation Items */}
                    <div className="absolute left-1/2 top-1/2 transform -translate-x-1/2 -translate-y-1/2">
                        <div className="flex items-center space-x-8">
                            {mainNavigation.map((item) => (
                                <Menu as="div" className="relative" key={item.name}>
                                    <div className="flex items-center">
{item.name === 'Dashboard' ? (
    <Menu.Button className="flex items-center px-3 py-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark rounded-md transition-colors duration-300">
        <Icon icon={item.icon} className="w-5 h-5 mr-2" />
        {item.name}
        <Icon icon="heroicons:chevron-down" className="w-4 h-4 ml-2" />
    </Menu.Button>
) : (
                                            <Menu.Button className="flex items-center px-3 py-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark rounded-md transition-colors duration-300">
                                                <Icon icon={item.icon} className="w-5 h-5 mr-2" />
                                                {item.name}
                                                <Icon icon="heroicons:chevron-down" className="w-4 h-4 ml-2" />
                                            </Menu.Button>
                                        )}
                                    </div>
                                    <Transition
                                        as={Fragment}
                                        enter="transition ease-out duration-100"
                                        enterFrom="transform opacity-0 scale-95"
                                        enterTo="transform opacity-100 scale-100"
                                        leave="transition ease-in duration-75"
                                        leaveFrom="transform opacity-100 scale-100"
                                        leaveTo="transform opacity-0 scale-95"
                                    >
                                        <Menu.Items className="absolute right-0 mt-2 w-64 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-lg py-1 border border-healthcare-border dark:border-healthcare-border-dark">
                                            {item.dropdownItems.map((dropdownItem) => (
                                                <Menu.Item key={dropdownItem.name}>
                                                    {({ active }) => (
                                                        <Link
                                                            href={dropdownItem.href}
                                                            className={`
                                                                block px-4 py-2 text-sm transition-colors duration-300
                                                                ${active 
                                                                    ? 'bg-healthcare-background dark:bg-healthcare-background-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark' 
                                                                    : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                                                                }
                                                            `}
                                                        >
                                                            {dropdownItem.name}
                                                        </Link>
                                                    )}
                                                </Menu.Item>
                                            ))}
                                        </Menu.Items>
                                    </Transition>
                                </Menu>
                            ))}
                        </div>
                    </div>

                    {/* Right side items */}
                    <div className="flex items-center space-x-4">
                        <DarkModeToggle isDarkMode={isDarkMode} onToggle={() => setIsDarkMode(!isDarkMode)} />
                        <Menu as="div" className="relative">
                            <Menu.Button className="flex items-center space-x-2 p-2 rounded-md hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-all duration-300">
                                <UserAvatar />
                                <Icon
                                    icon="heroicons:chevron-down"
                                    className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300"
                                />
                            </Menu.Button>
                            <Menu.Items className="absolute right-0 mt-2 w-48 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-lg py-1 border border-healthcare-border dark:border-healthcare-border-dark divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                <Menu.Item>
                                    {({ active }) => (
                                        <Link
                                            href={route('profile.edit')}
                                            className={`
                                                flex items-center px-4 py-2.5 text-sm transition-colors duration-300
                                                ${active 
                                                    ? 'bg-healthcare-background dark:bg-healthcare-background-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark' 
                                                    : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                                                }
                                            `}
                                        >
                                            <Icon icon="heroicons:user" className="w-4 h-4 mr-2" />
                                            Profile
                                        </Link>
                                    )}
                                </Menu.Item>
                                <Menu.Item>
                                    {({ active }) => (
                                        <Link
                                            href={route('logout')}
                                            method="post"
                                            className={`
                                                flex items-center px-4 py-2.5 text-sm transition-colors duration-300
                                                ${active 
                                                    ? 'bg-healthcare-background dark:bg-healthcare-background-dark' 
                                                    : ''
                                                }
                                                text-healthcare-critical dark:text-healthcare-critical-dark
                                            `}
                                        >
                                            <Icon icon="heroicons:arrow-right-on-rectangle" className="w-4 h-4 mr-2" />
                                            Sign out
                                        </Link>
                                    )}
                                </Menu.Item>
                            </Menu.Items>
                        </Menu>
                    </div>
                </div>
            </div>
        </nav>
    );
};

export default TopNavigation;
