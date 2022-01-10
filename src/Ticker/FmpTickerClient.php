<?php declare(strict_types = 1);

namespace App\Ticker;

use App\Ticker\Exception\TickerClientException;
use App\Ticker\Exception\TickerClientNoDataException;
use App\Ticker\Result\Fmp\StockPrice;
use Nette\Http\Url;
use Nette\Utils\Arrays;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FmpTickerClient
{
    private const API_KEY = "41b535586463a0697a7f2b16dc55fc59";
	private const API_URL = 'https://financialmodelingprep.com/api/v3/';

    private HttpClientInterface $client;

	public function __construct()
    {
        $this->client = HttpClient::create();
	}

    /**
     * @param array $symbols
     * @return StockPrice[]
     * @throws TickerClientException
     */
    public function price(array $symbols): array
    {
        $data = $this->request($this->createUrl("quote-short", $symbols));

        return array_map(
            fn (array $data) => new StockPrice($data),
            $data,
        );
    }

	/**
	 * @param string[]|string $symbols
	 */
	private function createUrl(string $path, array|string|null $symbols = null, bool $apiKey = true): Url
	{
		$path = trim($path, '/');
		if ($symbols !== null) {
			$path .= '/' . implode(',', (array) $symbols);
		}

		$url = new Url(self::API_URL . $path);
		if ($apiKey) {
			$url->setQueryParameter('apikey', self::API_KEY);
		}

		return $url;
	}

	/**
	 * @return array
	 */
	private function request(Url $url): array
	{
		try {
			$response = $this->client->request('GET', (string) $url);

			return $response->toArray();
		} catch (ExceptionInterface $exception) {
			throw new TickerClientException($exception);
		}
	}

}
