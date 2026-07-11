<?php

namespace App\Exceptions\Rounds;

/**
 * Input rejected by round policy (section allowlist, completion requirements,
 * missing exception reason). Controller maps to HTTP 422.
 */
class RoundPolicyException extends \RuntimeException {}
