<?php

// Goes in: app/Services/Integrations/UnsafeUrlException.php (new file).
// The message is written in plain English and is safe to show to a seller.

namespace App\Services\Integrations;

final class UnsafeUrlException extends \RuntimeException
{
}
