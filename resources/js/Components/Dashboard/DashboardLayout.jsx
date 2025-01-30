import React from 'react';
import { Shell, Sidebar, Header, Content } from '@heroui/react';
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
        <Shell>
            <Sidebar>
                <Sidebar.Brand>
                    <h2 className="text-xl font-bold">ZephyrusOR</h2>
                </Sidebar.Brand>
                <Sidebar.Nav>
                    {navigationItems.map((item) => (
                        <Sidebar.NavItem
                            key={item.name}
                            as={Link}
                            href={item.href}
                            icon={<Icon icon={item.icon} />}
                            current={item.current}
                        >
                            {item.name}
                        </Sidebar.NavItem>
                    ))}
                </Sidebar.Nav>
            </Sidebar>
            
            <Shell.Main>
                <Header>
                    <Header.Title>Operating Room Analytics</Header.Title>
                    <Header.Actions>
                        <Header.UserMenu
                            userImage="/images/default-avatar.png"
                            userName={route().params.user?.name || 'User'}
                            userEmail={route().params.user?.email || 'user@example.com'}
                            items={[
                                { label: 'Profile', href: route('profile.edit') },
                                { label: 'Settings', href: '#' },
                                { 
                                    label: 'Sign out', 
                                    href: route('logout'),
                                    method: 'post'
                                }
                            ]}
                        />
                    </Header.Actions>
                </Header>
                
                <Content>
                    {children}
                </Content>
            </Shell.Main>
        </Shell>
    );
};

export default DashboardLayout;
