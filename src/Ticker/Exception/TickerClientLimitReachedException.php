<?php declare(strict_types = 1);

namespace App\Ticker\Exception;

use Exception;

final class TickerClientLimitReachedException extends Exception implements TickerClientThrowable
{

}
