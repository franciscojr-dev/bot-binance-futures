<?php

namespace IBotMex\Core;

use DateTime;
use DateInterval;
use IBotMex\Api\Request;
use IBotMex\Core\DB;
use InvalidArgumentException;

final class Monitor
{
    private $configs = null;
    private $request = null;
    private $db = null;
    private $position = [];
    private $debug = false;
    private $walletBalance = 0.00;
    private $operations = false;
    private $marginAccount = 0;
    private $marginSymbol = 0;
    private $pnlHour = 0;
    private $enableBalanceOut = 0;
    private $percentBalanceOut = 0;
    private $fromBalanceOut = 0;
    private $priceChangePercent = 0;
    private $maxTry = 0;
    private $multipleOrder = 0;
    private $closeLossPosition = false;
    private $closeLossPositionHedge = false;
    private $closeLossPositionSoft = false;
    private $stopPreventive = false;
    private $checkMarginSafe = false;
    private $hedgePositionLong = true;
    private $hedgePositionShort = true;
    private $hedgeMode = false;
    private $softHedge = false;
    private $safePosition = false;
    private $orderReverse = false;
    private $maxOrders = 1;
    private $maxOrdersGeneral = 30;
    private $baseOrderAmount = 5;
    private $timeoutOrder = 10;
    private $multipleTimeoutOrder = 5;
    private $candleTime = '15m';
    private $candleLimit = 5;
    private $candleConsecutive = 1;
    private $candleClosed = false;
    private $amountGainMin = 0;
    private $amountPerOrder = 0;
    private $coveragePointDividerGain = 0;
    private $marginIndividualMin = 0;
    private $marginIndividualMax = 0;
    private $multiplePercentGain = 0;
    private $pnlAdditionalPercentGain = 0;
    private $pnlAdditionalPercentLoss = 0;
    private $pnlPositionPercentGain = 0;
    private $pnlPositionPercentLoss = 0;
    private $operationDisable = false;
    private $openSymbols = 0;

    public function __construct(
        Configurations $configs,
        Request $request,
        DB $db
    ) {
        $this->configs = $configs;
        $this->request = $request;
        $this->db = $db;

        $this->loadConfig();
    }

    private function __clone() {}

    private function loadConfig(): void
    {
        $path = dirname(__DIR__, 2);
        $config = parse_ini_file($path . '/configs/monitor.ini', true, INI_SCANNER_RAW);

        if (!$config) {
            $this->loadConfig();
            return;
        }

        $this->operationDisable = (bool) !($config['monitor']['operations'] ?? true);
        $this->marginAccount = $config['monitor']['margin_account'] ?? 0;
        $this->marginSymbol = $config['monitor']['margin_symbol'] ?? 0;
        $this->pnlHour = $config['monitor']['pnl_hour'] ?? 0;
        $this->enableBalanceOut = $config['monitor']['enable_balance_out'] ?? 0;
        $this->percentBalanceOut = $config['monitor']['percent_balance_out'] ?? 0;
        $this->fromBalanceOut = $config['monitor']['from_balance_out'] ?? 0;
        $this->priceChangePercent = $config['monitor']['price_change_percent'] ?? 0;
        $this->maxTry = $config['monitor']['max_try'] ?? 0;
        $this->multipleOrder = $config['monitor']['multiple_order'] ?? 0;
        $this->closeLossPosition = (bool) ($config['monitor']['close_loss_position'] ?? false);
        $this->closeLossPositionHedge = (bool) ($config['monitor']['close_loss_position_hedge'] ?? false);
        $this->closeLossPositionSoft = (bool) ($config['monitor']['close_loss_position_soft'] ?? false);
        $this->stopPreventive = (bool) ($config['monitor']['stop_preventive'] ?? false);
        $this->checkMarginSafe = (bool) ($config['monitor']['check_margin_safe'] ?? false);
        $this->hedgePositionLong = (bool) ($config['monitor']['hedge_position_long'] ?? false);
        $this->hedgePositionShort = (bool) ($config['monitor']['hedge_position_short'] ?? false);
        $this->hedgeMode = (bool) ($config['monitor']['hedge_mode'] ?? false);
        $this->softHedge = (bool) ($config['monitor']['soft_hedge'] ?? false);
        $this->safePosition = (bool) ($config['monitor']['safe_position'] ?? false);
        $this->orderReverse = (bool) ($config['monitor']['order_reverse'] ?? false);
        $this->maxOrders = $config['monitor']['max_orders'] ?? 1;
        $this->maxOrdersGeneral = $config['monitor']['max_orders_general'] ?? 30;
        $this->baseOrderAmount = $config['monitor']['base_order_amount'] ?? 5;
        $this->timeoutOrder = $config['monitor']['timeout_order'] ?? 10;
        $this->multipleTimeoutOrder = $config['monitor']['multiple_timeout_order'] ?? 5;
        $this->candleTime = $config['monitor']['candle_time'] ?? '15m';
        $this->candleLimit = $config['monitor']['candle_limit'] ?? 5;
        $this->candleConsecutive = $config['monitor']['candle_consecutive'] ?? 1;
        $this->candleClosed = (bool) ($config['monitor']['candle_closed'] ?? false);
        $this->amountGainMin = $config['monitor']['amount_gain_min'] ?? 0;
        $this->amountPerOrder = $config['monitor']['amount_per_order'] ?? 0;
        $this->coveragePointDividerGain = $config['monitor']['coverage_point_divider_gain'] ?? 0;
        $this->marginIndividualMin = $config['monitor']['margin_individual_min'] ?? 0;
        $this->marginIndividualMax = $config['monitor']['margin_individual_max'] ?? 0;
        $this->multiplePercentGain = $config['monitor']['multiple_percent_gain'] ?? 1;
        $this->pnlAdditionalPercentGain = $config['monitor']['pnl_additional_percent_gain'] ?? 0;
        $this->pnlAdditionalPercentLoss = $config['monitor']['pnl_additional_percent_loss'] ?? 0;
        $this->pnlPositionPercentGain = $config['monitor']['pnl_position_percent_gain'] ?? 0;
        $this->pnlPositionPercentLoss = $config['monitor']['pnl_position_percent_loss'] ?? 0;
    }

    public function init(): void
    {
        $accountBalance = $this->getAccountBalance();
        $account = $this->getAccountInformation();

        if (($account['status'] ?? 0) !== 200) {
            return;
        }

        $totalWalletBalance = $account['response']['totalWalletBalance'] ?? 0;
        $totalMarginBalance = $account['response']['totalMarginBalance'] ?? 0;
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

                if ($this->isPrintMessage()) {
                    $output = sprintf(
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

                    if ($this->debug) {
                        echo $output;
                    }
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

        $checkMargin = (float) $totalMaintMargin >= $this->calcPercentage($totalMarginBalance, $this->marginAccount);

        if ((int) date('i') % 55 === 0 && $accountBalance) {
            $this->executeSql(
                sprintf(
                    "UPDATE account_balance SET withdraw = %s, updated_at = '%s' WHERE id = %s",
                    '0',
                    date('Y-m-d H:i:s'),
                    $accountBalance['id']
                )
            );
        }

        if ((int) date('i') % 60 === 0 && !$checkMargin) {
            $accountBalance = $this->getAccountBalance();
            $account = $this->getAccountInformation();

            if (($account['status'] ?? 0) === 200 && $accountBalance) {
                $variationDaily = bcsub((string) $totalWalletBalance, (string) $accountBalance['value'], 8);

                if ($this->enableBalanceOut && $variationDaily >= $this->fromBalanceOut) {
                    $percentOut = bcdiv((string) $this->percentBalanceOut, '100', 5);
                    $balanceOut = bcmul((string) $variationDaily, $percentOut, 2);
                    $checkTransfer = $this->isPrintMessage() && rand(0, 1);

                    if (!$accountBalance['withdraw'] && $checkTransfer && $this->transferBalance((float) $balanceOut)) {
                        echo $this->textColor('green', "Withdraw of gains {$balanceOut} USDT\n");

                        $this->executeSql(
                            sprintf(
                                "UPDATE account_balance SET withdraw = %s, updated_at = '%s' WHERE id = %s",
                                '1',
                                date('Y-m-d H:i:s'),
                                $accountBalance['id']
                            )
                        );
                    }
                }
            }
        }

        if ($checkMargin) {
            if ($this->isPrintMessage()) {
                $output = $this->textColor('red', "Maximum margin used [Account]\n");

                if ($this->debug) {
                    echo $output;
                }
            }

            $this->operationDisable = true;
        }

        if (abs($pnlHour) >= $this->pnlHour) {
            if ($this->isPrintMessage()) {
                $output = $this->textColor('red', "maximum pnl per hour {$pnlHour} USDT\n");

                if ($this->debug) {
                    echo $output;
                }
            }

            $this->operationDisable = true;
        }

        if (!$this->position()) {
            return;
        }

        $this->orderAnalyser();
        $infoPrices = $this->infoPrice(false, $this->candleLimit);

        foreach ($this->position as $position) {
            if (!empty($infoPrices)) {
                if ($this->availableOrders($position, $infoPrices['scene'], $infoPrices['prices'])) {
                    $this->operation($infoPrices['scene'], $infoPrices['prices']);
                }
            }
        }
    }

    private function isPrintMessage(): bool {
        return (int) date('s') % 12 === 0;
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

    private function getPostionBySide(string $side): array
    {
        $positions = [];

        foreach ($this->position() as $position) {
            $positions[$position['positionSide']] = $position;
        }

        return $positions[$side];
    }

    private function position(): array
    {
        $response = $this->getPosition();

        if (($response['status'] ?? 0) !== 200) {
            $output = sprintf(
                "%s - %s \n",
                $this->configs->getSymbol(),
                $response['response']['msg']
            );

            if ($this->debug) {
                echo $output;
            }

            return [];
        }

        return $this->position = $response['response'];
    }

    private function infoPrice(bool $btc = false, int $limit = 3): array
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
            return;
        }

        foreach($response['response'] as $order) {
            $closePosition = (bool) ($this->hedgeMode ? $order['reduceOnly'] : false);
            $type = Position::typeOrder($this->position['positionAmt'] ?? 0);

            if (!$type || strnatcasecmp($order['side'], $type) === 0) {
                /*if ($order['status'] != 'NEW') {
                    continue;
                }*/

                if (!$closePosition && $this->isTimeBoxOrder((int) $order['time'], $closePosition)) {
                    $output = sprintf($this->textColor('blue', "%s Order canceled\n"), $order['orderId']);

                    if ($this->debug) {
                        echo $output;
                    }

                    $this->cancelOrder($order['orderId'] ?? '');
                }
            }
        }
    }

    private function isTimeBoxOrder(int $orderTime, bool $closePosition = false): bool
    {
        $timeoutOrder = $this->timeoutOrder;
        $timeoutOrder *= $closePosition ? $this->multipleTimeoutOrder : 1;

        return $this->getTimeOrder($orderTime) >= $timeoutOrder;
    }

    private function getTimeOrder(int $orderTime): int
    {
        $time_order = new DateTime('@'. (int) ($orderTime / 1e3));
        $time_order->sub(new DateInterval('PT3H'));
        $time_now = new DateTime('now');
        $time = $time_order->diff($time_now);

        $time_box = (int) ($time->format('%i')) * 60;
        $time_box += (int) ($time->format('%s'));

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

        if ($orderCancel['status'] === 200) {
            $this->openSymbols -= 1;
        }

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
                } while ($this->checkStatusResponse((int) $orderProfitCancel['status']) && $try <= $this->maxTry);

                if (!$this->checkStatusResponse((int) $orderProfitCancel['status'])) {
                    $this->openSymbols -= 1;
                }
            }
        }

        return [
            'order_cancel' => $orderCancel,
            'order_profit_cancel' => $orderProfitCancel
        ];
    }

    private function calcPercentage(float $value1, float $percentage): float
    {
        $value = bcmul((string) $value1, (string) (100 - $percentage), 8);
        $value = bcdiv($value, '100', 8);

        return (float) bcsub((string) $value1, $value, 8);
    }

    private function getPnlPercentGain(int $leverage): int
    {
        $percent = $this->pnlPositionPercentGain;

        if ($leverage >= 50) {
            $percent += $this->pnlAdditionalPercentGain;
        }

        if ($leverage >= 75) {
            $percent += $this->pnlAdditionalPercentGain;
        }

        if ($leverage >= 100) {
            $percent += $this->pnlAdditionalPercentGain;
        }

        if ($leverage >= 125) {
            $percent += $this->pnlAdditionalPercentGain;
        }

        return $percent;
    }

    private function getPnlPercentLoss(int $leverage): int
    {
        $percent = $this->pnlPositionPercentLoss;

        if ($leverage >= 50) {
            $percent += $this->pnlAdditionalPercentLoss;
        }

        if ($leverage >= 75) {
            $percent += $this->pnlAdditionalPercentLoss;
        }

        if ($leverage >= 100) {
            $percent += $this->pnlAdditionalPercentLoss;
        }

        if ($leverage >= 125) {
            $percent += $this->pnlAdditionalPercentLoss;
        }

        return $percent;
    }

    private function getLastCandleData(): array
    {
        $candles = $this->getCandles(false, 2);

        if ($candles['status'] !== 200) {
            return [];
        }

        return $this->evaluationOfScene($candles['response']);
    }

    private function availableOrders(array $position, array $operation, array $prices): bool
    {
        if (empty($position)) {
            return false;
        }

        $side = ucfirst(Position::typeOrder($position['positionAmt']));
        $entryPrice = (float) $position['entryPrice'];
        $markPrice = (float) $position['markPrice'];
        $unRealizedProfit = (float) $position['unRealizedProfit'];
        $notional = abs((float) $position['notional']);
        $leverage = (int) $position['leverage'];
        $symbol = $position['symbol'];
        $positionSide = $position['positionSide'];
        $marginType = $position['marginType'];
        $margin = $notional / $leverage;
        $profit = $this->getPnlPercentGain($leverage) / $leverage;
        $diffPriceGain = $this->calcPercentage($entryPrice, $profit);
        $diffPriceLoss = $this->calcPercentage($entryPrice, ($this->getPnlPercentLoss($leverage) / $leverage));
        $pnlPositionPercentGain = $this->calcPercentage($margin, $this->getPnlPercentGain($leverage));
        $pnlPositionPercentLoss = $this->calcPercentage($margin, $this->getPnlPercentLoss($leverage));
        $percentStopHedge = $this->getPnlPercentGain($leverage) * 2;
        $pnlPositionPercentLossHedge = $this->calcPercentage($margin, ($percentStopHedge + $this->getPnlPercentLoss($leverage)));
        $pnlPositionPercentGainProtect = $this->calcPercentage($margin, ($this->getPnlPercentGain($leverage) / $this->coveragePointDividerGain));
        $marginIndividual = $this->marginIndividualMin;
        $position['positionAmt'] = abs($position['positionAmt']);
        $positionAmtOrigin = $position['positionAmt'];
        $priceBook = $this->getPriceBook();
        $operationSide = '';
        $closed = true;
        $stop = false;

        if ($operation['type']) {
            $operationSide = $operation['type'] == 'buy' ? 'LONG' : 'SHORT';
        }

        if (!$priceBook) {
            return false;
        }

        if ($this->hedgeMode && $positionSide == 'BOTH') {
            return false;
        }

        if (strtoupper($marginType) !== 'CROSSED') {
            $this->marginType('CROSSED');
        }

        if ($this->walletBalance) {
            $calcMarginIndividual = $this->calcPercentage($this->walletBalance, $this->marginSymbol);

            if ($calcMarginIndividual > $this->marginIndividualMin) {
                $marginIndividual = $calcMarginIndividual < $this->marginIndividualMax
                    ? $calcMarginIndividual
                    : $this->marginIndividualMax;
            }
        }

        if ($margin >= $marginIndividual) {
            $this->setOperations(false);

            if ($operation['enable']) {
                $output = $this->textColor('red', "Maximum margin used {$positionSide} [{$symbol}]\n");

                if ($this->debug) {
                    echo $output;
                }
            }
        } else {
            if ($operationSide == $positionSide) {
                $this->setOperations(
                    $margin < $marginIndividual
                );
            }
        }

        $lastCandleData = $this->getLastCandleData();
        $checkMarginSafe = $this->checkMarginSafe && ($margin >= $this->marginIndividualMin || abs($unRealizedProfit) >= $this->marginIndividualMin);
        $bookPriceBuy = $priceBook['buy'];
        $bookPriceSell = $priceBook['sell'];
        $force = false;

        if ($side == 'Sell') {
            $priceLoss = (float) bcadd((string) $entryPrice, (string) $diffPriceLoss, 8);
            $priceGain = (float) bcsub((string) $entryPrice, (string) $diffPriceGain, 8);
            $priceProtect = 0;
            $msg = '';
            $priceClose = 0;
            $result = '';
            $stopPreventive = false;
            $stopProtectGain = false;

            if ($this->stopPreventive && $lastCandleData['enable']
                && $lastCandleData['type'] === 'buy' && $checkMarginSafe
            ) {
                $stopPreventive = true;
            }

            if ($markPrice > $priceGain && $unRealizedProfit >= $pnlPositionPercentGainProtect && !$stopPreventive) {
                $avgStopGain = bcadd((string) $entryPrice, (string) $markPrice, 8);
                $avgStopGain = bcdiv((string) $avgStopGain, '2', 8);
                $priceProtect = $this->formatDecimal((float) $bookPriceBuy, (float) $avgStopGain);
                $stopProtectGain = true;
                $stop = true;
            }

            if (($markPrice < $priceGain || $stopPreventive || $stopProtectGain) && $unRealizedProfit > $this->amountGainMin) {
                $msg = "Maximum %s [%.4f] - (%.4f < %.4f) | %.4f USDT - %s [%s]\n";
                $result = $this->textColor('green', 'gain');
                $priceClose = $stopProtectGain ? $priceProtect : $priceGain;
                // $force = true;

                if (!$this->softHedge) {
                    $positionHedge = $this->getPostionBySide('LONG');
                    $unRealizedProfitHedge = abs($positionHedge['unRealizedProfit']);

                    if ($unRealizedProfit < $unRealizedProfitHedge) {
                        $priceClose = 0;
                    }
                }

                if ($priceClose && !$this->hasPriceOperation('sell', $priceClose)) {
                    $output = $this->textColor('green', "Surfing the trend {$positionSide} [{$symbol}]\n");
                    $avgStopGain = bcadd((string) $bookPriceBuy, (string) $priceClose, 8);
                    $avgStopGain = bcdiv((string) $avgStopGain, '2', 8);
                    $avgStopGain = bcadd((string) $avgStopGain, (string) $markPrice, 8);
                    $avgStopGain = bcdiv((string) $avgStopGain, '2', 8);
                    $priceClose = $this->formatDecimal((float) $bookPriceBuy, (float) $avgStopGain);
                    $stop = true;

                    if ($this->debug) {
                        echo $output;
                    }
                }
            }

            if (($markPrice > $priceLoss || $stopPreventive) && $unRealizedProfit < 0) {
                $msg = "Maximum %s [%.4f] - (%.4f > %.4f) | %.4f USDT - %s [%s]\n";
                $result = $this->textColor('red', 'loss');
                $priceClose = $priceLoss;

                if ($this->closeLossPositionSoft && $this->hedgePositionShort && abs($unRealizedProfit) >= $pnlPositionPercentLossHedge) {
                    $stopPreventive = true;
                }

                if ($this->closeLossPosition || $stopPreventive) {
                    $force = true;
                } else {
                    if ($this->hedgePositionShort) {
                        $positionHedge = $this->getPostionBySide('LONG');
                        $positionAmtHedge = abs($positionHedge['positionAmt']);
                        $diffPriceClose = $this->calcPercentage($markPrice, $profit);
                        $priceCloseOrder = (float) bcadd((string) $markPrice, (string) $diffPriceClose, 8);
                        $hasPriceOperation = $this->hasPriceOperation('buy', $priceCloseOrder);
                        $diffPriceOrder = abs(Position::percentage($bookPriceBuy, $prices['price_sell']));
                        $closed = false;

                        if ($this->softHedge && !$checkMarginSafe) {
                            if ($operation['enable']) {
                                if ($operation['type'] != 'buy') {
                                    $hasPriceOperation = false;
                                }
                            } else {
                                $hasPriceOperation = false;
                            }

                            if ($hasPriceOperation && $diffPriceOrder <= $profit) {
                                $hasPriceOperation = false;
                            }

                            if (!$hasPriceOperation) {
                                $priceClose = 0;
                            }
                        } else {
                            $force = true;
                        }

                        if ($positionAmtHedge && $priceClose) {
                            if ($positionAmtHedge >= $position['positionAmt']) {
                                $entryPriceHedge = (float) $positionHedge['entryPrice'];
                                $averagePrice = bcadd((string) $entryPrice, (string) $entryPriceHedge, 8);
                                $averagePrice = (float) bcdiv($averagePrice, '2', 8);
                                $diffPriceLossHedge = $this->calcPercentage($averagePrice, ($this->getPnlPercentLoss($leverage) / $leverage));
                                $averagePrice = (float) bcadd((string) $averagePrice, (string) $diffPriceLossHedge, 8);

                                if (
                                    !$this->softHedge && $this->closeLossPositionHedge
                                    && $markPrice > $averagePrice
                                    && $checkMarginSafe
                                ) {
                                    $closed = true;
                                } else {
                                    $priceClose = 0;
                                }
                            } else {
                                $position['positionAmt'] -= $positionAmtHedge;
                            }
                        }

                        if ($position['positionAmt'] > $positionAmtOrigin) {
                            $priceClose = 0;
                        }
                    } else {
                        $priceClose = 0;
                    }
                }
            }

            if ($msg && $priceClose) {
                if (!$closed && ($positionHedge ?? false)) {
                    $positionHedge = $this->getPostionBySide('LONG');
                    $notionalHedge = abs((float) $positionHedge['notional']);
                    $leverageHedge = (int) $positionHedge['leverage'];
                    $marginHedge = $notionalHedge / $leverageHedge;

                    if ($marginHedge >= $marginIndividual) {
                        return false;
                    }
                }

                $output = sprintf(
                    $msg,
                    $result,
                    $entryPrice,
                    $markPrice,
                    $priceClose,
                    $unRealizedProfit,
                    $positionSide,
                    $symbol
                );

                if ($this->debug) {
                    echo $output;
                }

                $paramsClose = [
                    'type' => 'buy',
                    'quantity' => $position['positionAmt'],
                    'price' => $stop ? $priceClose : $bookPriceBuy,
                    'force' => $force,
                    'closed' => $closed,
                    'stop' => $stop
                ];
                $this->closePosition($paramsClose);

                return false;
            }
        }

        if ($side == 'Buy') {
            $priceLoss = (float) bcsub((string) $entryPrice, (string) $diffPriceLoss, 8);
            $priceGain = (float) bcadd((string) $entryPrice, (string) $diffPriceGain, 8);
            $priceProtect = 0;
            $msg = '';
            $result = '';
            $priceClose = 0;
            $stopPreventive = false;
            $stopProtectGain = false;

            if ($this->stopPreventive && $lastCandleData['enable']
                && $lastCandleData['type'] === 'sell' && $checkMarginSafe
            ) {
                $stopPreventive = true;
            }

            if ($markPrice < $priceGain && $unRealizedProfit >= $pnlPositionPercentGainProtect && !$stopPreventive) {
                $avgStopGain = bcadd((string) $entryPrice, (string) $markPrice, 8);
                $avgStopGain = bcdiv((string) $avgStopGain, '2', 8);
                $priceProtect = $this->formatDecimal((float) $bookPriceSell, (float) $avgStopGain);
                $stopProtectGain = true;
                $stop = true;
            }

            if (($markPrice > $priceGain || $stopPreventive || $stopProtectGain) && $unRealizedProfit > $this->amountGainMin) {
                $msg = "Maximum %s [%.4f] - (%.4f > %.4f) | %.4f USDT - %s [%s]\n";
                $result = $this->textColor('green', 'gain');
                $priceClose = $stopProtectGain ? $priceProtect : $priceGain;
                // $force = true;

                if (!$this->softHedge) {
                    $positionHedge = $this->getPostionBySide('SHORT');
                    $unRealizedProfitHedge = abs($positionHedge['unRealizedProfit']);

                    if ($unRealizedProfit < $unRealizedProfitHedge) {
                        $priceClose = 0;
                    }
                }

                if ($priceClose && !$this->hasPriceOperation('buy', $priceClose)) {
                    $output = $this->textColor('green', "Surfing the trend {$positionSide} [{$symbol}]\n");
                    $avgStopGain = bcadd((string) $bookPriceSell, (string) $priceClose, 8);
                    $avgStopGain = bcdiv((string) $avgStopGain, '2', 8);
                    $avgStopGain = bcadd((string) $avgStopGain, (string) $markPrice, 8);
                    $avgStopGain = bcdiv((string) $avgStopGain, '2', 8);
                    $priceClose = $this->formatDecimal((float) $bookPriceSell, (float) $avgStopGain);
                    $stop = true;

                    if ($this->debug) {
                        echo $output;
                    }
                }
            }

            if (($markPrice < $priceLoss || $stopPreventive) && $unRealizedProfit < 0) {
                $msg = "Maximum %s [%.4f] - (%.4f < %.4f) | %.4f USDT - %s [%s]\n";
                $result = $this->textColor('red', 'loss');
                $priceClose = $priceLoss;

                if ($this->closeLossPositionSoft && $this->hedgePositionLong && abs($unRealizedProfit) >= $pnlPositionPercentLossHedge) {
                    $stopPreventive = true;
                }

                if ($this->closeLossPosition || $stopPreventive) {
                    $force = true;
                } else {
                    if ($this->hedgePositionLong) {
                        $positionHedge = $this->getPostionBySide('SHORT');
                        $positionAmtHedge = abs($positionHedge['positionAmt']);
                        $diffPriceClose = $this->calcPercentage($markPrice, $profit);
                        $priceCloseOrder = (float) bcsub((string) $markPrice, (string) $diffPriceClose, 8);
                        $hasPriceOperation = $this->hasPriceOperation('sell', $priceCloseOrder);
                        $diffPriceOrder = abs(Position::percentage($bookPriceBuy, $prices['price_buy']));
                        $closed = false;

                        if ($this->softHedge && !$checkMarginSafe) {
                            if ($operation['enable']) {
                                if ($operation['type'] != 'sell') {
                                    $hasPriceOperation = false;
                                }
                            } else {
                                $hasPriceOperation = false;
                            }

                            if ($hasPriceOperation && $diffPriceOrder <= $profit) {
                                $hasPriceOperation = false;
                            }

                            if (!$hasPriceOperation) {
                                $priceClose = 0;
                            }
                        } else {
                            $force = true;
                        }

                        if ($positionAmtHedge && $priceClose) {
                            if ($positionAmtHedge >= $position['positionAmt']) {
                                $entryPriceHedge = (float) $positionHedge['entryPrice'];
                                $averagePrice = bcadd((string) $entryPrice, (string) $entryPriceHedge, 8);
                                $averagePrice = (float) bcdiv($averagePrice, '2', 8);
                                $diffPriceLossHedge = $this->calcPercentage($averagePrice, ($this->getPnlPercentLoss($leverage) / $leverage));
                                $averagePrice = (float) bcsub((string) $averagePrice, (string) $diffPriceLossHedge, 8);

                                if (
                                    !$this->softHedge && $this->closeLossPositionHedge
                                    && $markPrice < $averagePrice
                                    && $checkMarginSafe
                                ) {
                                    $closed = true;
                                } else {
                                    $priceClose = 0;
                                }
                            } else {
                                $position['positionAmt'] -= $positionAmtHedge;
                            }
                        }

                        if ($position['positionAmt'] > $positionAmtOrigin) {
                            $priceClose = 0;
                        }
                    } else {
                        $priceClose = 0;
                    }
                }
            }

            if ($msg && $priceClose) {
                if (!$closed && ($positionHedge ?? false)) {
                    $positionHedge = $this->getPostionBySide('SHORT');
                    $notionalHedge = abs((float) $positionHedge['notional']);
                    $leverageHedge = (int) $positionHedge['leverage'];
                    $marginHedge = $notionalHedge / $leverageHedge;

                    if ($marginHedge >= $marginIndividual) {
                        return false;
                    }
                }

                $output = sprintf(
                    $msg,
                    $result,
                    $entryPrice,
                    $markPrice,
                    $priceClose,
                    $unRealizedProfit,
                    $positionSide,
                    $symbol
                );

                if ($this->debug) {
                    echo $output;
                }

                $paramsClose = [
                    'type' => 'sell',
                    'quantity' => $position['positionAmt'],
                    'price' => $stop ? $priceClose : $bookPriceSell,
                    'force' => $force,
                    'closed' => $closed,
                    'stop' => $stop
                ];
                $this->closePosition($paramsClose);

                return false;
            }
        }

        if (!$this->hedgeMode && !$this->closeLossPosition && $side && $this->operations && (
            ($unRealizedProfit <= ($pnlPositionPercentLoss * -1) && $unRealizedProfit >= ($pnlPositionPercentLoss * -1) * 3)
             || $unRealizedProfit <= ($pnlPositionPercentLoss * -1) * 6
            )
        ) {
            if ($this->isPrintMessage()) {
                $output = $this->textColor('red', "Loss limit on the position {$positionSide} [{$symbol}]\n");

                if ($this->debug) {
                    echo $output;
                }
            }

            return false;
        }

        if (!$this->checkMaxOrders($side, $symbol)) {
            return false;
        }

        return $this->operations;
    }

    private function checkMaxOrders(string $side, string $symbol): bool
    {
        $responseOrders = $this->getOrders();

        if (($responseOrders['status'] ?? 0) !== 200) {
            return false;
        }

        $orders = $responseOrders['response'];
        $contracts = 0;
        $ordersTotal = 0;

        if (!empty($orders)) {
            foreach ($orders as $order) {
                if (strnatcasecmp($order['side'], $side) === 0) {
                    $contracts += $order['origQty'];
                }

                if (!$order['reduceOnly']) {
                    $ordersTotal++;
                }
            }

            $this->openSymbols = $ordersTotal;

            if ($ordersTotal >= $this->maxOrders) {
                $output = $this->textColor('blue', "Maximum open orders [{$symbol}]\n");

                if ($this->debug) {
                    echo $output;
                }

                return false;
            }
        }

        return true;
    }

    private function closePosition(array $params): void
    {
        if ($this->configs->getClosePosition()) {
            $orders = $this->getOrders()['response'];

            if ($position = $this->position()) {
                $type = Position::typeOrder($position['positionAmt'] ?? 0);
                $breakOrder = false;

                foreach ($orders as $order) {
                    if (!$this->hedgeMode && $type != strtolower($order['side'])/* && $order['status'] != 'NEW'*/) {
                        $breakOrder = true;
                        break;
                    }

                    if ($order['reduceOnly'] && !$this->isTimeBoxOrder((int) $order['time'], true)) {
                        return;
                    }

                    $result = $this->cancelOrder($order['orderId'] ?? '');

                    if (($result['order_cancel']['status'] ?? 0) !== 200) {
                        $breakOrder = true;
                    }
                }

                if (!$breakOrder) {
                    if ($this->hedgeMode || $type) {
                        $this->order(
                            $params,
                            $params['closed'] ?? true,
                            $params['stop'] ?? true
                        );
                    }

                    $output = $this->textColor('red', "Closed position\n");

                    if ($this->debug) {
                        echo $output;
                    }
                } else {
                    $output = $this->textColor('red', "Failed to create position close order\n");

                    if ($this->debug) {
                        echo $output;
                    }
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
        return $this->getOpenOrders(true);
    }

    private function getOpenOrders(bool $symbol = false): array
    {
        $request = [];

        if ($symbol) {
            $request['symbol'] = $this->configs->getSymbol();
        }

        return $this->waitRateLimit($this->request->get('/openOrders', $request));
    }

    public function getTotalOpenOrders(): int
    {
        $responseOrders = $this->getOpenOrders();
        $ordersTotal = 0;

        if (($responseOrders['status'] ?? 0) !== 200) {
            return $ordersTotal;
        }

        $orders = $responseOrders['response'];

        if (!empty($orders)) {
            foreach ($orders as $order) {
                if (!$order['reduceOnly']) {
                    $ordersTotal++;
                }
            }
        }

        return $ordersTotal;
    }

    private function getCandles(bool $btc = false, int $limit = 3, bool $closed = false): array
    {
        if ($this->candleClosed || $closed) {
            $limit += 1;
        }

        $return = $this->waitRateLimit(
            $this->request->get('/continuousKlines', [
                'pair' => !$btc ? $this->configs->getSymbol() : 'BTCUSDT',
                'contractType' => 'PERPETUAL',
                'interval' => $this->candleTime,
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

        if ($this->candleClosed || $closed) {
            array_pop($return['response']);
        }

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
            'price_buy' => min($prices_avaliable['low']),
            'price_sell' => max($prices_avaliable['high'])
        ];
    }

    private function evaluationOfScene(array $candles, bool $btc = false): array
    {
        $high = 0;
        $low = 0;
        $now = 0;
        $analyser_last = '';

        foreach($candles as $candle) {
            // High
            if ($candle['close'] > $candle['open']) {
                $high++;
                $now++;
            }

            // Low
            if ($candle['close'] < $candle['open']) {
                $low++;
                $now--;
            }
        }

        $key = Position::arrayKeyLast($candles);

        if ($candles[$key]['close'] > $candles[$key]['open']) {
            $analyser_last = 'buy';
        }

        if ($candles[$key]['close'] < $candles[$key]['open']) {
            $analyser_last = 'sell';
        }

        return $this->sceneToOperation([
            'high' => $high,
            'low' => $low,
            'now' => $now,
            'close' => $candles[$key]['close'],
            'last' => $analyser_last
        ], $btc);
    }

    private function sceneToOperation(array $params, bool $btc = false): array
    {
        $type_order = '';
        $open_order = false;
        $candleConsecutive = $this->stopPreventive ? ($this->candleConsecutive * 2) : $this->candleConsecutive;

        // buy
        if ($params['now'] >= $candleConsecutive && $params['last'] == 'buy') {
            $open_order = true;
            $type_order = 'buy';
        }

        // sell
        if ($params['now'] <= ($candleConsecutive * -1) && $params['last'] == 'sell') {
            $open_order = true;
            $type_order = 'sell';
        }

        if (!$this->hedgeMode) {
            $statics = $this->getStaticsTicker();

            if (($statics['status'] ?? 0) !== 200) {
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

    private function formatDecimal(float $base, float $value): float
    {
        preg_match('/\.([0-9]+)/', $base, $temp);
        $decimal = strlen($temp[1] ?? '');
        $value = $decimal ? round($value, $decimal) : ceil($value);

        return (float) $value;
    }

    private function getOrderContracts(float $price): float
    {
        $default = $this->configs->getOrderContracts();
        $contracts = bcdiv("{$this->baseOrderAmount}", (string) $price, 4);
        $contracts = $this->formatDecimal((float) $default, (float) $contracts);

        if ($contracts < $default) {
            $contracts = $default;
        }

        if ($this->amountPerOrder > 0) {
            $checkValue = bcmul((string) $contracts, (string) $price, 8);
            $checkValue = bcdiv((string) $checkValue, (string) $this->configs->getLeverage(), 8);

            if ($this->amountPerOrder > $checkValue) {
                $checkValue = (int) bcdiv((string) $this->amountPerOrder, (string) $checkValue);

                return $contracts * $checkValue;
            }
        }

        return $contracts * $this->multipleOrder;
    }

    private function getPriceBook(): ?array
    {
        $responseBook = $this->getBook();

        if (($responseBook['status'] ?? 0) !== 200) {
            return null;
        }

        $book = $responseBook['response'];

        return [
            'sell' => $book['asks'][4][0],
            'buy' => $book['bids'][4][0]
        ];
    }

    private function operation(array $operation, array $prices): void
    {
        if ($this->operationDisable) {
            return;
        }

        if (!$this->hedgeMode && $this->configs->getSide() !== '.') {
            if (strnatcasecmp($this->configs->getSide(), $operation['type']) !== 0) {
                return;
            }
        }

        if (empty($operation['type'])) {
            return;
        }

        if (!$this->hedgeMode && !empty($this->position)) {
            $type = Position::typeOrder($this->position['positionAmt'] ?? 0);

            if ($type && $operation['type'] != $type) {
                $operation['enable'] = false;
            }
        }

        if ($operation['enable']) {
            $priceBook = $this->getPriceBook();

            if (!$priceBook) {
                return;
            }

            $pricesOperation = $this->getPricesForOperation($operation['type'], $priceBook, $prices);

            if (!$this->hasPriceOperation($pricesOperation['origin']['param']['type'], $pricesOperation['origin']['price'])) {
                return;
            }

            if ($this->safePosition) {
                $infoPrices = $this->infoPrice(false, $this->candleLimit);

                if (!$infoPrices) {
                    return;
                }

                if ($pricesOperation['origin']['param']['type'] === 'buy') {
                    $priceCheckCandle = $infoPrices['prices']['price_sell'];
                } else {
                    $priceCheckCandle = $infoPrices['prices']['price_buy'];
                }

                $profit = $this->getPnlPercentGain($this->configs->getLeverage()) / $this->configs->getLeverage();
                $diffPriceOrder = abs(Position::percentage($pricesOperation['origin']['price'], $priceCheckCandle));

                if ($diffPriceOrder <= $profit * $this->multiplePercentGain) {
                    return;
                }
            }

            if (!$this->configs->getScalper()) {
                if ($pricesOperation['origin']['param']['type'] === 'buy') {
                    if ($pricesOperation['origin']['price'] <= $pricesOperation['origin']['param']['price']) {
                        return;
                    }
                } else {
                    if ($pricesOperation['origin']['price'] >= $pricesOperation['origin']['param']['price']) {
                        return;
                    }
                }
            }

            if ($this->operations && $operation['enable']) {
                $colorSide = $pricesOperation['origin']['param']['type'] == 'buy' ? 'green' : 'red';
                $output = sprintf(
                    "Symbol: %s - Side: %s - Open: %s - Quantity: %s\n",
                    $this->configs->getSymbol(),
                    $this->textColor($colorSide, $pricesOperation['origin']['param']['type']),
                    $pricesOperation['origin']['param']['price'],
                    $pricesOperation['origin']['param']['quantity']
                );

                if ($this->debug) {
                    echo $output;
                }

                if ($this->getTotalOpenOrders() >= $this->maxOrdersGeneral) {
                    $output = $this->textColor('cyan', "Maximum open orders [ALL]\n");

                    if ($this->debug) {
                        echo $output;
                    }

                    return;
                }

                $this->orderProfit($pricesOperation['origin']['param'], $pricesOperation['origin']['price']);

                if ($this->orderReverse) {
                    $this->orderProfit($pricesOperation['reverse']['param'], $pricesOperation['reverse']['price']);
                }
            }
        }
    }

    private function getPricesForOperation(string $type, array $priceBook, array $prices): array
    {
        $typeReverse = $type == 'buy' ? 'sell' : 'buy';
        $paramOrderOrigin = $this->paramsOfOrder([
            'type' => $type,
            'sell' => [
                'book' => $priceBook['sell'],
                'candle' => $prices['price_sell']
            ],
            'buy' => [
                'book' => $priceBook['buy'],
                'candle' => $prices['price_buy']
            ],
        ]);
        $priceOrderOrigin = Position::calculePriceOrder(
            $paramOrderOrigin['type'],
            $paramOrderOrigin['price'],
            $this->getPnlPercentGain($this->configs->getLeverage()),
            $this->configs->getLeverage()
        );

        $paramOrderReverse = $this->paramsOfOrder([
            'type' => $typeReverse,
            'sell' => [
                'book' => $priceBook['sell'],
                'candle' => $prices['price_sell']
            ],
            'buy' => [
                'book' => $priceBook['buy'],
                'candle' => $prices['price_buy']
            ],
        ]);
        $priceOrderReverse = Position::calculePriceOrder(
            $paramOrderReverse['type'],
            $paramOrderReverse['price'],
            $this->getPnlPercentGain($this->configs->getLeverage()),
            $this->configs->getLeverage()
        );

        return [
            'origin' => [
                'param' => $paramOrderOrigin,
                'price' => $priceOrderOrigin
            ],
            'reverse' => [
                'param' => $paramOrderReverse,
                'price' => $priceOrderReverse
            ]
        ];
    }

    private function hasPriceOperation(string $type, float $priceClose): bool
    {
        $profit = $this->getPnlPercentGain($this->configs->getLeverage()) / $this->configs->getLeverage();
        $statics = $this->getStaticsTicker();

        if ($statics['status'] != 200) {
            return false;
        }

        // Gain greater than max or close to margin
        $diff_price = Position::percentage($priceClose, $statics['response']['highPrice']);

        if (abs($diff_price) <= ($profit * $this->multiplePercentGain)) {
            return false;
        }

        // Gain greater than min or close to margin
        $diff_price = Position::percentage($priceClose, $statics['response']['lowPrice']);

        if (abs($diff_price) <= ($profit * $this->multiplePercentGain)) {
            return false;
        }

        $priceOpen = (float) $statics['response']['openPrice'];
        $lastPrice = (float) $statics['response']['lastPrice'];
        $priceExtreme = $type == 'buy'
            ? $statics['response']['highPrice']
            : $statics['response']['lowPrice'];
        $changePercentDay = abs(Position::percentage($lastPrice, $priceOpen));
        $changePercentExtreme = abs(Position::percentage($lastPrice, $priceExtreme));

        if ($changePercentDay >= $this->priceChangePercent
            || $changePercentExtreme >= ($this->priceChangePercent * $this->multiplePercentGain)
        ) {
            $output = $this->textColor('cyan', "Maximum daily variation [{$this->configs->getSymbol()}]\n");

            if ($this->debug) {
                echo $output;
            }

            return false;
        }

        return true;
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
                    $orderProfit = $this->order($params, true);
                    $try++;
                    sleep(1);
                } while ($this->checkStatusResponse((int) $orderProfit['status']) && $try <= $this->maxTry);
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

        $price = (float) $price;

        return [
            'type' => $param['type'],
            'quantity' => $this->getOrderContracts($price),
            'price' => $price
        ];
    }

    private function order(array $params, bool $closed = false, bool $stop = false): array
    {
        $params_request = [
            'symbol' => $this->configs->getSymbol(),
            'side' => strtoupper($params['type']),
            'quantity' => $params['quantity'],
            'positionSide' => 'BOTH',
            'type' => 'LIMIT',
        ];

        if ($params['force'] ?? false) {
            $params_request['type'] = 'MARKET';
        } else {
            if (!$stop) {
                $params_request['price'] = $params['price'];
                $params_request['timeInForce'] = 'GTC';
            } else {
                $params_request['type'] = 'STOP_MARKET';
                $params_request['stopPrice'] = $params['price'];
            }
        }

        if ($this->hedgeMode) {
            $checkSide = $params_request['side'] == 'SELL';

            if ($closed) {
                $checkSide = $params_request['side'] == 'BUY';
            }

            $params_request['positionSide'] = $checkSide ? 'SHORT' : 'LONG';
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
        } while ($this->checkStatusResponse((int) $order['status']) && $try <= $this->maxTry);

        if (!$this->checkStatusResponse((int) $order['status'])) {
            $this->openSymbols += 1;
        }

        return $order;
    }

    private function transferBalance(float $amount, bool $input = false): bool
    {
        $params_request = [
            'asset' => 'USDT',
            'amount' => $amount,
            'type' => $input ? 1 : 2
        ];

        $this->request->setEnableSaving(true);
        $response = $this->request->post('/futures/transfer', $params_request);
        $this->request->setEnableSaving(false);

        return $response['status'] === 200;
    }

    public function marginType(string $type = 'ISOLATED'): bool
    {
        $params_request = [
            'symbol' => $this->configs->getSymbol(),
            'marginType' => $type
        ];

        $response = $this->request->post('/marginType', $params_request);

        return $response['status'] === 200;
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
        $seconds = (int) date('s');
        $delay = 60 - $seconds;

        // Limit orders
        $orders = $request['orders']['1m'] ?? 0;
        $rateOrder = $orders / 60;

        if ($rateOrder >= 16) {
            sleep($delay);

            throw new InvalidArgumentException();
        } else {
            $rateOrder = (int) ($orders / ($seconds ?: 60));

            if ($rateOrder >= 7) {
                usleep(0.9 * 1e6);
            }
        }

        // Limit requests
        $limit = $request['rate_limit']['1m'] ?? 2300;
        $rate = $limit / 60;

        if ($rate >= 35) {
            sleep($delay);

            throw new InvalidArgumentException();
        } else {
            $rate = (int) ($limit / ($seconds ?: 60));

            if ($rate >= 15) {
                usleep(0.9 * 1e6);
            }
        }

        if (($request['status'] ?? 0) !== 200) {
            $output = sprintf(
                "%s - %s \n",
                $this->configs->getSymbol(),
                $request['response']['msg']
            );

            if ($this->debug) {
                echo $output;
            }
        }

        return $request;
    }

    public function getOpenSymbols(): int
    {
        return $this->openSymbols < 0 ? 0 : $this->openSymbols;
    }

    public function setOperations(bool $operations): void
    {
        $this->operations = $operations;
    }

    public function setOperationDisable(bool $operationDisable): void
    {
        $this->operationDisable = $operationDisable;
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }
}
