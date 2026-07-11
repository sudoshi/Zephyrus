import React, { useEffect } from 'react';
import { Command } from 'cmdk';
import { router, usePage } from '@inertiajs/react';
import { useUIStore } from '@/stores/uiStore';
import { flattenNavigation } from '@/config/navigationConfig';
import type { NavigationAccess } from '@/config/navigationConfig';
import type { PageProps } from '@/types';

export function CommandPalette() {
  const open = useUIStore((s) => s.commandPaletteOpen);
  const setOpen = useUIStore((s) => s.setCommandPaletteOpen);
  const page = usePage<PageProps>();
  const isAdmin = Boolean(page.props.auth?.is_admin);
  const access: NavigationAccess = { isAdmin, can: page.props.auth?.can, features: page.props.features };

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && open) {
        e.preventDefault();
        setOpen(false);
        return;
      }

      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        setOpen(!open);
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [open, setOpen]);

  if (!open) return null;

  const entries = flattenNavigation(access);
  const groups = entries.reduce<Record<string, typeof entries[number][]>>((acc, item) => {
    (acc[item.group] ??= []).push(item);
    return acc;
  }, {});

  return (
    <div className="fixed inset-0 z-50">
      <div className="modal-backdrop" aria-hidden="true" onClick={() => setOpen(false)} />
      <div className="fixed top-[20%] left-1/2 w-full max-w-lg -translate-x-1/2">
        <Command className="rounded-xl border border-gray-700 bg-gray-900 shadow-2xl" label="Command Palette">
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
                    value={`${item.group} ${item.label}`}
                    onSelect={() => {
                      setOpen(false);
                      router.visit(item.href);
                    }}
                    className="flex cursor-pointer items-center rounded-lg px-3 py-2 text-sm text-gray-300 aria-selected:bg-gray-800 aria-selected:text-white"
                  >
                    {item.label}
                  </Command.Item>
                ))}
              </Command.Group>
            ))}
          </Command.List>
          <div className="border-t border-gray-700 px-4 py-2 text-xs text-gray-500">
            <span className="mr-3">
              <kbd className="rounded bg-gray-800 px-1.5 py-0.5 text-xs font-medium text-gray-400">↑↓</kbd> Navigate
            </span>
            <span className="mr-3">
              <kbd className="rounded bg-gray-800 px-1.5 py-0.5 text-xs font-medium text-gray-400">↵</kbd> Select
            </span>
            <span>
              <kbd className="rounded bg-gray-800 px-1.5 py-0.5 text-xs font-medium text-gray-400">Esc</kbd> Close
            </span>
          </div>
        </Command>
      </div>
    </div>
  );
}
