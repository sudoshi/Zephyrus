import React, { useEffect } from 'react';
import { Command } from 'cmdk';
import { router } from '@inertiajs/react';
import { useUIStore } from '@/stores/uiStore';

interface CommandItem {
  name: string;
  href: string;
  group: string;
}

const navigationItems: CommandItem[] = [
  // Main workflows
  { name: 'Dashboard', href: '/dashboard', group: 'Navigation' },
  { name: 'RTDC', href: '/dashboard/rtdc', group: 'Navigation' },
  { name: 'Perioperative', href: '/dashboard/perioperative', group: 'Navigation' },
  { name: 'Emergency', href: '/dashboard/emergency', group: 'Navigation' },
  { name: 'Improvement', href: '/dashboard/improvement', group: 'Navigation' },

  // Perioperative Analytics
  { name: 'Block Utilization', href: '/analytics/block-utilization', group: 'Perioperative Analytics' },
  { name: 'OR Utilization', href: '/analytics/or-utilization', group: 'Perioperative Analytics' },
  { name: 'Primetime Utilization', href: '/analytics/primetime-utilization', group: 'Perioperative Analytics' },
  { name: 'Room Running', href: '/analytics/room-running', group: 'Perioperative Analytics' },
  { name: 'Turnover Times', href: '/analytics/turnover-times', group: 'Perioperative Analytics' },

  // Perioperative Operations
  { name: 'Block Schedule', href: '/operations/block-schedule', group: 'Perioperative Operations' },
  { name: 'Case Management', href: '/operations/cases', group: 'Perioperative Operations' },
  { name: 'Room Status', href: '/operations/room-status', group: 'Perioperative Operations' },

  // RTDC Operations
  { name: 'Bed Tracking', href: '/rtdc/bed-tracking', group: 'RTDC Operations' },
  { name: 'Ancillary Services', href: '/rtdc/ancillary-services', group: 'RTDC Operations' },
  { name: 'Global Huddle', href: '/rtdc/global-huddle', group: 'RTDC Operations' },
  { name: 'Service Huddle', href: '/rtdc/service-huddle', group: 'RTDC Operations' },

  // Emergency Operations
  { name: 'ED Resource Management', href: '/ed/operations/resources', group: 'Emergency Operations' },
  { name: 'Triage', href: '/ed/operations/triage', group: 'Emergency Operations' },
  { name: 'Treatment', href: '/ed/operations/treatment', group: 'Emergency Operations' },

  // Predictions
  { name: 'Volume Forecasting', href: '/predictions/volume-forecasting', group: 'Predictions' },
  { name: 'Capacity Planning', href: '/predictions/capacity-planning', group: 'Predictions' },
  { name: 'Resource Optimization', href: '/predictions/resource-optimization', group: 'Predictions' },
];

export function CommandPalette() {
  const open = useUIStore((s) => s.commandPaletteOpen);
  const setOpen = useUIStore((s) => s.setCommandPaletteOpen);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        setOpen(!open);
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [open, setOpen]);

  if (!open) return null;

  const groups = navigationItems.reduce<Record<string, CommandItem[]>>((acc, item) => {
    if (!acc[item.group]) {
      acc[item.group] = [];
    }
    acc[item.group].push(item);
    return acc;
  }, {});

  return (
    <div className="fixed inset-0 z-50">
      <div
        className="fixed inset-0 bg-black/50 backdrop-blur-sm"
        onClick={() => setOpen(false)}
      />
      <div className="fixed top-[20%] left-1/2 w-full max-w-lg -translate-x-1/2">
        <Command
          className="rounded-xl border border-gray-700 bg-gray-900 shadow-2xl"
          label="Command Palette"
        >
          <Command.Input
            className="w-full border-b border-gray-700 bg-transparent px-4 py-3 text-sm text-gray-100 placeholder-gray-500 outline-none"
            placeholder="Search pages and actions..."
            autoFocus
          />
          <Command.List className="max-h-80 overflow-y-auto p-2">
            <Command.Empty className="px-4 py-6 text-center text-sm text-gray-500">
              No results found.
            </Command.Empty>
            {Object.entries(groups).map(([group, items]) => (
              <Command.Group
                key={group}
                heading={group}
                className="[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-gray-500"
              >
                {items.map((item) => (
                  <Command.Item
                    key={item.href}
                    value={`${item.group} ${item.name}`}
                    onSelect={() => {
                      setOpen(false);
                      router.visit(item.href);
                    }}
                    className="flex cursor-pointer items-center rounded-lg px-3 py-2 text-sm text-gray-300 aria-selected:bg-gray-800 aria-selected:text-white"
                  >
                    {item.name}
                  </Command.Item>
                ))}
              </Command.Group>
            ))}
          </Command.List>
          <div className="border-t border-gray-700 px-4 py-2 text-xs text-gray-500">
            <span className="mr-3">
              <kbd className="rounded bg-gray-800 px-1.5 py-0.5 text-[10px] font-medium text-gray-400">
                ↑↓
              </kbd>{' '}
              Navigate
            </span>
            <span className="mr-3">
              <kbd className="rounded bg-gray-800 px-1.5 py-0.5 text-[10px] font-medium text-gray-400">
                ↵
              </kbd>{' '}
              Select
            </span>
            <span>
              <kbd className="rounded bg-gray-800 px-1.5 py-0.5 text-[10px] font-medium text-gray-400">
                Esc
              </kbd>{' '}
              Close
            </span>
          </div>
        </Command>
      </div>
    </div>
  );
}
