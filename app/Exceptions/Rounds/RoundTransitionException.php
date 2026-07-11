<?php

namespace App\Exceptions\Rounds;

/**
 * Disallowed state-machine transition (run, round patient, or contribution).
 * Controller maps to HTTP 409.
 */
class RoundTransitionException extends \RuntimeException {}
