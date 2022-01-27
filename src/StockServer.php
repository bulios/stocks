<?php declare(strict_types = 1);

namespace App;

use Swoole\Http\Request;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use WebChemistry\Stocks\Result\Fmp\RealtimePrice;
use WebChemistry\Stocks\StockClientInterface;

final class StockServer
{

	private Table $connectionTable;

	private Table $stockPriceTable;

	private Server $server;

	public function __construct(
		private StockClientInterface $stockClient,
		private string $host = '0.0.0.0',
		private int $port = 9502,
	)
	{
	}

	public function start(): void
	{
		// Client connections table
		$this->connectionTable = new Table(2048);
		$this->connectionTable->column('fd', Table::TYPE_INT, strlen((string) PHP_INT_MAX));
		$this->connectionTable->column('symbols', Table::TYPE_STRING, 65535);
		$this->connectionTable->create();

		// Stock prices table
		$this->stockPriceTable = new Table(2048);
		$this->stockPriceTable->column('symbol', Table::TYPE_STRING, 16);
		$this->stockPriceTable->column('price', Table::TYPE_FLOAT);
		$this->stockPriceTable->column('reference_count', Table::TYPE_INT, 8);
		$this->stockPriceTable->create();

		$this->server = new Server($this->host, $this->port);
		$this->server->on('Start', function(Server $server): void {
			echo sprintf("Swoole WebSocket Server is started at http://%s:%d\n", $this->host, $this->port);

			// Send updated stock prices to user every 5 seconds
			$server->tick(5000, function() use ($server): void {
				$symbols_to_update = [];

				// Get symbols with at least one reference
				foreach ($this->stockPriceTable as $stock_price) {
					if ($stock_price['reference_count']) {
						$symbols_to_update[] = $stock_price['symbol'];
					}
				}

				// If there are any symbols to update, save its price to table
				if ($symbols_to_update) {
					$this->saveStocksPrice($symbols_to_update);
				}

				// Send updated prices to users, which requested at least one stock price
				foreach ($this->connectionTable as $conn) {
					if ($conn['symbols']) {
						$server->push($conn['fd'], json_encode($this->getStocksPrice($conn['symbols'])));
					}
				}
			});
		});

		$this->server->on('Open', function(Server $server, Request $request): void {
			// Save connection to table
			$this->connectionTable->set((string) $request->fd, ['fd' => $request->fd, 'symbols' => '']);
		});

		$this->server->on('Message', function(Server $server, Frame $frame): void {
			// Send stock prices to user based on frame data
			$server->push($frame->fd, json_encode($this->getStocksPrice($frame->data)));

			// Count symbol references
			$this->countReferences($frame->fd, $frame->data);

			// Save sent symbols to user in table
			$this->connectionTable->set((string) $frame->fd, ['fd' => $frame->fd, 'symbols' => $frame->data]);
		});

		$this->server->on('Close', function(Server $server, int $fd): void {
			// Remove users' symbol references and delete him from table
			$this->decreaseSymbolReference(explode(',', $this->connectionTable->get((string) $fd, 'symbols')));
			$this->connectionTable->del((string) $fd);
		});

		$this->server->on('Disconnect', function(Server $server, int $fd): void {
			// Remove users' symbol references and delete him from table
			$this->decreaseSymbolReference(explode(',', $this->connectionTable->get((string) $fd, 'symbols')));
			$this->connectionTable->del((string) $fd);
		});

		$this->server->start();
	}

	/**
	 * Get realtime prices for specified symbols and saves them into table.
	 *
	 * @param string[] $symbols
	 * @return array{symbol: string, price: float}[]
	 */
	public function saveStocksPrice(array $symbols): array
	{
		if (!$symbols) {
			return [];
		}

		$data = [];
		$stock_prices = $this->stockClient->realtimePrices($symbols);

		foreach ($stock_prices->getAll() as $stock_price) {
			$this->stockPriceTable->set(
				$stock_price->getSymbol(),
				[
					'symbol' => $stock_price->getSymbol(),
					'price' => $stock_price->getPrice(),
				]
			);

			$data[] = $stock_price;
		}

		return $data;
	}

	/**
	 * Returns stock prices for specified symbols.
	 * If there is already stock price in stock prices table, it returns this value, else it requests realtime price.
	 *
	 * @return array{symbol: string, price: float}[]
	 */
	public function getStocksPrice(string $symbols): array
	{
		$data = [];
		$symbols = explode(',', $symbols);

		// Iterate through specified symbols and check, if stock price is already saved in table
		foreach ($symbols as $key => $symbol) {
			if ($this->stockPriceTable->exists($symbol)) {
				$stock_price = $this->stockPriceTable->get($symbol, 'price');

				$data[] = new RealtimePrice([
					'symbol' => $symbol,
					'price' => $stock_price,
				]);

				// Remove found symbol from array, it's price is already known
				unset($symbols[$key]);
			}
		}

		// If there are any symbols, which aren't in stock prices table, get realtime price
		if ($symbols) {
			$data = array_merge($data, $this->saveStocksPrice($symbols));
		}

		// Return stock prices as array
		return array_map(
			fn (RealtimePrice $stockPrice) => [
				'symbol' => $stockPrice->getSymbol(),
				'price' => $stockPrice->getPrice(),
			],
			$data
		);
	}

	/**
	 * Increases specified symbol(s) reference count.
	 *
	 *
	 * @param string|string[] $symbols
	 */
	public function increaseSymbolReference(string|array $symbols): void
	{
		foreach ((array) $symbols as $symbol) {
			$this->stockPriceTable->incr($symbol, 'reference_count');
		}
	}

	/**
	 * Decreases specified symbol(s) reference count.
	 *
	 * @param string|string[] $symbols
	 */
	public function decreaseSymbolReference(string|array $symbols): void
	{
		foreach ((array) $symbols as $symbol) {
			$this->stockPriceTable->decr($symbol, 'reference_count');
		}
	}

	/**
	 * Must be called after every incoming message.
	 * Takes requested symbols, compares it against previously requested symbols and recounts references
	 */
	public function countReferences(int $fd, string $new_symbols): void
	{
		$new_symbols_array = explode(',', $new_symbols); // Requested symbols
		$symbols = $this->connectionTable->get((string) $fd, 'symbols'); // Previously requested symbols

		// If user hasn't requested any stock prices yet, increase new symbols reference count
		if ($symbols) {
			$symbols_array = explode(',', $symbols);

			$decrease_symbols = array_diff($symbols_array, $new_symbols_array);
			$increase_symbols = array_diff($new_symbols_array, $symbols_array);

			$this->decreaseSymbolReference($decrease_symbols);
			$this->increaseSymbolReference($increase_symbols);
		} else {
			$this->increaseSymbolReference($new_symbols_array);
		}
	}

}
