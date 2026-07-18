import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import NavigatorFloorRail from '@/Components/PatientFlowNavigator/NavigatorFloorRail';

describe('NavigatorFloorRail (N-4)', () => {
  it('selects a floor in one click and offers All', () => {
    const onSelect = vi.fn();
    render(<NavigatorFloorRail floors={['1', '2', '3']} current="all" onSelect={onSelect} />);

    fireEvent.click(screen.getByRole('button', { name: '2' }));
    expect(onSelect).toHaveBeenCalledWith('2');

    fireEvent.click(screen.getByRole('button', { name: 'All' }));
    expect(onSelect).toHaveBeenCalledWith('all');
  });

  it('renders highest floor on top and marks the current floor', () => {
    render(<NavigatorFloorRail floors={['1', '2', '3']} current="2" onSelect={vi.fn()} />);

    const labels = screen.getAllByRole('button').map((button) => button.textContent);
    expect(labels).toEqual(['3', '2', '1', 'All']);
    expect(screen.getByRole('button', { name: '2' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('button', { name: '2' })).toHaveAttribute('title', 'Show floor 2 and frame it');
  });

  it('steps floors with arrow keys (↑ up the building, ↓ down)', () => {
    const onSelect = vi.fn();
    render(<NavigatorFloorRail floors={['1', '2', '3']} current="2" onSelect={onSelect} />);

    const rail = screen.getByRole('navigation', { name: 'Floor stepper' });
    fireEvent.keyDown(rail, { key: 'ArrowUp' });
    expect(onSelect).toHaveBeenLastCalledWith('3');
    fireEvent.keyDown(rail, { key: 'ArrowDown' });
    expect(onSelect).toHaveBeenLastCalledWith('1');
  });

  it('enters the building from All at the matching end', () => {
    const onSelect = vi.fn();
    render(<NavigatorFloorRail floors={['1', '2', '3']} current="all" onSelect={onSelect} />);

    const rail = screen.getByRole('navigation', { name: 'Floor stepper' });
    fireEvent.keyDown(rail, { key: 'ArrowUp' });
    expect(onSelect).toHaveBeenLastCalledWith('1');
    fireEvent.keyDown(rail, { key: 'ArrowDown' });
    expect(onSelect).toHaveBeenLastCalledWith('3');
  });

  it('renders nothing when no floors are known yet', () => {
    const { container } = render(<NavigatorFloorRail floors={[]} current="all" onSelect={vi.fn()} />);
    expect(container).toBeEmptyDOMElement();
  });
});
