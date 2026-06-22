// resources/js/Components/CommandCenter/status.ts
import type { StatusLevel } from '@/types/commandCenter';

export const STATUS_VAR: Record<StatusLevel, string> = {
  critical: 'var(--critical)',
  warning: 'var(--warning)',
  success: 'var(--success)',
  info: 'var(--info)',
  neutral: 'var(--text-muted)',
};
