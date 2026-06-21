<?php

namespace App\Exceptions;

/** Thrown when a requested bed is not available for placement (already occupied/closed). */
class BedUnavailableException extends \RuntimeException {}
