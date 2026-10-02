<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Exceptions;

use PayNL\Sdk\Exception\PayException;
use RuntimeException;
use Throwable;

class PaynlException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Pay.nl is not configured: set PAYNL_TOKEN_CODE, PAYNL_API_TOKEN and PAYNL_SERVICE_ID.');
    }

    public static function fromThrowable(Throwable $e): self
    {
        $message = $e instanceof PayException ? ($e->getFriendlyMessage() ?: $e->getMessage()) : $e->getMessage();

        return new self($message, (int) $e->getCode(), $e);
    }
}
