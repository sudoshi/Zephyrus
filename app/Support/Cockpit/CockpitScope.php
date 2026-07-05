<?php

namespace App\Support\Cockpit;

/**
 * Immutable descriptor of the altitude a cockpit surface is mounted at (Zephyrus 2.0
 * P8 — the Mount-Anywhere Cockpit). Exactly one of four levels — house / service_line
 * / department / unit — carrying the resolved key + a human label. Constructed only
 * via the named factories (and {@see CockpitScopeResolver}), never mutated: re-scoping
 * produces a new value, never an edit in place.
 *
 * The `token` form ('house' | 'unit:MICU' | 'service_line:critical_care' |
 * 'department:ed') is the stable string used in the `?scope=` URL param, cache
 * suffixes, and the drill API — parse it back with CockpitScopeResolver::fromToken().
 */
final class CockpitScope
{
    public const LEVEL_HOUSE = 'house';

    public const LEVEL_SERVICE_LINE = 'service_line';

    public const LEVEL_DEPARTMENT = 'department';

    public const LEVEL_UNIT = 'unit';

    /** @var list<string> */
    public const LEVELS = [
        self::LEVEL_HOUSE,
        self::LEVEL_SERVICE_LINE,
        self::LEVEL_DEPARTMENT,
        self::LEVEL_UNIT,
    ];

    private function __construct(
        public readonly string $level,
        public readonly ?string $key,
        public readonly string $label,
    ) {}

    public static function house(string $label = 'House-wide'): self
    {
        return new self(self::LEVEL_HOUSE, null, $label);
    }

    public static function serviceLine(string $code, string $label): self
    {
        return new self(self::LEVEL_SERVICE_LINE, $code, $label);
    }

    public static function department(string $key, string $label): self
    {
        return new self(self::LEVEL_DEPARTMENT, $key, $label);
    }

    public static function unit(string $abbr, string $label): self
    {
        return new self(self::LEVEL_UNIT, $abbr, $label);
    }

    public function isHouse(): bool
    {
        return $this->level === self::LEVEL_HOUSE;
    }

    /** Stable, URL/cache-safe identifier: 'house' or '{level}:{key}'. */
    public function token(): string
    {
        return $this->isHouse() ? self::LEVEL_HOUSE : "{$this->level}:{$this->key}";
    }

    /**
     * @return array{level: string, key: string|null, label: string, token: string}
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'key' => $this->key,
            'label' => $this->label,
            'token' => $this->token(),
        ];
    }
}
