<?php

use Swoole\Http\Request;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use WebChemistry\Stocks\FmpStockClient;
use WebChemistry\Stocks\Result\Fmp\RealtimePrice;

include_once __DIR__ . "/vendor/autoload.php";

// Client connections table
$conn_table = new Table(2048);
$conn_table->column("fd", Table::TYPE_INT, 4);
$conn_table->column("symbols", Table::TYPE_STRING, 65535);
$conn_table->create();

// Stock prices table
$stock_price_table = new Table(2048);
$stock_price_table->column("symbol", Table::TYPE_STRING, 16);
$stock_price_table->column("price", Table::TYPE_FLOAT);
$stock_price_table->column("reference_count", Table::TYPE_INT, 8);
$stock_price_table->create();

$server = new Server("0.0.0.0", 9502);
$server->stock_price_table = $stock_price_table;
$server->conn_table = $conn_table;

$fmp = new FmpStockClient("xxx");


/**
 * Get realtime prices for specified symbols and saves them into table.
 *
 * @param array $symbols
 * @return array
 */
function saveStocksPrice(array $symbols): array
{
    global $fmp;
    global $stock_price_table;

    $data = [];
    $stock_prices = $fmp->realtimePrices($symbols);

    foreach ($stock_prices->getAll() as $stock_price) {
        $stock_price_table->set(
            $stock_price->getSymbol(),
            [
                "symbol" => $stock_price->getSymbol(),
                "price" => $stock_price->getPrice()
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
 * @param string $symbols
 * @return array
 */
function getStocksPrice(string $symbols): array
{
    global $stock_price_table;

    $data = [];
    $symbols = explode(",", $symbols);

    // Iterate through specified symbols and check, if stock price is already saved in table
    foreach ($symbols as $key => $symbol) {
        if ($stock_price_table->exists($symbol)){
            $stock_price = $stock_price_table->get($symbol, "price");

            $data[] = new RealtimePrice([
                "symbol" => $symbol,
                "price" => $stock_price
            ]);

            // Remove found symbol from array, it's price is already known
            unset($symbols[$key]);
        }
    }

    // If there are any symbols, which aren't in stock prices table, get realtime price
    if (!empty($symbols)){
        array_merge($data, saveStocksPrice($symbols));
    }

    // Return stock prices as array
    return array_map(
        fn (RealtimePrice $stockPrice) => [
            "symbol" => $stockPrice->getSymbol(),
            "price" => $stockPrice->getPrice()
        ],
        $data
    );
}

/**
 * Increases specified symbol(s) reference count.
 *
 *
 * @param string|array $symbols
 */
function increaseSymbolReference(string|array $symbols){
    global $stock_price_table;

    if (is_array($symbols)){
        foreach ($symbols as $symbol) {
            increaseSymbolReference($symbol);
        }
    } else {
        $stock_price_table->incr($symbols, "reference_count");
    }
}

/**
 * Decreases specified symbol(s) reference count.
 *
 * @param string|array $symbols
 */
function decreaseSymbolReference(string|array $symbols){
    global $stock_price_table;

    if (is_array($symbols)){
        foreach ($symbols as $symbol) {
            decreaseSymbolReference($symbol);
        }
    } else {
        $stock_price_table->decr($symbols, "reference_count");
    }
}

/**
 * Must be called after every incoming message.
 * Takes requested symbols, compares it against previously requested symbols and recounts references
 *
 * @param int $fd
 * @param string $new_symbols
 */
function countReferences(int $fd, string $new_symbols){
    global $conn_table;

    $new_symbols_array = explode(",", $new_symbols); // Requested symbols
    $symbols = $conn_table->get($fd, "symbols"); // Previously requested symbols

    // If user hasn't requested any stock prices yet, increase new symbols reference count
    if (!empty($symbols)){
        $symbols_array = explode(",", $symbols);

        $decrease_symbols = array_diff($symbols_array, $new_symbols_array);
        $increase_symbols = array_diff($new_symbols_array, $symbols_array);

        decreaseSymbolReference($decrease_symbols);
        increaseSymbolReference($increase_symbols);
    } else {
        increaseSymbolReference($new_symbols_array);
    }
}

$server->on("Start", function(Server $server)
{
    echo "Swoole WebSocket Server is started at http://127.0.0.1:9502\n";

    // Send updated stock prices to user every 5 seconds
    $server->tick(5000, function() use ($server)
    {
        $symbols_to_update = [];

        // Get symbols with at least one reference
        foreach ($server->stock_price_table as $stock_price) {
            if ($stock_price["reference_count"]) $symbols_to_update[] = $stock_price["symbol"];
        }

        // If there are any symbols to update, save its price to table
        if (!empty($symbols_to_update)) saveStocksPrice($symbols_to_update);

        // Send updated prices to users, which requested at least one stock price
        foreach ($server->conn_table as $conn) {
            if (!empty($conn["symbols"])) $server->push($conn["fd"], json_encode(getStocksPrice($conn["symbols"])));
        }
    });
});

$server->on('Open', function(Server $server, Request $request)
{
    // Save connection to table
    $server->conn_table->set($request->fd, ["fd" => $request->fd, "symbols" => ""]);
});

$server->on('Message', function(Server $server, Frame $frame)
{
    // Send stock prices to user based on frame data
    $server->push($frame->fd, json_encode(getStocksPrice($frame->data)));

    // Count symbol references
    countReferences($frame->fd, $frame->data);

    // Save sent symbols to user in table
    $server->conn_table->set($frame->fd, ["fd" => $frame->fd, "symbols" => $frame->data]);
});

$server->on('Close', function(Server $server, int $fd)
{
    // Remove users' symbol references and delete him from table
    decreaseSymbolReference(explode(",", $server->conn_table->get($fd, "symbols")));
    $server->conn_table->del($fd);
});

$server->on('Disconnect', function(Server $server, int $fd)
{
    // Remove users' symbol references and delete him from table
    decreaseSymbolReference(explode(",", $server->conn_table->get($fd, "symbols")));
    $server->conn_table->del($fd);
});

$server->start();