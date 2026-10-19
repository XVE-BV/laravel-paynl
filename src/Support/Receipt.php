<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The card receipt of a terminal payment or refund, as Pay. returns it once the terminal is done.
 * Pay. requires the kassa to offer it to the customer (print, show or mail) after a successful payment.
 *
 * $lines is the receipt as the terminal prints it, one string per line; $ticketData holds the same
 * data as separate fields (terminal, merchant, card_brand_label_name, authorization_code, ...) for
 * a kassa that lays out its own receipt.
 */
final class Receipt implements Arrayable, JsonSerializable
{
    /**
     * @param  string[]  $lines
     */
    public function __construct(
        public readonly array $lines = [],
        public readonly array $ticketData = [],
        public readonly ?string $authorizationCode = null,
        public readonly bool $signatureRequired = false,
    ) {}

    /**
     * @param  ?string  $receipt  the order's `receipt`: a base64 encoded JSON list of lines
     * @param  array  $payments  the order's `payments`, whose supplierData carries the ticket data
     */
    public static function fromPay(?string $receipt, array $payments): ?self
    {
        $supplierData = [];
        foreach (array_reverse($payments) as $payment) {
            if (filled(data_get($payment, 'supplierData.ticket_data'))) {
                $supplierData = (array) data_get($payment, 'supplierData');
                break;
            }
        }

        $lines = self::decodeLines($receipt);
        if ($lines === [] && $supplierData === []) {
            return null;
        }

        return new self(
            lines: $lines,
            ticketData: (array) ($supplierData['ticket_data'] ?? []),
            authorizationCode: filled($supplierData['authorization_code'] ?? null) ? (string) $supplierData['authorization_code'] : null,
            signatureRequired: filter_var($supplierData['signature'] ?? false, FILTER_VALIDATE_BOOL),
        );
    }

    /**
     * @return string[]
     */
    private static function decodeLines(?string $receipt): array
    {
        if (blank($receipt)) {
            return [];
        }

        $decoded = base64_decode($receipt, true);
        $lines = $decoded === false ? null : json_decode($decoded, true);

        if (is_array($lines)) {
            return array_values(array_map(fn ($line) => (string) $line, $lines));
        }

        // Not the documented format: keep whatever text Pay. sent
        return preg_split('/\R/', $decoded !== false && mb_check_encoding($decoded, 'UTF-8') ? $decoded : $receipt) ?: [];
    }

    /**
     * The receipt as plain text, for a receipt printer or a mail.
     */
    public function text(): string
    {
        return implode(PHP_EOL, $this->lines);
    }

    public function toArray(): array
    {
        return [
            'lines' => $this->lines,
            'ticketData' => $this->ticketData,
            'authorizationCode' => $this->authorizationCode,
            'signatureRequired' => $this->signatureRequired,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
