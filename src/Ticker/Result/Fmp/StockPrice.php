<?php

namespace App\Ticker\Result\Fmp;

use Utilitte\Asserts\ArrayTypeAssert;

final class StockPrice
{
    /**
     * @param mixed[] $data
     */
    public function __construct(
        private array $data,
    )
    {
    }

    public function getSymbol(): string
    {
        return ArrayTypeAssert::string($this->data, 'symbol');
    }

    public function getPrice(): float
    {
        return ArrayTypeAssert::numericFloat($this->data, 'price');
    }

    public function getVolume(): int
    {
        return ArrayTypeAssert::int($this->data, 'volume');
    }

    public function toArray()
    {
        return [
            "symbol" => $this->getSymbol(),
            "price" => $this->getPrice(),
            //"volume" => $this->getVolume()
        ];
    }
}