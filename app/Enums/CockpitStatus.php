<?php

namespace App\Enums;

/**
 * The five ISA-101 logical status states of the Zephyrus 2.0 cockpit
 * (docs/ZEPHYRUS-2.0-PLAN.md Part IV §1). The backend emits these LOGICAL
 * names; the React layer (Components/cockpit/statusStyle.ts) owns the token
 * bridge onto the 4-color+grey canon. canon() exists for legacy 3-state
 * callers during the transition — never for new cockpit surfaces.
 */
enum CockpitStatus: string
{
    case NORMAL = 'normal';
    case OK = 'ok';
    case WATCH = 'watch';
    case WARN = 'warn';
    case CRIT = 'crit';

    /**
     * The single reconciliation map onto the enforced canon vocabulary
     * (StatusLevel in resources/js/types/commandCenter.ts). Mirrored by
     * COCKPIT_STATE_TO_LEVEL in statusStyle.ts — keep the two in lockstep.
     */
    public function canon(): string
    {
        return match ($this) {
            self::NORMAL => 'neutral',
            self::OK => 'success',
            self::WATCH => 'info',
            self::WARN => 'warning',
            self::CRIT => 'critical',
        };
    }

    public function isAlerting(): bool
    {
        return $this === self::WARN || $this === self::CRIT;
    }
}
