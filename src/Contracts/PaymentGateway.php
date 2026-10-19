<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Contracts;

use PayNL\Sdk\Model\Request\OrderCreateRequest;
use Xve\LaravelPaynl\Exceptions\PaymentNotVerifiedException;
use Xve\LaravelPaynl\Exceptions\PaynlException;
use Xve\LaravelPaynl\Support\Payment;
use Xve\LaravelPaynl\Support\Terminal;

/**
 * What a package needs to take Pay. payments. Type-hint this (or use the
 * Paynl facade) instead of PaynlService, so Paynl::fake() can stand in for it in tests.
 *
 * Amounts are integer cents (12,10 EUR = 1210), as Pay. itself counts them.
 * Every method throws PaynlException when Pay. cannot be reached or refuses the call.
 */
interface PaymentGateway
{
    /**
     * @return Terminal[]
     */
    public function listTerminals(): array;

    /**
     * Sends the amount straight to the terminal; the customer pays there and the result
     * arrives through the exchange (or a status call).
     *
     * @param  (callable(OrderCreateRequest): void)|null  $configure  extra order data (customer, products, ...)
     */
    public function startTerminalPayment(int $amount, string $terminalCode, string $description, string $reference = '', ?callable $configure = null): Payment;

    /**
     * Pays the amount back to the card on the terminal (Pay. POS refund, method 2351).
     */
    public function startTerminalRefund(int $amount, string $terminalCode, string $description, string $reference = ''): Payment;

    /**
     * Creates an online order; send the customer to the returned paymentUrl.
     *
     * @param  (callable(OrderCreateRequest): void)|null  $configure  extra order data (customer, products, expire time, ...)
     */
    public function startOnlinePayment(int $amount, string $description, string $returnUrl, string $reference = '', ?int $paymentMethodId = null, ?callable $configure = null): Payment;

    public function getStatus(string $orderId): Payment;

    /**
     * The payment, once Pay. confirms it is paid for this amount. Check this before booking:
     * a browser or return url reporting "paid" is no proof.
     *
     * @throws PaymentNotVerifiedException when the order is not paid or paid for another amount
     * @throws PaynlException when Pay. cannot be reached
     */
    public function verifyPaid(string $orderId, int $amount): Payment;

    /**
     * Stops an order that is not paid yet, also one waiting on a terminal.
     */
    public function cancel(string $orderId): Payment;

    /**
     * Refunds a paid online order, in full or for $amount. Pay. processes it asynchronously;
     * the refunded status arrives through the exchange.
     */
    public function refund(string $orderId, ?int $amount = null, string $description = ''): void;

    /**
     * Accepts an order Pay. holds in VERIFY (status Verify) after its fraud check.
     */
    public function approve(string $orderId): Payment;

    /**
     * Rejects an order Pay. holds in VERIFY; Pay. refunds it when it was already captured.
     */
    public function decline(string $orderId): Payment;

    /**
     * Captures an authorized order, in full or for $amount.
     */
    public function capture(string $orderId, ?int $amount = null): Payment;

    /**
     * Releases an authorized order without capturing it.
     */
    public function void(string $orderId): Payment;
}
