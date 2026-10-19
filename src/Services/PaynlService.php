<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Services;

use Illuminate\Http\Request;
use InvalidArgumentException;
use PayNL\Sdk\Config\Config as PayConfig;
use PayNL\Sdk\Model\Amount;
use PayNL\Sdk\Model\Request\OrderAbortRequest;
use PayNL\Sdk\Model\Request\OrderApproveRequest;
use PayNL\Sdk\Model\Request\OrderCaptureRequest;
use PayNL\Sdk\Model\Request\OrderDeclineRequest;
use PayNL\Sdk\Model\Request\OrderStatusRequest;
use PayNL\Sdk\Model\Request\OrderVoidRequest;
use PayNL\Sdk\Model\Request\TerminalsBrowseRequest;
use PayNL\Sdk\Model\Request\TransactionRefundRequest;
use PayNL\Sdk\Model\Stats;
use PayNL\Sdk\Model\Terminals;
use PayNL\Sdk\Request\RequestData;
use PayNL\Sdk\Util\Misc;
use Throwable;
use Xve\LaravelPaynl\Concerns\VerifiesPayments;
use Xve\LaravelPaynl\Contracts\PaymentGateway;
use Xve\LaravelPaynl\Exceptions\PaynlException;
use Xve\LaravelPaynl\Http\Client\TimeoutGuzzleClientFactory;
use Xve\LaravelPaynl\Support\OrderRequest;
use Xve\LaravelPaynl\Support\Payment;
use Xve\LaravelPaynl\Support\Terminal;

class PaynlService implements PaymentGateway
{
    use VerifiesPayments;

    /**
     * Pay. payment method id for instore terminal ("PIN"/POS) payments.
     */
    public const TERMINAL_PAYMENT_METHOD = 1927;

    /**
     * Pay. payment method id for a refund to the card on the terminal.
     */
    public const TERMINAL_REFUND_METHOD = 2351;

    /**
     * XVE's PartnerID at Pay., sent as stats.object on every order of every project so Pay. can
     * trace them to this integration. TEST_PARTNER_VALUE (what Pay.'s certification test case 5
     * expects) until the certification hands out the real one; then it goes here, in a new release.
     */
    public const PARTNER_ID = 'TEST_PARTNER_VALUE';

    private const SIGNATURE_ALGORITHMS = ['sha256', 'sha512'];

    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return filled($this->config['token_code'] ?? null)
            && filled($this->config['api_token'] ?? null)
            && filled($this->config['service_id'] ?? null);
    }

    public function listTerminals(): array
    {
        /** @var Terminals $terminals */
        $terminals = $this->send(new TerminalsBrowseRequest);

        return array_values(array_map(
            fn ($terminal) => Terminal::fromPayTerminal($terminal),
            $terminals->getTerminals(),
        ));
    }

    public function startTerminalPayment(int $amount, string $terminalCode, string $description, string $reference = '', ?callable $configure = null): Payment
    {
        $request = $this->orderRequest($amount, $description, $reference);
        $request->setPaymentMethodId(self::TERMINAL_PAYMENT_METHOD);
        $request->setTerminal($terminalCode);

        if ($configure !== null) {
            $configure($request);
        }

        return Payment::fromPayOrder($this->send($request));
    }

    public function startTerminalRefund(int $amount, string $terminalCode, string $description, string $reference = ''): Payment
    {
        $request = $this->orderRequest($amount, $description, $reference);
        $request->setPaymentMethodId(self::TERMINAL_REFUND_METHOD);
        $request->setTerminal($terminalCode);

        return Payment::fromPayOrder($this->send($request));
    }

    public function startOnlinePayment(int $amount, string $description, string $returnUrl, string $reference = '', ?int $paymentMethodId = null, ?callable $configure = null): Payment
    {
        $request = $this->orderRequest($amount, $description, $reference, $returnUrl);
        if ($paymentMethodId !== null) {
            $request->setPaymentMethodId($paymentMethodId);
        }

        if ($configure !== null) {
            $configure($request);
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

    public function refund(string $orderId, ?int $amount = null, string $description = ''): void
    {
        $request = new TransactionRefundRequest($orderId);
        if ($amount !== null) {
            $this->assertAmount($amount);
            $request->setAmount(new Amount($amount, $this->currency()));
        }
        if ($description !== '') {
            $request->setDescription($description);
        }
        if ($this->exchangeEnabled()) {
            $request->setExchangeUrl($this->exchangeUrl());
        }

        $this->send($request);
    }

    public function approve(string $orderId): Payment
    {
        $this->send(new OrderApproveRequest($orderId));

        return $this->getStatus($orderId);
    }

    public function decline(string $orderId): Payment
    {
        $this->send(new OrderDeclineRequest($orderId));

        return $this->getStatus($orderId);
    }

    public function capture(string $orderId, ?int $amount = null): Payment
    {
        if ($amount !== null) {
            $this->assertAmount($amount);
        }

        // The SDK takes euros here and multiplies an Amount object by 100 once more, so hand it a float
        $this->send(new OrderCaptureRequest($orderId, $amount === null ? null : $amount / 100));

        return $this->getStatus($orderId);
    }

    public function void(string $orderId): Payment
    {
        $this->send(new OrderVoidRequest($orderId));

        return $this->getStatus($orderId);
    }

    /**
     * Verifies an incoming exchange call and returns the payment it is about.
     *
     * A signed call (HMAC over the raw body with the API token) is trusted as is.
     * Anything else only tells us which order changed: its status is asked from Pay. itself,
     * so a forged call can at most make us look up a real order.
     *
     * The signature is checked against the Laravel request, not getallheaders()/php://input as
     * the SDK does, so it also holds under Octane, Swoole and RoadRunner.
     */
    public function handleExchange(Request $request): Payment
    {
        $this->ensureConfigured();
        $payload = $this->exchangePayload($request);

        if ($this->isSignedExchange($request)) {
            $this->verifySignature($request);

            $object = $payload['object'] ?? null;
            if (is_array($object) && (int) data_get($object, 'status.code', 0) !== 0 && filled($object['orderId'] ?? $object['id'] ?? null)) {
                return Payment::fromExchangeObject($object);
            }
        }

        // TGU sends object.orderId; refunds and other legacy (GMS) calls send a flat order_id
        $orderId = data_get($payload, 'object.orderId') ?: data_get($payload, 'object.id') ?: ($payload['order_id'] ?? null);
        if (! is_string($orderId) || blank($orderId)) {
            throw PaynlException::invalidExchange('no order id in the payload');
        }

        return $this->getStatus($orderId);
    }

    public function isSignedExchange(Request $request): bool
    {
        return $request->header('signature-method') === 'HMAC';
    }

    protected function send(RequestData $request): mixed
    {
        $this->ensureConfigured();

        try {
            return $request->setConfig($this->payConfig($request))->start();
        } catch (Throwable $e) {
            throw PaynlException::fromThrowable($e);
        }
    }

    private function exchangePayload(Request $request): array
    {
        $payload = $request->all();

        // Pay. posts JSON; without a JSON content type Laravel leaves the body unparsed
        if (! isset($payload['object']) && ! isset($payload['order_id'])) {
            $body = json_decode($request->getContent(), true);
            if (is_array($body)) {
                $payload = array_merge($payload, $body);
            }
        }

        return $payload;
    }

    private function verifySignature(Request $request): void
    {
        $keyId = trim((string) $request->header('signature-keyid'));
        $secret = match (true) {
            $keyId !== '' && $keyId === (string) $this->config['token_code'] => (string) $this->config['api_token'],
            $keyId !== '' && $keyId === (string) $this->config['service_id'] && filled($this->config['service_secret'] ?? null) => (string) $this->config['service_secret'],
            default => throw PaynlException::invalidExchange("signed with key [{$keyId}], which is not our token code or sales location"),
        };

        $algorithm = strtolower((string) $request->header('signature-algorithm', 'sha256'));
        if (! in_array($algorithm, self::SIGNATURE_ALGORITHMS, true)) {
            throw PaynlException::invalidExchange("unsupported signature algorithm [{$algorithm}]");
        }

        $expected = hash_hmac($algorithm, $request->getContent(), $secret);
        if (! hash_equals($expected, strtolower(trim((string) $request->header('signature'))))) {
            throw PaynlException::invalidExchange('signature does not match');
        }
    }

    private function orderRequest(int $amount, string $description, string $reference, ?string $returnUrl = null): OrderRequest
    {
        $this->assertAmount($amount);
        // Pay. only accepts letters, digits and dashes, up to 128 characters
        if (! preg_match('/^[A-Za-z0-9-]{0,128}$/', $reference)) {
            throw new InvalidArgumentException("Pay. reference [{$reference}] may only hold letters, digits and dashes (max 128).");
        }

        $request = new OrderRequest;
        $request->setServiceId((string) $this->config['service_id']);
        $request->setAmount(new Amount($amount, $this->currency()));
        $request->setDescription($description);
        if ($reference !== '') {
            $request->setReference($reference);
        }
        // Pay. requires a return url, also for terminal payments where nobody is redirected
        $request->setReturnurl($returnUrl ?? url('/'));
        if ($this->exchangeEnabled()) {
            $request->setExchangeUrl($this->exchangeUrl());
        }
        $request->setTestmode((bool) ($this->config['testmode'] ?? false));
        $request->setStats($this->stats());

        return $request;
    }

    private function assertAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("A Pay. amount is a positive number of cents, got [{$amount}].");
        }
    }

    private function currency(): string
    {
        return (string) ($this->config['currency'] ?? 'EUR');
    }

    private function exchangeEnabled(): bool
    {
        return (bool) data_get($this->config, 'exchange.enabled', true);
    }

    private function exchangeUrl(): string
    {
        return route('paynl.exchange');
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

    private function payConfig(?RequestData $request = null): PayConfig
    {
        $config = (new PayConfig)
            ->setUsername((string) $this->config['token_code'])
            ->setPassword((string) $this->config['api_token'])
            // The SDK caches status calls for the whole process (and in world-writable temp files);
            // a queue worker, Octane or a polling kassa would read the first status forever
            ->setCaching(false)
            ->setGuzzleClientFactory(new TimeoutGuzzleClientFactory(
                (float) ($this->config['timeout'] ?? 10),
                (float) ($this->config['connect_timeout'] ?? 3),
            ));

        if (filled($this->config['core'] ?? null)) {
            $config->setCore((string) $this->config['core']);

            // OrderStatusRequest pins its core to connect.pay.nl and only the failover url overrides that.
            // Not for other requests or non-TGU (EX-) ids: those go to rest.pay.nl, which it would override too.
            if ($request instanceof OrderStatusRequest && Misc::isTguTransaction($request->getPathParameters()['transactionId'] ?? null)) {
                $config->setupFailoverUrl((string) $this->config['core']);
            }
        }

        return $config;
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw PaynlException::notConfigured();
        }
    }
}
