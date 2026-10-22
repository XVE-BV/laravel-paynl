<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * A terminal payment that cannot be started or booked, with the message for the cashier.
 * Laravel renders it as JSON (`message`), so a kassa endpoint can let it through as is.
 */
class TerminalPaymentException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function terminalUnknown(): self
    {
        return new self(__('paynl::terminal.terminal_unknown'));
    }

    public static function terminalOffline(): self
    {
        return new self(__('paynl::terminal.terminal_offline'));
    }

    public static function terminalInactive(string $status): self
    {
        return new self(__('paynl::terminal.terminal_inactive', ['status' => strtolower($status)]));
    }

    public static function terminalBusy(PaynlException $e): self
    {
        return new self(__('paynl::terminal.terminal_busy', ['reason' => $e->getMessage()]), 409, $e);
    }

    public static function starting(): self
    {
        return new self(__('paynl::terminal.starting'), 409);
    }

    public static function notConfigured(): self
    {
        return new self(__('paynl::terminal.not_configured'));
    }

    public static function notStarted(): self
    {
        return new self(__('paynl::terminal.not_started'));
    }

    public static function booking(): self
    {
        return new self(__('paynl::terminal.booking'), 409);
    }

    public static function notVerified(PaymentNotVerifiedException $e): self
    {
        return new self(__($e->reason === PaymentNotVerifiedException::AMOUNT_MISMATCH ? 'paynl::terminal.amount_mismatch' : 'paynl::terminal.not_paid'), 422, $e);
    }

    public static function unverifiable(PaynlException $e): self
    {
        return new self(__('paynl::terminal.unverifiable', ['reason' => $e->getMessage()]), 502, $e);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
