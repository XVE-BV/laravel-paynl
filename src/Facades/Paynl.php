<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Facades;

use Illuminate\Support\Facades\Facade;
use Xve\LaravelPaynl\Contracts\PaymentGateway;
use Xve\LaravelPaynl\Testing\FakePaynl;

/**
 * @method static \Xve\LaravelPaynl\Support\Terminal[] listTerminals()
 * @method static \Xve\LaravelPaynl\Support\Payment startTerminalPayment(int $amount, string $terminalCode, string $description, string $reference = '', ?callable $configure = null)
 * @method static \Xve\LaravelPaynl\Support\Payment startTerminalRefund(int $amount, string $terminalCode, string $description, string $reference = '')
 * @method static \Xve\LaravelPaynl\Support\Payment startOnlinePayment(int $amount, string $description, string $returnUrl, string $reference = '', ?int $paymentMethodId = null, ?callable $configure = null)
 * @method static \Xve\LaravelPaynl\Support\Payment getStatus(string $orderId)
 * @method static \Xve\LaravelPaynl\Support\Payment verifyPaid(string $orderId, int $amount)
 * @method static \Xve\LaravelPaynl\Support\Payment cancel(string $orderId)
 * @method static void refund(string $orderId, ?int $amount = null, string $description = '')
 * @method static \Xve\LaravelPaynl\Support\Payment approve(string $orderId)
 * @method static \Xve\LaravelPaynl\Support\Payment decline(string $orderId)
 * @method static \Xve\LaravelPaynl\Support\Payment capture(string $orderId, ?int $amount = null)
 * @method static \Xve\LaravelPaynl\Support\Payment void(string $orderId)
 *
 * @see PaymentGateway
 */
class Paynl extends Facade
{
    /**
     * Swaps the gateway for an in-memory fake: no calls to Pay., and assertions on what was started.
     * Only PaymentGateway and this facade are faked; the exchange keeps verifying for real.
     */
    public static function fake(): FakePaynl
    {
        static::swap($fake = new FakePaynl);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return PaymentGateway::class;
    }
}
