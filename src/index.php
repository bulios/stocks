<?php

use Swoole\Http\Request;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use WebChemistry\Stocks\FmpStockClient;
use WebChemistry\Stocks\Result\Fmp\RealtimePrice;

include_once __DIR__ . "/vendor/autoload.php";

$conn_table = new Table(2048);
$conn_table->column("fd", Table::TYPE_INT, 4);
$conn_table->column("symbols", Table::TYPE_STRING, 65535);
$conn_table->create();

$stock_price_table = new Table(2048);
$stock_price_table->column("symbol", Table::TYPE_STRING, 16);
$stock_price_table->column("price", Table::TYPE_FLOAT);
$stock_price_table->column("reference_count", Table::TYPE_INT, 8);
$stock_price_table->create();

$server = new Server("0.0.0.0", 9502);
$server->stock_price_table = $stock_price_table;
$server->conn_table = $conn_table;

$fmp = new FmpStockClient("xxx");

function getStockPrice(string $symbols): array
{
    global $fmp;
    global $stock_price_table;

    $data = [];
    $symbols = explode(",", $symbols);

    foreach ($symbols as $key => $symbol) {
        if ($stock_price_table->exists($symbol)){
            $stock_price = $stock_price_table->get($symbol, "price");

            $data[] = new RealtimePrice([
                "symbol" => $symbol,
                "price" => $stock_price
            ]);

            unset($symbols[$key]);
        }
    }

    if (!empty($symbols)){
        $stock_prices = $fmp->realtimePrices($symbols);

        foreach ($stock_prices->getAll() as $stock_price) {
            $stock_price_table->set(
                $stock_price->getSymbol(),
                [
                    "symbol" => $stock_price->getSymbol(),
                    "price" => $stock_price->getPrice(),
                    "reference_count" => 0
                ]
            );

            $data[] = $stock_price;
        }
    }

    return array_map(
        fn (RealtimePrice $stockPrice) => [
            "symbol" => $stockPrice->getSymbol(),
            "price" => $stockPrice->getPrice()
        ],
        $data
    );
}

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

function countReferences(int $fd, string $new_symbols){
    global $conn_table;

    $new_symbols_array = explode(",", $new_symbols);
    $symbols = $conn_table->get($fd, "symbols");

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

    $server->tick(5000, function() use ($server)
    {
        foreach ($server->conn_table as $conn) {

        }
    });
});

$server->on('Open', function(Server $server, Request $request)
{
    echo "connection open: {$request->fd}\n";

    $server->conn_table->set($request->fd, ["fd" => $request->fd, "symbols" => ""]);
});

$server->on('Message', function(Server $server, Frame $frame)
{
    echo "received message: {$frame->data}\n";

    $server->push($frame->fd, json_encode(getStockPrice($frame->data)));

    countReferences($frame->fd, $frame->data);

    $server->conn_table->set($frame->fd, ["fd" => $frame->fd, "symbols" => $frame->data]);
});

$server->on('Close', function(Server $server, int $fd)
{
    echo "connection close: {$fd}\n";

    decreaseSymbolReference(explode(",", $server->conn_table->get($fd, "symbols")));
    $server->conn_table->del($fd);
});

$server->on('Disconnect', function(Server $server, int $fd)
{
    echo "connection disconnect: {$fd}\n";

    decreaseSymbolReference(explode(",", $server->conn_table->get($fd, "symbols")));
    $server->conn_table->del($fd);
});

$server->start();