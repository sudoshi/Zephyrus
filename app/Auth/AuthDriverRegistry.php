<?php

namespace App\Auth;

use App\Contracts\AuthDriverInterface;
use InvalidArgumentException;

class AuthDriverRegistry
{
    /** @var array<string, AuthDriverInterface> */
    private array $drivers = [];

    public function register(AuthDriverInterface $driver): void
    {
        $this->drivers[$driver->name()] = $driver;
    }

    public function driver(string $name): AuthDriverInterface
    {
        if (! isset($this->drivers[$name])) {
            throw new InvalidArgumentException(
                "Unknown auth driver: '{$name}'. Registered drivers: ".
                (empty($this->drivers) ? '(none)' : implode(', ', $this->names()))
            );
        }

        return $this->drivers[$name];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->drivers);
    }

    /** @return list<string> */
    public function availableNames(): array
    {
        return array_values(array_filter($this->names(), fn (string $n) => $this->drivers[$n]->isAvailable()));
    }
}
