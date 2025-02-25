import React from 'react';
import { Tabs as FlowbiteTabs } from 'flowbite-react';

/**
 * Tabs component wrapper for Flowbite Tabs
 * 
 * @param {Object} props - Component props
 * @param {React.ReactNode} props.children - Tab content
 * @param {string} [props.className] - Additional CSS classes
 * @param {string} [props.variant="underline"] - Tab style (default, underline, pills, fullWidth)
 * @param {Function} [props.onActiveTabChange] - Callback when active tab changes
 * @returns {React.ReactElement} Tabs component
 */
export function Tabs({ 
  children, 
  className = "", 
  variant = "underline",
  onActiveTabChange,
  ...props 
}) {
  return (
    <FlowbiteTabs
      className={className}
      // Pass the variant to the correct Flowbite prop
      // In Flowbite React, the prop for tab style is 'style'
      variant={variant}
      onActiveTabChange={onActiveTabChange}
      {...props}
    >
      {children}
    </FlowbiteTabs>
  );
}

/**
 * TabItem component for individual tabs
 * 
 * @param {Object} props - Component props
 * @param {React.ReactNode} props.children - Tab content
 * @param {string} props.title - Tab title
 * @param {string} [props.className] - Additional CSS classes
 * @param {boolean} [props.active] - Whether the tab is active
 * @param {boolean} [props.disabled] - Whether the tab is disabled
 * @param {string} [props.icon] - Icon for the tab
 * @returns {React.ReactElement} TabItem component
 */
Tabs.Item = function TabItem({ 
  children, 
  title, 
  className = "", 
  active,
  disabled,
  icon,
  ...props 
}) {
  return (
    <FlowbiteTabs.Item
      title={title}
      className={className}
      active={active}
      disabled={disabled}
      icon={icon}
      {...props}
    >
      {children}
    </FlowbiteTabs.Item>
  );
};

/**
 * Create a custom tab panel with title and content
 * 
 * @param {string} title - Tab title
 * @param {React.ReactNode} content - Tab content
 * @param {Object} [props] - Additional props
 * @returns {React.ReactElement} Tab panel
 */
Tabs.createTabPanel = function createTabPanel(title, content, props = {}) {
  return (
    <Tabs.Item title={title} {...props}>
      {content}
    </Tabs.Item>
  );
};

/**
 * Example usage:
 * 
 * <Tabs>
 *   <Tabs.Item title="Profile">Profile content</Tabs.Item>
 *   <Tabs.Item title="Dashboard">Dashboard content</Tabs.Item>
 *   <Tabs.Item title="Settings">Settings content</Tabs.Item>
 * </Tabs>
 * 
 * Or using createTabPanel:
 * 
 * <Tabs>
 *   {Tabs.createTabPanel("Profile", <ProfileContent />)}
 *   {Tabs.createTabPanel("Dashboard", <DashboardContent />)}
 *   {Tabs.createTabPanel("Settings", <SettingsContent />)}
 * </Tabs>
 */
