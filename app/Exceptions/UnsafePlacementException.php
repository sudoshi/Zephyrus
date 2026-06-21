<?php

namespace App\Exceptions;

/** Thrown when a requested bed placement violates a hard safety constraint. */
class UnsafePlacementException extends \RuntimeException {}
