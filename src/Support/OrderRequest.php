<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Support;

use PayNL\Sdk\Model\Request\OrderCreateRequest;

/**
 * The SDK's Order:Create request, plus merge() for the fields its models do not send
 * (stats.promotorId, customer.gaClientId, customer.company.country, ...).
 *
 *     Paynl::startOnlinePayment(2500, 'Webshop', $returnUrl, 'ORD-1', configure: fn (OrderRequest $request) => $request
 *         ->merge(['customer' => ['gaClientId' => $gaClientId], 'stats' => ['promotorId' => 123456789]]));
 */
class OrderRequest extends OrderCreateRequest
{
    private array $merged = [];

    /**
     * Adds raw Order:Create body fields, merged recursively over what the SDK models build.
     */
    public function merge(array $fields): static
    {
        $this->merged = array_replace_recursive($this->merged, $fields);

        return $this;
    }

    public function getBodyParameters(): array
    {
        return array_replace_recursive(parent::getBodyParameters(), $this->merged);
    }
}
