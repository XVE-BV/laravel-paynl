<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Services;

use PayNL\Sdk\Config\Config as PayConfig;
use PayNL\Sdk\Model\Pay\PayOrder;
use PayNL\Sdk\Model\Request\OrderAbortRequest;
use PayNL\Sdk\Model\Request\OrderCreateRequest;
use PayNL\Sdk\Model\Request\OrderStatusRequest;
use PayNL\Sdk\Model\Request\TerminalsBrowseRequest;
use PayNL\Sdk\Model\Stats;
use PayNL\Sdk\Model\Terminals;
use PayNL\Sdk\Request\RequestData;
use PayNL\Sdk\Util\Exchange;
use Throwable;
use Xve\LaravelPaynl\Exceptions\PaynlException;
use Xve\LaravelPaynl\Support\Payment;
use Xve\LaravelPaynl\Support\Terminal;

class PaynlService
{
    /**
     * Pay. payment method id for instore terminal ("PIN"/POS) payments.
     */
    public const TERMINAL_PAYMENT_METHOD = 1927;

    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return filled($this->config['token_code'] ?? null)
            && filled($this->config['api_token'] ?? null)
            && filled($this->config['service_id'] ?? null);
    }

    /**
     * @return Terminal[]
     */
    public function listTerminals(): array
    {
        /** @var Terminals $terminals */
        $terminals = $this->send(new TerminalsBrowseRequest);

        return array_values(array_map(
            fn ($terminal) => Terminal::fromPayTerminal($terminal),
            $terminals->getTerminals(),
        ));
    }

    /**
     * Sends the amount straight to the terminal; the customer pays there and the
     * result arrives through the exchange (or a status call).
     */
    public function startTerminalPayment(float $amount, string $terminalCode, string $description, string $reference = ''): Payment
    {
        $request = $this->orderRequest($amount, $description, $reference);
        $request->setPaymentMethodId(self::TERMINAL_PAYMENT_METHOD);
        $request->setTerminal($terminalCode);

        return Payment::fromPayOrder($this->send($request));
    }

    /**
     * Creates an online order; send the customer to the returned paymentUrl.
     */
    public function startOnlinePayment(float $amount, string $description, string $returnUrl, string $reference = '', ?int $paymentMethodId = null): Payment
    {
        $request = $this->orderRequest($amount, $description, $reference, $returnUrl);
        if ($paymentMethodId !== null) {
            $request->setPaymentMethodId($paymentMethodId);
        }

        return Payment::fromPayOrder($this->send($request));
    }

    public function getStatus(string $orderId): Payment
    {
        return Payment::fromPayOrder($this->send(new OrderStatusRequest($orderId)));
    }

    public function cancel(string $orderId): Payment
    {
        $this->send(new OrderAbortRequest($orderId));

        return $this->getStatus($orderId);
    }

    /**
     * Verifies an incoming exchange call (signature or a status call to Pay.) and
     * returns the payment it is about.
     */
    public function handleExchange(array $payload): Payment
    {
        $this->ensureConfigured();

        try {
            $order = $this->processExchange(new Exchange($payload ?: null));
        } catch (Throwable $e) {
            throw PaynlException::fromThrowable($e);
        }

        return Payment::fromPayOrder($order);
    }

    /**
     * The text Pay. expects back from the exchange; anything but TRUE makes it retry.
     */
    public function exchangeResponse(array $payload, bool $result, string $message): string
    {
        return (new Exchange($payload ?: null))->setResponse($result, $message, true);
    }

    protected function processExchange(Exchange $exchange): PayOrder
    {
        return $exchange->process($this->payConfig());
    }

    protected function send(RequestData $request): mixed
    {
        $this->ensureConfigured();

        try {
            return $request->setConfig($this->payConfig())->start();
        } catch (Throwable $e) {
            throw PaynlException::fromThrowable($e);
        }
    }

    private function orderRequest(float $amount, string $description, string $reference, ?string $returnUrl = null): OrderCreateRequest
    {
        $request = new OrderCreateRequest;
        $request->setServiceId((string) $this->config['service_id']);
        $request->setAmount(round($amount, 2));
        $request->setCurrency('EUR');
        $request->setDescription($description);
        if ($reference !== '') {
            $request->setReference($reference);
        }
        // Pay. requires a return url, also for terminal payments where nobody is redirected
        $request->setReturnurl($returnUrl ?? url('/'));
        if (config('paynl.exchange.enabled')) {
            $request->setExchangeUrl(route('paynl.exchange'));
        }
        $request->setTestmode((bool) ($this->config['testmode'] ?? false));
        $request->setStats($this->stats());

        return $request;
    }

    private function stats(): Stats
    {
        $stats = new Stats;
        $config = $this->config['stats'] ?? [];

        if (filled($config['object'] ?? null)) {
            $stats->setObject((string) $config['object']);
        }
        if (filled($config['tool'] ?? null)) {
            $stats->setTool((string) $config['tool']);
        }
        if (filled($config['extra1'] ?? null)) {
            $stats->setExtra1((string) $config['extra1']);
        }

        return $stats;
    }

    private function payConfig(): PayConfig
    {
        return (new PayConfig)
            ->setUsername((string) $this->config['token_code'])
            ->setPassword((string) $this->config['api_token']);
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw PaynlException::notConfigured();
        }
    }
}
