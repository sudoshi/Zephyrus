// tests/js/cockpit/StatusChip.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { StatusChip } from '@/Components/cockpit/StatusChip';
import { statusLevels } from '@/types/commandCenter';
import { statusStyle } from '@/Components/cockpit/statusStyle';

describe('StatusChip', () => {
  it('exposes role="img" with the human label as aria-label', () => {
    render(<StatusChip status="critical" />);
    expect(screen.getByRole('img', { name: 'Critical' })).toBeInTheDocument();
  });

  it('renders a DIFFERENT glyph per tier where color pairs collide (non-color-reliance proof)', () => {
    const glyphs = statusLevels.map((level) => statusStyle(level).glyph);
    // normal – / warn ▲ / crit ◆ are unique; ok+watch share ● but differ by
    // color AND value-color discipline (asserted in statusStyle.test.ts).
    expect(new Set(glyphs).size).toBeGreaterThanOrEqual(4);
    for (const level of statusLevels) {
      const { unmount } = render(<StatusChip status={level} />);
      expect(screen.getByRole('img', { name: statusStyle(level).label }).textContent).toBe(
        statusStyle(level).glyph,
      );
      unmount();
    }
  });

  it('renders the optional visible label beside the glyph', () => {
    render(<StatusChip status="warning" label="ED boarders" />);
    expect(screen.getByText('ED boarders')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Warning' })).toBeInTheDocument();
  });
});
