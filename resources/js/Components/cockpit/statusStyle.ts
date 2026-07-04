// resources/js/Components/cockpit/statusStyle.ts
//
// The SINGLE client mirror of the server StatusEngine (app/Services/Cockpit/
// StatusEngine.php). Glyph + color + the ISA-101 value-color policy live here
// and nowhere else; every cockpit primitive reads this helper so status can
// never be encoded by color alone (WCAG 1.4.1) and the grey-baseline render
// discipline has exactly one enforcement point.
//
// Vocabulary (docs/ZEPHYRUS-2.0-PLAN.md Part IV §1): five logical states on
// four canon colors + grey — shape carries the fifth distinction.
import type { StatusLevel } from '@/types/commandCenter';
import type { CockpitState } from '@/types/cockpit';
import { STATUS_VAR } from '@/Components/CommandCenter/status';

export type StatusGlyph = '–' | '●' | '▲' | '◆';

export interface StatusStyle {
  /** Canon CSS-var color (STATUS_VAR) — never a raw hex. */
  color: string;
  /** ISA-101 state glyph: – normal / ● ok / ● watch / ▲ warn / ◆ crit. */
  glyph: StatusGlyph;
  /** Human label, used as the StatusChip aria-label. */
  label: string;
  /**
   * ISA-101 grey-baseline rule: when true the metric VALUE renders in
   * near-white text-primary (normal + watch); only ok (rationed), warn and
   * crit put status color on the value itself.
   */
  valuePrimary: boolean;
}

const STYLES: Record<StatusLevel, StatusStyle> = {
  neutral: { color: STATUS_VAR.neutral, glyph: '–', label: 'Normal', valuePrimary: true },
  success: { color: STATUS_VAR.success, glyph: '●', label: 'On target', valuePrimary: false },
  info: { color: STATUS_VAR.info, glyph: '●', label: 'Watch', valuePrimary: true },
  warning: { color: STATUS_VAR.warning, glyph: '▲', label: 'Warning', valuePrimary: false },
  critical: { color: STATUS_VAR.critical, glyph: '◆', label: 'Critical', valuePrimary: false },
};

export function statusStyle(level: StatusLevel): StatusStyle {
  return STYLES[level];
}

// D7: the logical ISA-101 vocabulary is an ALIAS onto the existing StatusLevel
// enum — no physical rename of the ad-hoc status assignments or Zod schemas.
export const COCKPIT_STATE_TO_LEVEL: Record<CockpitState, StatusLevel> = {
  normal: 'neutral',
  ok: 'success',
  watch: 'info',
  warn: 'warning',
  crit: 'critical',
};

export const LEVEL_TO_COCKPIT_STATE: Record<StatusLevel, CockpitState> = {
  neutral: 'normal',
  success: 'ok',
  info: 'watch',
  warning: 'warn',
  critical: 'crit',
};

export function cockpitStatusStyle(state: CockpitState): StatusStyle {
  return statusStyle(COCKPIT_STATE_TO_LEVEL[state]);
}
