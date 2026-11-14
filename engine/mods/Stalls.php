<?php
declare(strict_types=1);

/**
 * Market stalls: a player's own shop front. They name it, stock it from their inventory at prices they choose, and
 * other players buy from it. The money goes straight to the owner; the items wait in the stall, not the inventory.
 *
 * A sale locks the line being bought, so two buyers cannot both take the last one, and the buyer names the price
 * they saw, so an owner cannot raise it in the moment between looking and paying.
 */
const MCC_STALL_FEE = 25000;              // to open one; it leaves the game
const MCC_STALL_LINES = 20;               // different items one stall can stock
const MCC_STALL_PRICE_MAX = 2000000000;

function stall_name_clean(string $name): string
{
    return trim((string)preg_replace('/\s+/', ' ', strip_tags($name)));
}

function stall_of(int $owner): ?array
{
    global $db;
    return $db->row('SELECT `s`.`userid`, `s`.`name`, `s`.`opened`, `u`.`username` FROM `v3_stalls` AS `s` INNER JOIN `users` AS `u` ON `u`.`userid` = `s`.`userid` WHERE `s`.`userid` = ?', [$owner]);
}

function stall_open(int $userid, string $name): ?string
{
    global $db;
    $name = stall_name_clean($name);
    if (strlen($name) < 3 || strlen($name) > 40) {
        return 'Give your stall a name of 3 to 40 characters.';
    }
    $why = 'You cannot afford the ' . money_formatter(MCC_STALL_FEE) . ' pitch fee.';
    $db->attempt(function () use ($db, $userid, $name, &$why) {
        if ($db->exec('INSERT IGNORE INTO `v3_stalls` (`userid`, `name`, `opened`) VALUES (?, ?, ?)', [$userid, $name, time()]) !== 1) {
            $why = 'You already have a stall.';
            return false;
        }
        if (!wallet_take($userid, 'money', MCC_STALL_FEE)) {
            return false;
        }
        $why = null;
        return true;
    });
    return $why;
}

function stall_rename(int $userid, string $name): bool
{
    global $db;
    $name = stall_name_clean($name);
    return strlen($name) >= 3 && strlen($name) <= 40 && stall_of($userid) !== null && $db->exec('UPDATE `v3_stalls` SET `name` = ? WHERE `userid` = ?', [$name, $userid]) >= 0;
}

/** What a stall has for sale. */
function stall_stock(int $owner): array
{
    global $db;
    return $db->all('SELECT `k`.`id`, `k`.`item`, `k`.`qty`, `k`.`price`, `i`.`itmname` FROM `v3_stall_stock` AS `k` INNER JOIN `items` AS `i` ON `i`.`itmid` = `k`.`item` WHERE `k`.`userid` = ? AND `k`.`qty` > 0 ORDER BY `i`.`itmname`', [$owner]);
}

/** Move items from the inventory onto the stall at a price each. Adding more of an item already stocked sets its price. */
function stall_add(int $userid, int $inv_id, int $qty, int $price): ?string
{
    global $db;
    if ($qty < 1 || $price < 1 || $price > MCC_STALL_PRICE_MAX) {
        return 'Give a quantity and a price of at least $1.';
    }
    $item = (int)$db->value('SELECT `inv_itemid` FROM `inventory` WHERE `inv_id` = ? AND `inv_userid` = ?', [$inv_id, $userid], 0);
    $why = 'You do not have that many of that item.';
    $db->attempt(function () use ($db, $userid, $inv_id, $qty, $price, $item, &$why) {
        if ($db->value('SELECT `userid` FROM `v3_stalls` WHERE `userid` = ? FOR UPDATE', [$userid]) === null) {
            $why = 'Open a stall first.';
            return false;
        }
        $lines = array_map('intval', array_column($db->all('SELECT `item` FROM `v3_stall_stock` WHERE `userid` = ?', [$userid]), 'item'));
        if (!in_array($item, $lines, true) && count($lines) >= MCC_STALL_LINES) {
            $why = 'A stall holds ' . MCC_STALL_LINES . ' different items.';
            return false;
        }
        if ($item === 0 || !item_take_row($userid, $inv_id, $qty)) {
            return false;
        }
        $db->exec('INSERT INTO `v3_stall_stock` (`userid`, `item`, `qty`, `price`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `qty` = `qty` + VALUES(`qty`), `price` = VALUES(`price`)', [$userid, $item, $qty, $price]);
        $why = null;
        return true;
    });
    return $why;
}

/** Take a line off the stall and back into the inventory. True if it was the owner's and came back. */
function stall_remove(int $userid, int $line): bool
{
    global $db;
    return $db->attempt(function () use ($db, $userid, $line) {
        $k = $db->row('SELECT `item`, `qty` FROM `v3_stall_stock` WHERE `id` = ? AND `userid` = ? FOR UPDATE', [$line, $userid]);
        if ($k === null || $db->exec('DELETE FROM `v3_stall_stock` WHERE `id` = ? AND `userid` = ?', [$line, $userid]) !== 1) {
            return false;
        }
        if ((int)$k['qty'] > 0) {
            item_add($userid, (int)$k['item'], (int)$k['qty']);
        }
        return true;
    });
}

/** Buy from a stall at the price the buyer saw. Null when bought, or a string saying why not. */
function stall_buy(int $buyer, int $line, int $qty, int $seen_price): ?string
{
    global $db;
    if ($qty < 1) {
        return 'How many?';
    }
    $why = 'You cannot afford that.';
    $db->attempt(function () use ($db, $buyer, $line, $qty, $seen_price, &$why) {
        $k = $db->row('SELECT `k`.`userid`, `k`.`item`, `k`.`qty`, `k`.`price`, `i`.`itmname` FROM `v3_stall_stock` AS `k` INNER JOIN `items` AS `i` ON `i`.`itmid` = `k`.`item` WHERE `k`.`id` = ? FOR UPDATE', [$line]);
        if ($k === null || (int)$k['qty'] < $qty) {
            $why = 'The stall does not have that many left.';
            return false;
        }
        if ((int)$k['userid'] === $buyer) {
            $why = 'That is your own stall.';
            return false;
        }
        if ((int)$k['price'] !== $seen_price) {
            $why = 'The price has changed. Look again.';
            return false;
        }
        $total = (int)$k['price'] * $qty;
        if (!wallet_send($buyer, (int)$k['userid'], 'money', $total)) {
            return false;
        }
        if ((int)$k['qty'] === $qty) {
            $db->exec('DELETE FROM `v3_stall_stock` WHERE `id` = ?', [$line]);
        } else {
            $db->exec('UPDATE `v3_stall_stock` SET `qty` = `qty` - ? WHERE `id` = ?', [$qty, $line]);
        }
        item_add($buyer, (int)$k['item'], $qty);
        $buyer_name = (string)$db->value('SELECT `username` FROM `users` WHERE `userid` = ?', [$buyer]);
        event_add((int)$k['userid'], "<a href='viewuser.php?u={$buyer}'>" . e($buyer_name) . "</a> bought {$qty} x " . e((string)$k['itmname']) . ' from your stall for ' . money_formatter($total) . '.');
        $why = null;
        return true;
    });
    return $why;
}

/** Close an empty stall. The pitch fee is not returned. */
function stall_close(int $userid): bool
{
    global $db;
    return $db->attempt(function () use ($db, $userid) {
        return $db->value('SELECT `userid` FROM `v3_stalls` WHERE `userid` = ? FOR UPDATE', [$userid]) !== null
            && $db->value('SELECT 1 FROM `v3_stall_stock` WHERE `userid` = ? LIMIT 1', [$userid]) === null
            && $db->exec('DELETE FROM `v3_stalls` WHERE `userid` = ?', [$userid]) === 1;
    });
}

/** Every stall with something on it, busiest first. */
function stall_directory(int $limit = 100): array
{
    global $db;
    return $db->all('SELECT `s`.`userid`, `s`.`name`, `u`.`username`, COUNT(`k`.`id`) AS `lines`, COALESCE(SUM(`k`.`qty`), 0) AS `items` FROM `v3_stalls` AS `s` INNER JOIN `users` AS `u` ON `u`.`userid` = `s`.`userid`
        INNER JOIN `v3_stall_stock` AS `k` ON `k`.`userid` = `s`.`userid` AND `k`.`qty` > 0 GROUP BY `s`.`userid`, `s`.`name`, `u`.`username` ORDER BY `lines` DESC, `s`.`userid` LIMIT ' . max(1, min($limit, 200)));
}

/** Who sells an item, cheapest first. */
function stall_find(string $item_name, int $limit = 50): array
{
    global $db;
    $like = '%' . addcslashes(trim($item_name), '%_\\') . '%';
    return $db->all('SELECT `k`.`id`, `k`.`userid`, `k`.`qty`, `k`.`price`, `i`.`itmname`, `s`.`name` FROM `v3_stall_stock` AS `k` INNER JOIN `items` AS `i` ON `i`.`itmid` = `k`.`item` INNER JOIN `v3_stalls` AS `s` ON `s`.`userid` = `k`.`userid`
        WHERE `k`.`qty` > 0 AND `i`.`itmname` LIKE ? ORDER BY `k`.`price`, `k`.`id` LIMIT ' . max(1, min($limit, 100)), [$like]);
}
