<?php
declare(strict_types=1);

/**
 * The stock market. Eight made-up companies whose prices move every quarter of an hour. Buy low, sell high.
 *
 * Prices move by themselves: a random step each tick, with a gentle pull back towards each company's long-run
 * price, so a share that has crashed tends to recover and one that has soared tends to cool. Players' trades do not
 * move prices, so nobody can push a price around with a second account.
 *
 * Two things keep it from printing unlimited money: a fee on every trade, and a cap on how many shares of one
 * company a player can hold, which rises with their level.
 */
const MCC_STOCK_TICK = 900;                 // seconds between price moves
const MCC_STOCK_FEE_PERMILLE = 15;          // 1.5% of every trade, each way; it leaves the game
const MCC_STOCK_SHARES_PER_LEVEL = 50;      // most shares of one company a player may hold, per level
const MCC_STOCK_PULL_PERMILLE = 20;         // each tick closes 2% of the gap to the long-run price
const MCC_STOCK_CATCHUP = 96;               // if nobody has been on for a while, replay at most this many ticks (a day)
const MCC_STOCK_KEEP = 7 * 86400;           // price history kept
/** Seeded once. symbol => [name, long-run price, how far it swings each tick in thousandths]. */
const MCC_STOCK_SEED = [
    'BRIX' => ['Brixton Scrap & Salvage', 120, 25],
    'NEON' => ['Neon Row Casinos', 850, 45],
    'DOCK' => ['Eastdock Freight', 310, 20],
    'PAWN' => ['Golden Pawn Holdings', 65, 35],
    'VLT' => ['Vault Street Security', 1400, 15],
    'RUSH' => ['Rush Courier Co', 40, 60],
    'IRON' => ['Ironworks Gym Group', 520, 30],
    'SMOK' => ['Smokestack Brewing', 215, 40],
];

function stock_cap(int $level): int
{
    return MCC_STOCK_SHARES_PER_LEVEL * max(1, $level);
}

function stock_fee(int $amount): int
{
    return (int)ceil($amount * MCC_STOCK_FEE_PERMILLE / 1000);
}

/** One step of one price. */
function stock_step(int $price, int $base, int $swing): int
{
    $move = mt_rand(-$swing * 10, $swing * 10) / 10000;                         // the random part
    $pull = MCC_STOCK_PULL_PERMILLE / 1000 * ($base - $price) / max(1, $base);  // the pull towards the long-run price
    return max(1, (int)round($price * (1 + $move + $pull)));
}

/**
 * Move prices if a tick is due. One request claims the tick; others carry on. Returns how many ticks were played.
 */
function stocks_tick(?int $now = null): int
{
    global $db;
    $now ??= time();
    $slot = intdiv($now, MCC_STOCK_TICK);
    $last = (int)$db->value("SELECT `conf_value` FROM `settings` WHERE `conf_name` = 'engine_stock_tick'", [], 0);
    if ($last >= $slot) {
        return 0;
    }
    if ($db->exec("UPDATE `settings` SET `conf_value` = ? WHERE `conf_name` = 'engine_stock_tick' AND `conf_value` = ?", [(string)$slot, (string)$last]) !== 1) {
        return 0;                                // another request took this tick
    }
    $ticks = $last === 0 ? 1 : min($slot - $last, MCC_STOCK_CATCHUP);
    $db->transaction(function () use ($db, $slot, $ticks) {
        foreach ($db->all('SELECT `id`, `price`, `base`, `swing` FROM `v3_stocks` FOR UPDATE') as $s) {
            $price = (int)$s['price'];
            $prev = $price;
            for ($i = $ticks - 1; $i >= 0; $i--) {
                $prev = $price;
                $price = stock_step($price, (int)$s['base'], (int)$s['swing']);
                $db->run('INSERT IGNORE INTO `v3_stock_history` (`stock`, `at`, `price`) VALUES (?, ?, ?)', [(int)$s['id'], ($slot - $i) * MCC_STOCK_TICK, $price]);
            }
            $db->exec('UPDATE `v3_stocks` SET `price` = ?, `prev` = ? WHERE `id` = ?', [$price, $prev, (int)$s['id']]);
        }
        $db->exec('DELETE FROM `v3_stock_history` WHERE `at` < ?', [$slot * MCC_STOCK_TICK - MCC_STOCK_KEEP]);
    });
    return $ticks;
}

/** Every company with the player's holding: [..., 'shares' => , 'cost' => what they paid for those shares]. */
function stocks_list(int $userid): array
{
    global $db;
    return $db->all('SELECT `s`.*, COALESCE(`h`.`shares`, 0) AS `shares`, COALESCE(`h`.`cost`, 0) AS `cost`
                     FROM `v3_stocks` AS `s` LEFT JOIN `v3_stock_holdings` AS `h` ON `h`.`stock` = `s`.`id` AND `h`.`userid` = ? ORDER BY `s`.`symbol`', [$userid]);
}

/** Prices for the last $hours, oldest first: [stock id => [price, ...]]. */
function stocks_history(int $hours = 24): array
{
    global $db;
    $out = [];
    foreach ($db->all('SELECT `stock`, `price` FROM `v3_stock_history` WHERE `at` >= ? ORDER BY `at`', [time() - $hours * 3600]) as $r) {
        $out[(int)$r['stock']][] = (int)$r['price'];
    }
    return $out;
}

/**
 * Buy shares at the current price. Returns ['shares' => , 'price' => , 'paid' => , 'fee' => ] or a string saying
 * why not. The price is read, the money taken and the shares added in one locked step.
 */
function stock_buy(int $userid, int $stock_id, int $shares, int $level): array|string
{
    global $db;
    if ($shares < 1) {
        return 'Say how many shares.';
    }
    $out = 'That could not be bought.';
    $db->attempt(function () use ($db, $userid, $stock_id, $shares, $level, &$out) {
        $s = $db->row('SELECT `price`, `symbol` FROM `v3_stocks` WHERE `id` = ? FOR UPDATE', [$stock_id]);
        if ($s === null) {
            $out = 'There is no such company.';
            return false;
        }
        $cost = (int)$s['price'] * $shares;
        $fee = stock_fee($cost);
        $db->run('INSERT IGNORE INTO `v3_stock_holdings` (`userid`, `stock`, `shares`, `cost`) VALUES (?, ?, 0, 0)', [$userid, $stock_id]);
        // the cap is part of the statement, so buying from several places at once cannot go past it
        if ($db->exec('UPDATE `v3_stock_holdings` SET `shares` = `shares` + ?, `cost` = `cost` + ? WHERE `userid` = ? AND `stock` = ? AND `shares` + ? <= ?',
                [$shares, $cost, $userid, $stock_id, $shares, stock_cap($level)]) !== 1) {
            $out = 'At your level you can hold ' . number_format(stock_cap($level)) . " shares of {$s['symbol']}.";
            return false;
        }
        if (!wallet_take($userid, 'money', $cost + $fee)) {
            $out = 'You need ' . money_formatter($cost + $fee) . ' in your pocket (the shares plus the fee).';
            return false;
        }
        $out = ['shares' => $shares, 'price' => (int)$s['price'], 'paid' => $cost + $fee, 'fee' => $fee];
        return true;
    });
    return $out;
}

/** Sell shares at the current price. Returns ['shares', 'price', 'got', 'fee', 'profit'] or why not. */
function stock_sell(int $userid, int $stock_id, int $shares): array|string
{
    global $db;
    if ($shares < 1) {
        return 'Say how many shares.';
    }
    $out = 'You do not hold that many shares.';
    $db->attempt(function () use ($db, $userid, $stock_id, $shares, &$out) {
        $s = $db->row('SELECT `price` FROM `v3_stocks` WHERE `id` = ? FOR UPDATE', [$stock_id]);
        $h = $db->row('SELECT `shares`, `cost` FROM `v3_stock_holdings` WHERE `userid` = ? AND `stock` = ? FOR UPDATE', [$userid, $stock_id]);
        if ($s === null || $h === null || (int)$h['shares'] < $shares) {
            return false;
        }
        $part = (int)round((int)$h['cost'] * $shares / (int)$h['shares']);      // what those shares cost to buy
        if ($db->exec('UPDATE `v3_stock_holdings` SET `shares` = `shares` - ?, `cost` = `cost` - ? WHERE `userid` = ? AND `stock` = ? AND `shares` >= ?', [$shares, $part, $userid, $stock_id, $shares]) !== 1) {
            return false;
        }
        $value = (int)$s['price'] * $shares;
        $fee = stock_fee($value);
        wallet_give($userid, 'money', $value - $fee);
        $out = ['shares' => $shares, 'price' => (int)$s['price'], 'got' => $value - $fee, 'fee' => $fee, 'profit' => $value - $fee - $part];
        return true;
    });
    return $out;
}

/** A small line for the last day's prices. Direction is also given in words beside it, never by colour alone. */
function stock_spark(array $prices, bool $up): string
{
    $n = count($prices);
    if ($n < 2) {
        return '';
    }
    $min = min($prices);
    $span = max(1, max($prices) - $min);
    $pts = [];
    foreach ($prices as $i => $p) {
        $pts[] = round(2 + 116 * $i / ($n - 1), 1) . ',' . round(26 - 22 * ($p - $min) / $span, 1);
    }
    return "<svg viewBox='0 0 120 30' width='120' height='30' role='img' aria-label='Price over the last day, from " . number_format($prices[0]) . ' to ' . number_format($prices[$n - 1])
        . "'><polyline points='" . implode(' ', $pts) . "' fill='none' stroke='" . ($up ? '#34D399' : '#FB7185') . "' stroke-width='2' stroke-linejoin='round' stroke-linecap='round' /></svg>";
}
