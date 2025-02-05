import React from 'react';
import { Menu } from '@headlessui/react';
import { Icon } from '@iconify/react';
import { useDashboard } from '@/Contexts/DashboardContext';

const WorkflowSelector = () => {
  const { currentWorkflow, changeWorkflow } = useDashboard();
  
  const workflows = [
    { id: 'rtdc', name: 'RTDC' },
    { id: 'or', name: 'OR' },
    { id: 'ed', name: 'ED' }
  ];

  return (
    <Menu as="div" className="relative">
      <Menu.Button className="flex items-center px-3 py-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark rounded-md transition-colors duration-300">
        {workflows.find(w => w.id === currentWorkflow)?.name}
        <Icon icon="heroicons:chevron-down" className="w-4 h-4 ml-2" />
      </Menu.Button>
      <Menu.Items className="absolute right-0 mt-2 w-48 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-lg py-1">
        {workflows.map((workflow) => (
          <Menu.Item key={workflow.id}>
            {({ active }) => (
              <button
                onClick={() => changeWorkflow(workflow.id)}
                className={`
                  block w-full text-left px-4 py-2 text-sm transition-colors duration-300
                  ${active 
                    ? 'bg-healthcare-background dark:bg-healthcare-background-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark' 
                    : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                  }
                `}
              >
                {workflow.name}
              </button>
            )}
          </Menu.Item>
        ))}
      </Menu.Items>
    </Menu>
  );
};

export default WorkflowSelector;
