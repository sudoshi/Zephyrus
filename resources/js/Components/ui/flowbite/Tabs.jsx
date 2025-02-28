import React from 'react';
import { Tabs as FlowbiteTabs } from 'flowbite-react';

/**
 * Tabs component wrapper for Flowbite Tabs
 * 
 * @param {Object} props - Component props
 * @param {React.ReactNode} props.children - Tab content
 * @param {string} [props.className] - Additional CSS classes
 * @param {Object} [props.style] - Tab style object
 * @param {Function} [props.onActiveTabChange] - Callback when active tab changes
 * @returns {React.ReactElement} Tabs component
 */
export function Tabs({ 
  children, 
  className = "", 
  style = { base: "underline" },
  onActiveTabChange,
  ...props 
}) {
  return (
    <FlowbiteTabs
      className={className}
      style={style}
      onActiveTabChange={onActiveTabChange}
      {...props}
    >
      {children}
    </FlowbiteTabs>
  );
}

/**
 * TabItem component for use with Tabs
 * 
 * @param {Object} props - Component props
 * @param {React.ReactNode} props.children - Tab content
 * @param {string} props.title - Tab title
 * @param {string} [props.className] - Additional CSS classes
 * @param {boolean} [props.active] - Whether tab is active
 * @param {boolean} [props.disabled] - Whether tab is disabled
 * @param {React.ReactNode} [props.icon] - Icon to display in tab
 * @returns {React.ReactElement} TabItem component
 */
export function TabItem({ 
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
}

// Export TabItem as a property of Tabs for compatibility with Flowbite API
Tabs.Item = TabItem;
