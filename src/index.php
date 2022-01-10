<?php

use App\Ticker\FmpTickerClient;
use App\Ticker\Result\Fmp\StockPrice;
use Swoole\Table;
use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;

include_once __DIR__ . "/vendor/autoload.php";

include_once __DIR__ . "/Ticker/Result/Fmp/StockPrice.php";
include_once __DIR__ . "/Ticker/FmpTickerClient.php";

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

$fmp = new FmpTickerClient();

function getStockPrice(string $symbols): array
{
    global $fmp;
    global $stock_price_table;

    $data = [];
    $symbols = explode(",", $symbols);

    foreach ($symbols as $symbol) {
        if ($stock_price_table->exists($symbol)){
            $stock_price = $stock_price_table->get($symbol, "price");

            $data[] = new StockPrice([
                "symbol" => $symbol,
                "price" => $stock_price
            ]);

            unset($symbol);
        }
    }

    $stock_prices = $fmp->price($symbols);

    foreach ($stock_prices as $stock_price) {
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

    return array_map(
        fn (StockPrice $stockPrice) => $stockPrice->toArray(),
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

$server->on('Message', function(Server $server, Frame $frame) use ($fmp)
{
    echo "received message: {$frame->data}\n";

    $server->conn_table->set($frame->fd, ["fd" => $frame->fd, "symbols" => $frame->data]);

    $server->push($frame->fd, json_encode(getStockPrice($frame->data)));
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