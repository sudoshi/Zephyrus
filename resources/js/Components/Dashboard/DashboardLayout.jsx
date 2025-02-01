Import React from 'react';
import { Menu } from '@headlessui/react';
import { Icon } from '@iconify/react';
import { Link, usePage } from '@inertiajs/react';

const DashboardLayout = ({ children }) => {
    const { url } = usePage();

    const navigationItems = [
        {
            name: 'Dashboard',
            href: route('dashboard'),
            icon: 'heroicons:home',
            current: url.startsWith('/dashboard')
        },
        {
            name: 'Block Schedule',
            href: route('blocks'),
            icon: 'heroicons:calendar',
            current: url.startsWith('/blocks')
        },
        {
            name: 'Cases',
            href: route('cases'),
            icon: 'heroicons:clipboard-document-list',
            current: url.startsWith('/cases')
        },
        {
            name: 'Room Status',
            href: route('room-status'),
            icon: 'heroicons:building-office-2',
            current: url.startsWith('/room-status')
        },
        {
            name: 'Analytics',
            href: route('analytics'),
            icon: 'heroicons:chart-bar',
            current: url.startsWith('/analytics')
        },
        {
            name: 'Settings',
            href: '#',
            icon: 'heroicons:cog-6-tooth',
            current: url.startsWith('/settings')
        }
    ];

    return (
        <div className="min-h-screen bg-gray-100">
            {/* Sidebar */}
            <div className="fixed inset-y-0 left-0 w-64 bg-white shadow-lg">
                <div className="flex flex-col h-full">
                    <div className="px-4 py-6">
                        <h2 className="text-xl font-bold">ZephyrusOR</h2>
                    </div>
                    <nav className="flex-1 px-2 space-y-1 overflow-y-auto">
                        {navigationItems.map((item) => (
                            <Link
                                key={item.name}
                                href={item.href}
                                className={`flex items-center px-4 py-2 text-sm font-medium rounded-md ${
                                    item.current
                                        ? 'bg-gray-100 text-gray-900'
                                        : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                                }`}
                            >
                                <Icon icon={item.icon} className="w-5 h-5 mr-3" />
                                {item.name}
                            </Link>
                        ))}
                    </nav>
                    {/* Logout button at bottom */}
                    <div className="p-4 border-t">
                        <Link
                            href={route('logout')}
                            method="post"
                            className="flex items-center px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 rounded-md"
                        >
                            <Icon icon="heroicons:logout" className="w-5 h-5 mr-3" />
                            Logout
                        </Link>
                    </div>
                </div>
            </div>

            {/* Main content */}
            <div className="pl-64">
                {/* Header */}
                <header className="bg-white shadow">
                    <div className="flex justify-between items-center px-4 py-6">
                        <h1 className="text-2xl font-semibold text-gray-900">Operating Room Analytics</h1>
                        <Menu as="div" className="relative">
                            <Menu.Button className="flex items-center">
                                <img
                                    className="h-8 w-8 rounded-full"
                                    src="/images/default-avatar.png"
                                    alt="User avatar"
                                />
                            </Menu.Button>
                            <Menu.Items className="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1">
                                <Menu.Item>
                                    {({ active }) => (
                                        <Link
                                            href={route('profile.edit')}
                                            className={`${
                                                active ? 'bg-gray-100' : ''
                                            } block px-4 py-2 text-sm text-gray-700`}
                                        >
                                            Profile
                                        </Link>
                                    )}
                                </Menu.Item>
                                <Menu.Item>
                                    {({ active }) => (
                                        <Link
                                            href="#"
                                            className={`${
                                                active ? 'bg-gray-100' : ''
                                            } block px-4 py-2 text-sm text-gray-700`}
                                        >
                                            Settings
                                        </Link>
                                    )}
                                </Menu.Item>
                                <Menu.Item>
                                    {({ active }) => (
                                        <Link
                                            href={route('logout')}
                                            method="post"
                                            className={`${
                                                active ? 'bg-gray-100' : ''
                                            } block px-4 py-2 text-sm text-gray-700`}
                                        >
                                            Sign out
                                        </Link>
                                    )}
                                </Menu.Item>
                            </Menu.Items>
                        </Menu>
                    </div>
                </header>

                <main className="py-6 px-4">
                    {children}
                </main>
            </div>
        </div>
    );
};

export default DashboardLayout;
