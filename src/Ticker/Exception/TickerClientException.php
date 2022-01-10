<?php declare(strict_types = 1);

namespace App\Ticker\Exception;

use Exception;
use Throwable;

final class TickerClientException extends Exception implements TickerClientThrowable
{

	public function __construct(Throwable $previous)
	{
		parent::__construct(
			sprintf('Cannot retrieve information for ticker: %s', $previous->getMessage()),
			0,
			$previous
		);
	}

}
