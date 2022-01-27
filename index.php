<?php declare(strict_types = 1);

use App\StockServer;
use WebChemistry\Stocks\FmpStockClient;

require __DIR__ . '/vendor/autoload.php';

$server = new StockServer(new FmpStockClient('41b535586463a0697a7f2b16dc55fc59'));
$server->start();
