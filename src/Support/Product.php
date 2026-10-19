<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Support;

use PayNL\Sdk\Model\Product as PayProduct;

/**
 * The SDK's order line, without its transliteration to ASCII: the SDK turns "Crème brûlée"
 * into "Cr`eme br^ul'ee", while Pay. takes UTF-8 and its certification (test case 9) checks for it.
 * Same constructor as the SDK model; $amount is the unit price in euros there.
 */
class Product extends PayProduct
{
    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }
}
