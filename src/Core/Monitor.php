<?php

namespace IBotMex\Core;

use DateTime;
use DateInterval;
use IBotMex\Api\Request;
use IBotMex\Core\DB;

final class Monitor
{
    public const MARGIN_ACCOUNT = 3.6;
    public const MARGIN_SYMBOL = 0.6;
    public const PNL_HOUR = 3.5;
    public const PRICE_CHANGE_PERCENT = 10;
    public const MAX_TRY = 0;
    public const MARGIN_INDIVIDUAL_MIN = 5;
    public const MARGIN_INDIVIDUAL_MAX = 15;

    private $configs = null;
    private $request = null;
    private $db = null;
    private $operations = false;
    private $position = [];
    private $debug = false;
    private $walletBalance = 0.00;

    public function __construct(Configurations $configs, Request $request, DB $db)
    {
        $this->configs = $configs;
        $this->request = $request;
        $this->db = $db;
    }

    private function __clone() {}

    public function init(): void
    {
        $accountBalance = $this->getAccountBalance();
        $account = $this->getAccountInformation();

        if (($account['status'] ?? 0) !== 200) {
            return;
        }

        $totalWalletBalance = $account['response']['totalWalletBalance'] ?? 0;
        $totalMaintMargin = $account['response']['totalMaintMargin'] ?? 0;
        $pnlHour = (float) ($accountBalance['pnl_hour'] ?? 0);
        $this->walletBalance = $totalWalletBalance;

        if ((int) date('i') % 5 === 0) {
            if (!$accountBalance) {
                $this->executeSql(
                    sprintf(
                        "INSERT INTO account_balance (value, variation, created_at) VALUES (%s, 0, '%s')",
                        $totalWalletBalance,
                        date('Y-m-d H:i:s')
                    )
                );
            } else {
                $variationDaily = bcsub((string) $totalWalletBalance, (string) $accountBalance['value'], 8);

                $this->executeSql(
                    sprintf(
                        "DELETE FROM account_balance WHERE DATE(created_at) = '%s' AND id != %s",
                        date('Y-m-d'),
                        $accountBalance['id']
                    )
                );

                $this->executeSql(
                    sprintf(
                        "UPDATE account_balance SET variation = %s, updated_at = '%s' WHERE id = %s",
                        $variationDaily,
                        date('Y-m-d H:i:s'),
                        $accountBalance['id']
                    )
                );

                $pnlHour = bcdiv((string) $variationDaily, (string) $this->getIntervals(), 8);

                if ((int) date('s') % 30 === 0) {
                    printf(
                        "%s%s %.4f USDT - %s%s %.4f USDT %s%s %.4f\n",
                        $this->textColor('yellow', 'Balance:'),
                        $this->textColor('white', ''),
                        $totalWalletBalance,
                        $this->textColor('yellow', 'Daily variation:'),
                        $this->textColor('white', ''),
                        $variationDaily,
                        $this->textColor('yellow', 'Pnl Hour:'),
                        $this->textColor('white', ''),
                        $pnlHour
                    );
                }

                $this->executeSql(
                    sprintf(
                        "UPDATE account_balance SET pnl_hour = %s, updated_at = '%s' WHERE id = %s",
                        $pnlHour,
                        date('Y-m-d H:i:s'),
                        $accountBalance['id']
                    )
                );
            }
        }

        if ((float) $totalMaintMargin >= $this->calcPercentage($totalWalletBalance, self::MARGIN_ACCOUNT)) {
            if ((int) date('s') % 30 === 0) {
                echo $this->textColor('red', "Maximum margin used [Account]\n");
            }

            $this->setOperations(false);
        }

        if (abs($pnlHour) >= self::PNL_HOUR) {
            if ((int) date('s') % 30 === 0) {
                echo $this->textColor('red', "maximum pnl per hour {$pnlHour} USDT\n");
            }

            $this->setOperations(false);
        }

        if (!$this->position()) {
            return;
        }

        $this->orderAnalyser();

        if ($this->availableOrders($this->position)) {
            $infoPrices = $this->infoPrice();

            if (!empty($infoPrices)) {
                $this->operation($infoPrices['scene'], $infoPrices['prices']);
            }
        }
    }

    private function getIntervals(): int
    {
        $hour = (int) date('H');
        $intervals = (($hour+1) * 60) / 5;

        return $intervals;
    }

    private function executeSql(string $sql): bool
    {
        return $this->db->exec($sql);
    }

    private function fetchSql(string $sql): array
    {
        $result = $this->db->querySingle($sql, true);

        if (!$result) {
            return [];
        }

        return (array) $result;
    }

    private function getAccountBalance()
    {
        return $this->fetchSql(
            sprintf(
                "SELECT * FROM account_balance WHERE DATE(created_at) = '%s';",
                date('Y-m-d')
            )
        );
    }

    private function position(): array
    {
        $response = $this->getPosition();

        if (($response['status'] ?? 0) !== 200) {
            print_r($response);
            echo $this->configs->getSymbol().PHP_EOL;
            echo 'LINE: '.__LINE__.PHP_EOL;
            return [];
        }

        $this->position = $response['response'][0];

        return $this->position;
    }

    private function infoPrice(bool $btc = false, int $limit = 5): array
    {
        $candles = $this->getCandles($btc, $limit)['response'] ?? [];

        if (empty($candles)) {
            return [];
        }

        $prices = $this->relationPrices($candles);

        if (empty($prices)) {
            return [];
        }

        $scene = $this->evaluationOfScene($candles, $btc);

        return [
            'scene' => $scene,
            'prices' => $prices
        ];
    }

    private function nextFunding(): string
    {
        $fundings = ['05', '13', '21'];
        $now = new DateTime('now');

        foreach ($fundings as $funding) {
            if ($now->format('H') < $funding) {
                return $funding;
            }
        }

        return $fundings[0];
    }

    public function isMonitoring(): bool
    {
        $now = new DateTime('now');
        $nex_funding = $now->format(sprintf('d-m-Y %s:00:00', $this->nextFunding()));
        $funding = new DateTime($nex_funding);
        $time = $funding->diff($now);

        $minutes = ((int) $time->format('%H')) * 60;
        $minutes += (int) $time->format('%i');

        return $minutes >= 2;
    }

    private function orderAnalyser(): void
    {
        $response = $this->waitRateLimit(
            $this->request->get('/openOrders', [
                'symbol' => $this->configs->getSymbol()
            ])
        );

        if (($response['status'] ?? 0) !== 200) {
            print_r($response);
            echo $this->configs->getSymbol().PHP_EOL;
            echo 'LINE: '.__LINE__.PHP_EOL;
            return;
        }

        foreach($response['response'] as $order) {
            $type = Position::typeOrder($this->position['positionAmt']);

            if (!$type || strnatcasecmp($order['side'], $type) === 0) {
                if ($order['status'] != 'NEW') {
                    continue;
                }

                if ($this->isTimeBoxOrder((int) $order['time'])) {
                    if ($this->debug) {
                        printf($this->textColor('blue', "%s Order canceled\n"), $order['orderId']);
                    }

                    $this->cancelOrder($order['orderId']);
                }
            }
        }
    }

    private function isTimeBoxOrder(int $orderTime, bool $closePosition = false): bool
    {
        $timeoutOrder = $this->configs->getTimeoutOrder();
        $timeoutOrder *= $closePosition ? 2 : 1;

        return $this->getTimeOrder($orderTime) >= $timeoutOrder;
    }

    private function getTimeOrder(int $orderTime): int
    {
        $time_order = new DateTime('@'. (int) ($orderTime / 1e3));
        $time_order->sub(new DateInterval('PT3H'));
        $time_now = new DateTime('now');
        $time = $time_order->diff($time_now);

        $time_box = (int) $time->format('%i') * 60;
        $time_box += (int) $time->format('%s');

        return $time_box;
    }

    private function cancelOrder(string $order): array
    {
        $orderCancel = $this->waitRateLimit(
            $this->request->delete('/order', [
                'symbol' => $this->configs->getSymbol(),
                'orderID' => $order
            ])
        );
        $orderProfitCancel = [];
        $try = 1;

        if (!$this->configs->getScalper()) {
            if ($orderCancel['status'] === 200) {
                $orderProfit = sprintf('profit-order-%s', $order);

                do {
                    $orderProfitCancel = $this->waitRateLimit(
                        $this->request->delete('/order', [
                            'symbol' => $this->configs->getSymbol(),
                            'origClientOrderId' => $orderProfit
                        ])
                    );
                    $try++;
                    sleep(1);

                    if ($orderProfitCancel['status'] !== 200) {
                        print_r($orderProfitCancel);
                        echo $this->configs->getSymbol().PHP_EOL;
                        echo 'LINE: '.__LINE__.PHP_EOL;
                    }
                } while ($this->checkStatusResponse((int) $orderProfitCancel['status']) && $try <= self::MAX_TRY);
            }
        }

        return [
            'order_cancel' => $orderCancel,
            'order_profit_cancel' => $orderProfitCancel
        ];
    }

    private function calcPercentage(float $value1, float $percentage): float
    {
        $value = bcmul((string) $value1, (string) (100 - $percentage), 4);
        $value = bcdiv($value, '100', 4);

        return (float) bcsub((string) $value1, $value, 4);
    }

    private function availableOrders(array $position): bool
    {
        if (empty($position)) {
            return false;
        }

        $side = ucfirst(Position::typeOrder($position['positionAmt']));
        $entryPrice = (float) $position['entryPrice'];
        $markPrice = (float) $position['markPrice'];
        $unRealizedProfit = (float) $position['unRealizedProfit'];
        $notional = (float) $position['notional'];
        $leverage = (int) $position['leverage'];
        $symbol = $position['symbol'];
        $margin = $notional / $leverage;
        $diffPriceLoss = $this->calcPercentage($entryPrice, $this->configs->getLossPosition());
        $diffPriceGain = $this->calcPercentage($entryPrice, $this->configs->getProfit() / $this->configs->getLeverage());
        $pnlPosition = $this->calcPercentage($margin, 20);
        $pnlPositionPercentGain = $this->calcPercentage($margin, 20);
        $pnlPositionPercentLoss = $this->calcPercentage($margin, 20);
        $marginIndividual = self::MARGIN_INDIVIDUAL_MIN;
        $responseBook = $this->getBook();

        if (($responseBook['status'] ?? 0) !== 200) {
            return false;
        }

        if ($this->walletBalance) {
            $calcMarginIndividual = $this->calcPercentage($this->walletBalance, self::MARGIN_SYMBOL);

            if ($calcMarginIndividual > self::MARGIN_INDIVIDUAL_MIN) {
                $marginIndividual = $calcMarginIndividual < self::MARGIN_INDIVIDUAL_MAX
                    ? $calcMarginIndividual
                    : self::MARGIN_INDIVIDUAL_MAX;
            }
        }

        if ($this->operations && $margin >= $marginIndividual) {
            $this->setOperations(false);
            echo $this->textColor('red', "Maximum margin used [{$symbol}]\n");
        }

        $book = $responseBook['response'];
        $bookPriceBuy = $book['bids'][4][0];
        $bookPriceSell = $book['asks'][4][0];
        $force = false;

        $infoPriceBtc = $this->getInfoPriceBtc(1);
        $hasDiffPriceBtc = $infoPriceBtc && ($infoPriceBtc['enable'] && $infoPriceBtc['type'] != strtolower($side));

        if ($side == 'Sell') {
            $priceLoss = (float) bcadd((string) $entryPrice, (string) $diffPriceLoss, 4);
            $priceGain = (float) bcsub((string) $entryPrice, (string) $diffPriceGain, 4);
            $position['positionAmt'] *= -1;
            $msg = '';
            $priceClose = 0;
            $result = '';

            if ($markPrice < $priceGain || $unRealizedProfit > $pnlPosition || $unRealizedProfit >= 2 && $unRealizedProfit >= $pnlPositionPercentGain
                || $unRealizedProfit >= $pnlPositionPercentGain && $hasDiffPriceBtc
            ) {
                $msg = "Maximum %s [%.4f] - (%.4f < %.4f) | %.4f USDT [%s]\n";
                $result = $this->textColor('green', 'gain');
                $priceClose = $priceGain;
                // $force = true;
            }

            /*
            if ($markPrice > $priceLoss || $unRealizedProfit < 0 && $unRealizedProfit <= ($margin * -1)
                || $unRealizedProfit <= -1 || $unRealizedProfit <= ($pnlPositionPercentLoss * -1) && $hasDiffPriceBtc
            ) {
                $msg = "Maximum %s [%.4f] - (%.4f > %.4f) | %.4f USDT [%s]\n";
                $result = $this->textColor('red', 'loss');
                $priceClose = $priceLoss;
                $force = true;
            }
            */

            if ($msg && $priceClose) {
                if ($this->debug) {
                    printf(
                        $msg,
                        $result,
                        $entryPrice,
                        $markPrice,
                        $priceClose,
                        $unRealizedProfit,
                        $symbol
                    );
                }

                $paramsClose = [
                    'type' => 'buy',
                    'quantity' => $position['positionAmt'],
                    'price' => $bookPriceBuy,
                    'force' => $force
                ];
                $this->closePosition($paramsClose);

                return false;
            }
        }

        if ($side == 'Buy') {
            $priceLoss = (float) bcsub((string) $entryPrice, (string) $diffPriceLoss, 4);
            $priceGain = (float) bcadd((string) $entryPrice, (string) $diffPriceGain, 4);
            $msg = '';
            $result = '';
            $priceClose = 0;

            if ($markPrice > $priceGain || $unRealizedProfit > $pnlPosition || $unRealizedProfit >= 2 && $unRealizedProfit >= $pnlPositionPercentGain
                || $unRealizedProfit >= $pnlPositionPercentGain && $hasDiffPriceBtc
            ) {
                $msg = "Maximum %s [%.4f] - (%.4f > %.4f) | %.4f USDT [%s]\n";
                $result = $this->textColor('green', 'gain');
                $priceClose = $priceGain;
                // $force = true;
            }

            /*
            if ($markPrice < $priceLoss || $unRealizedProfit < 0 && $unRealizedProfit <= ($margin * -1)
                || $unRealizedProfit <= -1 || $unRealizedProfit <= ($pnlPositionPercentLoss * -1) && $hasDiffPriceBtc
            ) {
                $msg = "Maximum %s [%.4f] - (%.4f < %.4f) | %.4f USDT [%s]\n";
                $result = $this->textColor('red', 'loss');
                $priceClose = $priceLoss;
                $force = true;
            }
            */

            if ($msg && $priceClose) {
                if ($this->debug) {
                    printf(
                        $msg,
                        $result,
                        $entryPrice,
                        $markPrice,
                        $priceClose,
                        $unRealizedProfit,
                        $symbol
                    );
                }

                $paramsClose = [
                    'type' => 'sell',
                    'quantity' => $position['positionAmt'],
                    'price' => $bookPriceSell,
                    'force' => $force
                ];
                $this->closePosition($paramsClose);

                return false;
            }
        }

        /*
        if ($position['positionAmt'] >= $this->configs->getMaxContracts()) {
            if ($this->debug) {
                echo $this->textColor('blue', "Maximum position\n");
            }

            return false;
        }
        */

        $responseOrders = $this->getOrders();

        if (($responseOrders['status'] ?? 0) !== 200) {
            return false;
        }

        $orders = $responseOrders['response'];
        $contracts = 0;

        if (!empty($orders)) {
            /*
            foreach ($orders as $order) {
                if (strnatcasecmp($order['side'], $side) === 0) {
                    $contracts += $order['origQty'];
                }
            }

            if ($contracts >= $this->configs->getMaxContracts()) {
                if ($this->debug) {
                    echo $this->textColor('blue', "Maximum position\n");
                }

                return false;
            }
            */

            if (count($orders) >= $this->configs->getMaxOrders()) {
                if ($this->debug) {
                    echo $this->textColor('blue', "Maximum open orders\n");
                }

                return false;
            }
        }

        return $this->operations;
    }

    private function closePosition(array $params): void
    {
        if ($this->configs->getClosePosition()) {
            $orders = $this->getOrders()['response'];

            if ($position = $this->position()) {
                $type = Position::typeOrder($position['positionAmt'] ?? '');
                $breakOrder = false;

                foreach ($orders as $order) {
                    if ($type != strtolower($order['side']) && $order['status'] != 'NEW') {
                        $breakOrder = true;
                        break;
                    }

                    if (!$this->isTimeBoxOrder((int) $order['time'], true)) {
                        return;
                    }

                    $result = $this->cancelOrder($order['orderId']);

                    if ($result['order_cancel']['status'] !== 200) {
                        $breakOrder = true;
                    }
                }

                if (!$breakOrder) {
                    if ($type) {
                        $this->order($params);
                    }

                    if ($this->debug) {
                        echo $this->textColor('red', "Closed position\n");
                    }
                } else {
                    echo $this->textColor('red', "Failed to create position close order\n");
                }
            }
        }
    }

    private function getPosition(): array
    {
        return $this->waitRateLimit(
            $this->request->get('/positionRisk', [
                'symbol' => $this->configs->getSymbol()
            ])
        );
    }

    private function getOrders(): array
    {
        return $this->waitRateLimit(
            $this->request->get('/openOrders', [
                'symbol' => $this->configs->getSymbol()
            ])
        );
    }

    private function getCandles(bool $btc = false, int $limit = 5): array
    {
        $return = $this->waitRateLimit(
            $this->request->get('/continuousKlines', [
                'pair' => !$btc ? $this->configs->getSymbol() : 'BTCUSDT',
                'contractType' => 'PERPETUAL',
                'interval' => '15m',
                'limit' => $limit
            ])
        );

        if (($return['status'] ?? 0) !== 200) {
            return [];
        }

        $return['response'] = array_map(function(array $v) {
            $keys = [
                'open_time',
                'open',
                'high',
                'low',
                'close',
                'volume',
                'close_time',
                'quote_asset_volume',
                'number_of_trades',
                'taker_buy_volume',
                'taker_buy_quote_asset_volume',
                'ignore'
            ];

            return array_combine($keys, $v);
        }, $return['response']);

        return $return;
    }

    private function relationPrices(array $candles): array
    {
        $prices_avaliable = [];

        foreach($candles as $candle) {
            $prices_avaliable['low'][] = $candle['low'];
            $prices_avaliable['high'][] = $candle['high'];
        }

        if (empty(min($prices_avaliable['low'])) ||
            empty(max($prices_avaliable['high']))
        ) {
            return [];
        }

        return [
            'price_buy' => Position::calculePriceOrder(
                'buy',
                min($prices_avaliable['low']),
                $this->configs->getProfit(),
                $this->configs->getLeverage()
            ),
            'price_sell' => Position::calculePriceOrder(
                'sell',
                max($prices_avaliable['high']),
                $this->configs->getProfit(),
                $this->configs->getLeverage()
            ),
        ];
    }

    private function evaluationOfScene(array $candles, bool $btc = false): array
    {
        $high = 0;
        $low = 0;
        $now = 0;
        $current_candle = 0;

        foreach($candles as $candle) {
            if ($candle['close'] > $candle['open']) {
                $high++;
                $now++;
            } else {
                $low++;
                $now--;
            }

            $current_candle++;
        }

        $key = Position::arrayKeyLast($candles);

        if ($candles[$key]['close'] > $candles[$key]['open']) {
            $analyser_last = 'buy';
        } else {
            $analyser_last = 'sell';
        }

        return $this->sceneToOperation([
            'high' => $high,
            'low' => $low,
            'now' => $now,
            'close' => $candle['close'],
            'last' => $analyser_last
        ], $btc);
    }

    private function sceneToOperation(array $params, bool $btc = false): array
    {
        $type_order = '';
        $open_order = false;

        // buy
        if ($params['now'] >= 1 && $params['last'] == 'buy') {
            $open_order = true;
            $type_order = 'buy';
        }

        // sell
        if ($params['now'] <= -1 && $params['last'] == 'sell') {
            $open_order = true;
            $type_order = 'sell';
        }

        $statics = $this->getStaticsTicker();

        if ($statics['status'] !== 200) {
            $open_order = false;
        }

        if ($open_order && !$btc) {
            $lastPrice = $statics['response']['lastPrice'];

            if ($type_order == 'buy' && $lastPrice < $params['close']) {
                $open_order = false;
            }

            if ($type_order == 'sell' && $lastPrice > $params['close']) {
                $open_order = false;
            }
        }

        return [
            'type' => $type_order,
            'enable' => $open_order
        ];
    }

    private function getBook(): array
    {
        return $this->waitRateLimit(
            $this->request->get('/depth', [
                'symbol' => $this->configs->getSymbol(),
                'limit' => 5
            ])
        );
    }

    public function resetColor(): string
    {
        return "\033[0m";
    }

    public function padText(string $text, bool $newLine = false): string
    {
        return str_pad($text, 60, ' ', \STR_PAD_RIGHT) . $newLine ? "\n" : '';
    }

    public function textColor(string $color, string $text): string
    {
        $color= strtolower($color);

        switch ($color) {
            case 'black':
                $color = "\033[1;30m";
                break;
            case 'red':
                $color = "\033[1;31m";
                break;
            case 'green':
                $color = "\033[1;32m";
                break;
            case 'yellow':
                $color = "\033[1;33m";
                break;
            case 'blue':
                $color = "\033[1;34m";
                break;
            case 'magenta':
                $color = "\033[1;35m";
                break;
            case 'cyan':
                $color = "\033[1;36m";
                break;
            case 'white':
                $color = "\033[1;37m";
                break;
            default:
                $color = "\033[1;30m";
        }

        return sprintf("%s%s%s", $color, $text, $this->resetColor());
    }

    public function textBackgroundColor(string $color, string $text): string
    {
        $color= strtolower($color);

        switch ($color) {
            case 'black':
                $color = "\033[1;40m";
                break;
            case 'red':
                $color = "\033[1;41m";
                break;
            case 'green':
                $color = "\033[1;42m";
                break;
            case 'yellow':
                $color = "\033[1;43m";
                break;
            case 'blue':
                $color = "\033[1;44m";
                break;
            case 'magenta':
                $color = "\033[1;45m";
                break;
            case 'cyan':
                $color = "\033[1;46m";
                break;
            case 'white':
                $color = "\033[1;47m";
                break;
            default:
                $color = "\033[1;40m";
        }

        return sprintf("%s%s", $color, $text);
    }

    private function getInfoPriceBtc(int $limit = 5): array
    {
        return $this->infoPrice(true, $limit)['scene'] ?? [];
    }

    private function operation(array $operation, array $prices): void
    {
        if ($this->configs->getSide() !== '.') {
            if (strnatcasecmp($this->configs->getSide(), $operation['type']) !== 0) {
                return;
            }
        }

        if (empty($operation['type'])) {
            return;
        }

        if (!empty($this->position)) {
            $type = Position::typeOrder($this->position['positionAmt']);

            if ($type && $operation['type'] != $type) {
                $operation['enable'] = false;
            }
        }

        if ($operation['enable']) {
            $responseBook = $this->getBook();

            if (($responseBook['status'] ?? 0) !== 200) {
                return;
            }

            $book = $responseBook['response'];
            $param_order = $this->paramsOfOrder([
                'type' => $operation['type'],
                'contracts' => $this->configs->getOrderContracts(),
                'sell' => [
                    'book' => $book['asks'][4][0],
                    'candle' => $prices['price_sell']
                ],
                'buy' => [
                    'book' => $book['bids'][4][0],
                    'candle' => $prices['price_buy']
                ],
            ]);

            $price_order_close = Position::calculePriceOrder(
                $param_order['type'],
                $param_order['price'],
                $this->configs->getProfit(),
                $this->configs->getLeverage()
            );

            $profit = Position::calculeProfitOrder(
                $this->configs->getProfit(),
                $this->configs->getLeverage()
            );
            $statics = $this->getStaticsTicker();

            if ($statics['status'] == 200) {
                $check_price = $param_order['type'] == 'buy'
                    ? $statics['response']['highPrice']
                    : $statics['response']['lowPrice'];

                $diff_price = Position::percentage(
                    $price_order_close,
                    $check_price
                );

                // Gain greater than max/min or close to margin
                if ($diff_price >= 0 ||
                    abs($diff_price) <= ($profit * 0.8)
                ) {
                    return;
                }

                $lastPrice = (float) $statics['response']['lastPrice'];
                $priceChangePercent = abs(Position::percentage($lastPrice, $check_price));

                if ($priceChangePercent >= self::PRICE_CHANGE_PERCENT) {
                    return;
                }
            } else {
                return;
            }

            if ($param_order['type'] === 'buy') {
                if ($price_order_close <= $param_order['price']) {
                    return;
                }
            } else {
                if ($price_order_close >= $param_order['price']) {
                    return;
                }
            }

            $infoPriceBtc = $this->getInfoPriceBtc();

            if ($operation['enable'] && $infoPriceBtc && (!$infoPriceBtc['enable'] || $infoPriceBtc['type'] != $operation['type'])) {
                $colorSide1 = $operation['type'] == 'buy' ? 'green' : 'red';
                $enable1 = $operation['enable'] ? 'yes' : 'no';
                $colorEnable1 = $enable1 == 'yes' ? 'green' : 'red';
                $colorSide2 = $infoPriceBtc['type'] == 'buy' ? 'green' : 'red';
                $enable2 = $infoPriceBtc['enable'] ? 'yes' : 'no';
                $colorEnable2 = $enable2 == 'yes' ? 'green' : 'red';
                $infoPriceBtc['type'] = $infoPriceBtc['type'] ? $infoPriceBtc['type'] : '-';
                $operation['enable'] = false;

                if ($this->debug) {
                    print(str_repeat('-', 60)."\n");

                    if ($operation['enable']) {
                        printf($this->textColor('green', "Order activated\n"));
                    } else {
                        printf($this->textColor('red', "Order not activated\n"));
                    }

                    printf("Symbol: %s\n", $this->configs->getSymbol());
                    printf("Pair side: %s\n", $this->textColor($colorSide1, $operation['type']));
                    printf("Pair enable: %s\n", $this->textColor($colorEnable1, $enable1));
                    printf("Btc side: %s\n", $this->textColor($colorSide2, $infoPriceBtc['type']));
                    printf("Btc enable: %s\n", $this->textColor($colorEnable2, $enable2));

                    print(str_repeat('-', 60)."\n");
                }
            }

            if ($this->operations && $operation['enable']) {
                if ($this->debug) {
                    $colorSide = $param_order['type'] == 'buy' ? 'green' : 'red';

                    print(str_repeat('-', 60)."\n");
                    printf("Symbol: %s\n", $this->configs->getSymbol());
                    printf("Side: %s\n", $this->textColor($colorSide, $param_order['type']));
                    printf("Open: %s\n", $param_order['price']);
                    printf("Close: %s\n", $price_order_close);
                    printf("Profit: %s%%\n", $profit * 100);
                    printf("Quantity: %s\n", $param_order['quantity']);
                    print(str_repeat('-', 60)."\n");
                }

                $this->orderProfit($param_order, $price_order_close);
            }
        }
    }

    private function orderProfit(array $params, float $priceClose): array
    {
        $orderCreate = $this->order($params);
        $orderProfit = [];
        $try = 1;

        if ($orderCreate['status'] === 200) {
            if (!$this->configs->getScalper()) {
                $params['type'] = $params['type'] === 'sell' ? 'buy' : 'sell';
                $params['price'] = $priceClose;
                $params['newClientOrderId'] = sprintf('profit-order-%s', $orderCreate['response']['orderId']);

                do {
                    $orderProfit = $this->order($params);
                    $try++;
                    sleep(1);

                    if ($orderProfit['status'] !== 200) {
                        print_r($orderProfit);
                        echo $this->configs->getSymbol().PHP_EOL;
                        echo 'LINE: '.__LINE__.PHP_EOL;
                    }
                } while ($this->checkStatusResponse((int) $orderProfit['status']) && $try <= self::MAX_TRY);
            }
        }

        return [
            'order_create' => $orderCreate,
            'order_profit' => $orderProfit
        ];
    }

    private function checkStatusResponse(int $status): bool
    {
        return !in_array($status, [200, 400]);
    }

    private function paramsOfOrder(array $param): array
    {
        if ($param['type'] == 'sell') {
            $price = $param['sell']['candle'];

            if ($param['sell']['book'] != $param['sell']['candle']) {
                $price = $param['sell']['book'];
            }
        }

        if ($param['type'] == 'buy') {
            $price = $param['buy']['candle'];

            if ($param['buy']['book'] != $param['buy']['candle']) {
                $price = $param['buy']['book'];
            }
        }

        return [
            'type' => $param['type'],
            'quantity' => $param['contracts'],
            'price' => (float) $price
        ];
    }

    private function order(array $params): array
    {
        $params_request = [
            'symbol' => $this->configs->getSymbol(),
            'side' => strtoupper($params['type']),
            'quantity' => $params['quantity'],
            'type' => 'LIMIT',
        ];

        if ($params['force'] ?? false) {
            $params_request['type'] = 'MARKET';
        } else {
            $params_request['price'] = $params['price'];
            $params_request['timeInForce'] = 'GTC';
        }

        $try = 1;

        if (!empty($params['newClientOrderId'])) {
            $params_request['newClientOrderId'] = $params['newClientOrderId'];
        }

        do {
            $order = $this->waitRateLimit(
                $this->request->post('/order', $params_request)
            );
            $try++;
            sleep(1);

            if ($order['status'] !== 200) {
                print_r($order);
                echo $this->configs->getSymbol().PHP_EOL;
                echo 'LINE: '.__LINE__.PHP_EOL;
            }
        } while ($this->checkStatusResponse((int) $order['status']) && $try <= self::MAX_TRY);

        return $order;
    }

    private function getStaticsTicker(): array
    {
        return $this->waitRateLimit(
            $this->request->get('/ticker/24hr', [
                'symbol' => $this->configs->getSymbol()
            ])
        );
    }

    private function getAccountInformation(): array
    {
        $request = $this->request->get('/account', []);

        if (!$request) {
            return [];
        }

        return $this->waitRateLimit($request);
    }

    private function waitRateLimit(array $request): array
    {
        $limit = $request['rate_limit']['1m'] ?? 2300;
        $rate = $limit / 60;
        $seconds = (int) date('s');
        $delay = 60 - $seconds;

        if ($rate >= 36) {
            // echo $this->textColor('cyan', "Request limit control [{$limit}]\n");
            sleep($delay);
        } else {
            $rate = (int) ($limit / ($seconds ?: 60));

            if ($rate >= 15) {
                usleep(0.9 * 1e6);
            }
        }

        if ($request['status'] !== 200) {
            print_r($request);
            echo $this->configs->getSymbol().PHP_EOL;
            echo 'LINE: '.__LINE__.PHP_EOL;
        }

        return $request;
    }

    public function setOperations(bool $operations): void
    {
        $this->operations = $operations;
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }
}
