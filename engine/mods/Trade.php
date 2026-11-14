<?php
declare(strict_types=1);

/**
 * Travel trading. Every city sells a few local goods that are worth more anywhere else. Buy where they are made,
 * carry them to another city, sell them there.
 *
 * Three things keep it a game and not a money tap: each city only has so much of each thing and restocks slowly
 * (so traders compete for it), a player can only carry so much, and there is a customs allowance on how much one
 * player can buy in a day.
 */
/** key => [name, price where it is made, how many a city holds when full]. Which city makes which is worked out from the list of cities. */
const MCC_TRADE_GOODS = [
    'cigars' => ['Hand-rolled cigars', 600, 120], 'whisky' => ['Single malt whisky', 1500, 80], 'watches' => ['Imitation watches', 2500, 60], 'silk' => ['Bolts of silk', 900, 100],
    'spice' => ['Saffron', 3500, 40], 'coffee' => ['Coffee beans', 400, 150], 'perfume' => ['Perfume', 2000, 60], 'jade' => ['Carved jade', 5000, 30],
    'leather' => ['Leather jackets', 1200, 90], 'vinyl' => ['Rare records', 800, 100], 'caviar' => ['Caviar', 4200, 30], 'tea' => ['Tea chests', 500, 140],
];
const MCC_TRADE_GOODS_PER_CITY = 3;
const MCC_TRADE_MARKUP_PERCENT = 35;       // what a good fetches away from home, over its price there
const MCC_TRADE_RESTOCK_HOURS = 6;         // an empty shelf is full again after this long
const MCC_TRADE_CARRY_BASE = 10;           // units a player can carry...
const MCC_TRADE_CARRY_PER_LEVEL = 1;       // ...plus this per level...
const MCC_TRADE_CARRY_MAX = 60;            // ...up to this
const MCC_TRADE_LOADS_A_DAY = 3;           // customs: a player may buy this many full loads a day

function trade_capacity(int $level): int
{
    return min(MCC_TRADE_CARRY_MAX, MCC_TRADE_CARRY_BASE + MCC_TRADE_CARRY_PER_LEVEL * max(1, $level));
}

function trade_sell_price(string $good): int
{
    return (int)round(MCC_TRADE_GOODS[$good][1] * (100 + MCC_TRADE_MARKUP_PERCENT) / 100);
}

/** [good key => city id that makes it]. Goods are dealt round the cities in id order, so it never changes unless cities do. */
function trade_origins(): array
{
    global $db;
    static $out = null;
    if ($out === null) {
        $cities = array_map('intval', array_column($db->all('SELECT `cityid` FROM `cities` ORDER BY `cityid`'), 'cityid'));
        $out = [];
        if (count($cities) >= 2) {
            $keys = array_slice(array_keys(MCC_TRADE_GOODS), 0, count($cities) * MCC_TRADE_GOODS_PER_CITY);
            foreach ($keys as $i => $key) {
                $out[$key] = $cities[$i % count($cities)];
            }
        }
    }
    return $out;
}

/** What is on the shelves in a city now: [good => stock]. Shelves refill a little at a time, worked out when looked at. */
function trade_stock(int $city, ?int $now = null): array
{
    global $db;
    $now ??= time();
    $out = [];
    foreach (trade_origins() as $good => $home) {
        if ($home !== $city) {
            continue;
        }
        $full = MCC_TRADE_GOODS[$good][2];
        $db->run('INSERT IGNORE INTO `v3_trade_stock` (`city`, `good`, `stock`, `at`) VALUES (?, ?, ?, ?)', [$city, $good, $full, $now]);
        $r = $db->row('SELECT `stock`, `at` FROM `v3_trade_stock` WHERE `city` = ? AND `good` = ?', [$city, $good]);
        $grown = (int)floor(($now - (int)$r['at']) * $full / (MCC_TRADE_RESTOCK_HOURS * 3600));
        if ($grown > 0 && (int)$r['stock'] < $full) {
            // only if nobody has touched the shelf since we looked
            $db->exec('UPDATE `v3_trade_stock` SET `stock` = LEAST(?, `stock` + ?), `at` = ? WHERE `city` = ? AND `good` = ? AND `at` = ?', [$full, $grown, $now, $city, $good, (int)$r['at']]);
            $r = $db->row('SELECT `stock` FROM `v3_trade_stock` WHERE `city` = ? AND `good` = ?', [$city, $good]);
        } elseif ((int)$r['stock'] >= $full) {
            $db->exec('UPDATE `v3_trade_stock` SET `at` = ? WHERE `city` = ? AND `good` = ? AND `stock` >= ?', [$now, $city, $good, $full]);
        }
        $out[$good] = (int)$r['stock'];
    }
    return $out;
}

/** What a player is carrying: [good => ['qty' => , 'paid' => ]]. */
function trade_cargo(int $userid): array
{
    global $db;
    $out = [];
    foreach ($db->all('SELECT `good`, `qty`, `paid` FROM `v3_trade_cargo` WHERE `userid` = ? AND `qty` > 0 ORDER BY `good`', [$userid]) as $r) {
        $out[$r['good']] = ['qty' => (int)$r['qty'], 'paid' => (int)$r['paid']];
    }
    return $out;
}

/** Buy goods in the city the player is in. Null when bought, or why not. */
function trade_buy(int $userid, string $good, int $qty, ?string $today = null): ?string
{
    global $db;
    if ($qty < 1 || !isset(MCC_TRADE_GOODS[$good])) {
        return 'Say what, and how many.';
    }
    $why = 'That could not be bought.';
    $ok = $db->attempt(function () use ($db, $userid, $good, $qty, $today, &$why) {
        // the player's own row is locked first, so their purchases happen one at a time and the limits below hold
        $u = $db->row('SELECT `location`, `level` FROM `users` WHERE `userid` = ? FOR UPDATE', [$userid]);
        if ($u === null || (trade_origins()[$good] ?? 0) !== (int)$u['location']) {
            $why = 'That is not sold here.';
            return false;
        }
        $cap = trade_capacity((int)$u['level']);
        $carrying = (int)$db->value('SELECT COALESCE(SUM(`qty`), 0) FROM `v3_trade_cargo` WHERE `userid` = ?', [$userid]);
        if ($carrying + $qty > $cap) {
            $why = "You can carry {$cap} and are carrying {$carrying}.";
            return false;
        }
        $today ??= date('Y-m-d');
        $db->run('INSERT IGNORE INTO `v3_perk_uses` (`userid`, `day`, `perk`, `uses`) VALUES (?, ?, ?, 0)', [$userid, $today, 'trade_units']);
        if ($db->exec('UPDATE `v3_perk_uses` SET `uses` = `uses` + ? WHERE `userid` = ? AND `day` = ? AND `perk` = ? AND `uses` + ? <= ?', [$qty, $userid, $today, 'trade_units', $qty, $cap * MCC_TRADE_LOADS_A_DAY]) !== 1) {
            $why = 'Customs allow you ' . ($cap * MCC_TRADE_LOADS_A_DAY) . ' units a day, and that would take you over.';
            return false;
        }
        // the shelf is shared with everyone: one statement takes the stock only if it is there
        if ($db->exec('UPDATE `v3_trade_stock` SET `stock` = `stock` - ? WHERE `city` = ? AND `good` = ? AND `stock` >= ?', [$qty, (int)$u['location'], $good, $qty]) !== 1) {
            $why = 'There are not that many left. More arrive through the day.';
            return false;
        }
        $cost = MCC_TRADE_GOODS[$good][1] * $qty;
        if (!wallet_take($userid, 'money', $cost)) {
            $why = 'That comes to ' . money_formatter($cost) . '.';
            return false;
        }
        $db->run('INSERT INTO `v3_trade_cargo` (`userid`, `good`, `qty`, `paid`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `qty` = `qty` + VALUES(`qty`), `paid` = `paid` + VALUES(`paid`)', [$userid, $good, $qty, $cost]);
        return true;
    });
    return $ok ? null : $why;
}

/** Sell goods in the city the player is in. Returns ['got' => , 'profit' => ] or why not. */
function trade_sell(int $userid, string $good, int $qty): array|string
{
    global $db;
    if ($qty < 1 || !isset(MCC_TRADE_GOODS[$good])) {
        return 'Say what, and how many.';
    }
    $out = 'You are not carrying that many.';
    $db->attempt(function () use ($db, $userid, $good, $qty, &$out) {
        $u = $db->row('SELECT `location` FROM `users` WHERE `userid` = ? FOR UPDATE', [$userid]);
        $c = $db->row('SELECT `qty`, `paid` FROM `v3_trade_cargo` WHERE `userid` = ? AND `good` = ?', [$userid, $good]);
        if ($u === null || $c === null || (int)$c['qty'] < $qty) {
            return false;
        }
        // at home a good only fetches what it cost; anywhere else it fetches the mark-up
        $each = (trade_origins()[$good] ?? 0) === (int)$u['location'] ? MCC_TRADE_GOODS[$good][1] : trade_sell_price($good);
        $part = (int)round((int)$c['paid'] * $qty / (int)$c['qty']);
        if ($db->exec('UPDATE `v3_trade_cargo` SET `qty` = `qty` - ?, `paid` = `paid` - ? WHERE `userid` = ? AND `good` = ? AND `qty` >= ?', [$qty, $part, $userid, $good, $qty]) !== 1) {
            return false;
        }
        wallet_give($userid, 'money', $each * $qty);
        $out = ['got' => $each * $qty, 'profit' => $each * $qty - $part];
        return true;
    });
    if (is_array($out) && $out['profit'] > 0 && function_exists('progress_add')) {
        progress_add($userid, 'trade_profit', $out['profit']);
    }
    return $out;
}
