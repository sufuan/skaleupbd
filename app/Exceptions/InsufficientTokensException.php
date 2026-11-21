<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by OrderService when a business cannot afford a new order
 * under the M19 points model. Intake surfaces catch this and show the
 * plain message (UI flash / API 402).
 */
class InsufficientTokensException extends RuntimeException
{
}
