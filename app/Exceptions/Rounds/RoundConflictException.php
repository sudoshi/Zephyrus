<?php

namespace App\Exceptions\Rounds;

/**
 * Stale expected version or duplicate idempotent command — controller maps
 * to HTTP 409 alongside the current projection so the client can recover.
 */
class RoundConflictException extends \RuntimeException {}
