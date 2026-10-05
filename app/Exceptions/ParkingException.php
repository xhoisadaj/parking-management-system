<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A rule the operator needs to know about (no spots, unknown ticket, discount over the limit).
 * Message is safe to show on screen. Permission failures use AuthorizationException instead.
 */
class ParkingException extends RuntimeException {}
