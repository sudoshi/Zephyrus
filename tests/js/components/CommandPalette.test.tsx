import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { CommandPalette } from '@/components/ui/CommandPalette';
import { useUIStore } from '@/stores/uiStore';

// Mock cmdk since it relies on DOM APIs that jsdom doesn't fully support
vi.mock('cmdk', () => {
  const Command = ({ children, ...props }: any) =>
    React.createElement('div', { 'data-testid': 'command-root', ...props }, children);

  Command.Input = ({ placeholder, ...props }: any) =>
    React.createElement('input', { placeholder, 'data-testid': 'command-input', ...props });

  Command.List = ({ children }: any) =>
    React.createElement('div', { 'data-testid': 'command-list' }, children);

  Command.Empty = ({ children }: any) =>
    React.createElement('div', { 'data-testid': 'command-empty' }, children);

  Command.Group = ({ heading, children }: any) =>
    React.createElement(
      'div',
      { 'data-testid': `command-group-${heading}` },
      React.createElement('span', null, heading),
      children
    );

  Command.Item = ({ children, onSelect, ...props }: any) =>
    React.createElement(
      'div',
      {
        'data-testid': 'command-item',
        onClick: onSelect,
        role: 'option',
        ...props,
      },
      children
    );

  return { Command };
});

describe('CommandPalette', () => {
  beforeEach(() => {
    useUIStore.setState({
      commandPaletteOpen: false,
    });
  });

  describe('visibility', () => {
    it('does not render when closed', () => {
      const { container } = render(React.createElement(CommandPalette));

      expect(container.innerHTML).toBe('');
    });

    it('renders when open', () => {
      useUIStore.setState({ commandPaletteOpen: true });

      render(React.createElement(CommandPalette));

      expect(screen.getByTestId('command-root')).toBeInTheDocument();
    });

    it('renders search input when open', () => {
      useUIStore.setState({ commandPaletteOpen: true });

      render(React.createElement(CommandPalette));

      expect(screen.getByTestId('command-input')).toBeInTheDocument();
    });

    it('renders navigation groups when open', () => {
      useUIStore.setState({ commandPaletteOpen: true });

      render(React.createElement(CommandPalette));

      expect(screen.getByTestId('command-group-Navigation')).toBeInTheDocument();
    });
  });

  describe('keyboard shortcut', () => {
    it('opens command palette with Cmd+K', () => {
      render(React.createElement(CommandPalette));

      fireEvent.keyDown(document, { key: 'k', metaKey: true });

      expect(useUIStore.getState().commandPaletteOpen).toBe(true);
    });

    it('opens command palette with Ctrl+K', () => {
      render(React.createElement(CommandPalette));

      fireEvent.keyDown(document, { key: 'k', ctrlKey: true });

      expect(useUIStore.getState().commandPaletteOpen).toBe(true);
    });

    it('closes command palette when already open with Cmd+K', () => {
      useUIStore.setState({ commandPaletteOpen: true });

      render(React.createElement(CommandPalette));

      fireEvent.keyDown(document, { key: 'k', metaKey: true });

      expect(useUIStore.getState().commandPaletteOpen).toBe(false);
    });

    it('does not toggle without modifier key', () => {
      render(React.createElement(CommandPalette));

      fireEvent.keyDown(document, { key: 'k' });

      expect(useUIStore.getState().commandPaletteOpen).toBe(false);
    });
  });

  describe('backdrop', () => {
    it('closes when clicking the backdrop', () => {
      useUIStore.setState({ commandPaletteOpen: true });

      const { container } = render(React.createElement(CommandPalette));

      // Click the backdrop overlay
      const backdrop = container.querySelector('.bg-black\\/50');
      if (backdrop) {
        fireEvent.click(backdrop);
      }

      expect(useUIStore.getState().commandPaletteOpen).toBe(false);
    });
  });

  describe('navigation items', () => {
    it('renders command items', () => {
      useUIStore.setState({ commandPaletteOpen: true });

      render(React.createElement(CommandPalette));

      const items = screen.getAllByTestId('command-item');
      expect(items.length).toBeGreaterThan(0);
    });

    it('includes Dashboard in navigation items', () => {
      useUIStore.setState({ commandPaletteOpen: true });

      render(React.createElement(CommandPalette));

      expect(screen.getByText('Dashboard')).toBeInTheDocument();
    });

    it('includes RTDC in navigation items', () => {
      useUIStore.setState({ commandPaletteOpen: true });

      render(React.createElement(CommandPalette));

      expect(screen.getByText('RTDC')).toBeInTheDocument();
    });

    it('includes config-driven sub-pages like Bed Tracking', () => {
      useUIStore.setState({ commandPaletteOpen: true });

      render(React.createElement(CommandPalette));

      expect(screen.getByText('Bed Tracking')).toBeInTheDocument();
    });
  });
});
