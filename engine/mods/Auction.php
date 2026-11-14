<?php
declare(strict_types=1);

/**
 * The auction house. A player puts items up with a starting price and a closing time; others bid; when the time is
 * up the highest bidder gets the items and the seller gets the money, less the house's cut.
 *
 * A bid is paid when it is made and held by the house. Being outbid returns it at once. So a winning bid is always
 * money that exists, and nobody can bid what they do not have.
 */
const MCC_AUCTION_HOURS = [1, 6, 24, 72];
const MCC_AUCTION_CUT_PERCENT = 5;         // of the winning bid; it leaves the game
const MCC_AUCTION_STEP_PERCENT = 5;        // a new bid must beat the last by at least this much (and by at least $1)
const MCC_AUCTION_EXTEND = 120;            // a bid in the last two minutes pushes the close back to two minutes away
const MCC_AUCTION_OPEN_LIMIT = 10;         // auctions one player may have running

/** The smallest bid an auction will take now. */
function auction_min_bid(array $a): int
{
    return (int)$a['bidder'] === 0 ? (int)$a['start_bid'] : (int)$a['bid'] + max(1, (int)ceil((int)$a['bid'] * MCC_AUCTION_STEP_PERCENT / 100));
}

/** Put items from one inventory stack up for auction. Returns the auction's id, or a string saying why not. */
function auction_list(int $seller, int $inv_id, int $qty, int $start_bid, int $hours): int|string
{
    global $db;
    if ($qty < 1 || $start_bid < 1 || $start_bid > 2000000000 || !in_array($hours, MCC_AUCTION_HOURS, true)) {
        return 'Give a quantity, a starting price of at least $1, and one of the closing times.';
    }
    if ((int)$db->value("SELECT COUNT(*) FROM `v3_auctions` WHERE `seller` = ? AND `status` = 'open'", [$seller]) >= MCC_AUCTION_OPEN_LIMIT) {
        return 'You already have ' . MCC_AUCTION_OPEN_LIMIT . ' auctions running.';
    }
    $item = (int)$db->value('SELECT `inv_itemid` FROM `inventory` WHERE `inv_id` = ? AND `inv_userid` = ?', [$inv_id, $seller], 0);
    $id = 'You do not have that many of that item.';
    $db->attempt(function () use ($db, $seller, $inv_id, $qty, $start_bid, $hours, $item, &$id) {
        if ($item === 0 || !item_take_row($seller, $inv_id, $qty)) {
            return false;
        }
        $id = $db->insert('v3_auctions', ['seller' => $seller, 'item' => $item, 'qty' => $qty, 'start_bid' => $start_bid, 'bid' => 0, 'bidder' => 0, 'ends' => time() + $hours * 3600, 'status' => 'open', 'created' => time()]);
        return true;
    });
    return $id;
}

/** Bid. Null when the bid stands, or why not. */
function auction_bid(int $bidder, int $auction_id, int $amount, ?int $now = null): ?string
{
    global $db;
    $now ??= time();
    $why = 'That auction is closed.';
    $outbid = 0;
    $ok = $db->attempt(function () use ($db, $bidder, $auction_id, $amount, $now, &$why, &$outbid) {
        // the auction's row is locked: bids on it are taken one at a time
        $a = $db->row("SELECT * FROM `v3_auctions` WHERE `id` = ? AND `status` = 'open' FOR UPDATE", [$auction_id]);
        if ($a === null || (int)$a['ends'] <= $now) {
            return false;
        }
        if ((int)$a['seller'] === $bidder) {
            $why = 'You cannot bid on your own auction.';
            return false;
        }
        if ((int)$a['bidder'] === $bidder) {
            $why = 'You are already the highest bidder.';
            return false;
        }
        if ($amount < auction_min_bid($a)) {
            $why = 'The lowest bid it will take now is ' . money_formatter(auction_min_bid($a)) . '.';
            return false;
        }
        if (!wallet_take($bidder, 'money', $amount)) {
            $why = 'You do not have ' . money_formatter($amount) . ' in your pocket.';
            return false;
        }
        if ((int)$a['bidder'] > 0) {
            wallet_give((int)$a['bidder'], 'money', (int)$a['bid']);          // the bid that was beaten goes straight back
            $outbid = (int)$a['bidder'];
        }
        $db->exec('UPDATE `v3_auctions` SET `bid` = ?, `bidder` = ?, `ends` = GREATEST(`ends`, ?) WHERE `id` = ?', [$amount, $bidder, $now + MCC_AUCTION_EXTEND, $auction_id]);
        return true;
    });
    if ($ok && $outbid > 0) {
        event_add($outbid, "You have been outbid at the <a href='auction.php'>auction house</a>. Your money is back in your pocket.");
    }
    return $ok ? null : $why;
}

/** Take an auction down. Only its seller, and only before anyone has bid. */
function auction_cancel(int $seller, int $auction_id): bool
{
    global $db;
    return $db->attempt(function () use ($db, $seller, $auction_id) {
        $a = $db->row("SELECT `item`, `qty` FROM `v3_auctions` WHERE `id` = ? AND `seller` = ? AND `status` = 'open' AND `bidder` = 0 FOR UPDATE", [$auction_id, $seller]);
        if ($a === null || $db->exec("UPDATE `v3_auctions` SET `status` = 'cancelled' WHERE `id` = ? AND `status` = 'open' AND `bidder` = 0", [$auction_id]) !== 1) {
            return false;
        }
        item_add($seller, (int)$a['item'], (int)$a['qty']);
        return true;
    });
}

/** Close every auction whose time is up: items to the winner, money to the seller, or items back if nobody bid. Returns how many closed. */
function auction_settle(?int $now = null): int
{
    global $db;
    $now ??= time();
    $closed = 0;
    foreach ($db->all("SELECT `id` FROM `v3_auctions` WHERE `status` = 'open' AND `ends` <= ? ORDER BY `ends` LIMIT 50", [$now]) as $row) {
        $db->transaction(function () use ($db, $row, $now, &$closed) {
            $a = $db->row("SELECT `a`.*, `i`.`itmname` FROM `v3_auctions` AS `a` LEFT JOIN `items` AS `i` ON `i`.`itmid` = `a`.`item` WHERE `a`.`id` = ? AND `a`.`status` = 'open' AND `a`.`ends` <= ? FOR UPDATE", [(int)$row['id'], $now]);
            if ($a === null) {
                return;                    // another request closed it first
            }
            $what = (int)$a['qty'] . ' x ' . ($a['itmname'] ?? 'item');
            if ((int)$a['bidder'] > 0) {
                $cut = (int)ceil((int)$a['bid'] * MCC_AUCTION_CUT_PERCENT / 100);
                $db->exec("UPDATE `v3_auctions` SET `status` = 'sold' WHERE `id` = ?", [(int)$a['id']]);
                item_add((int)$a['bidder'], (int)$a['item'], (int)$a['qty']);
                wallet_give((int)$a['seller'], 'money', (int)$a['bid'] - $cut);
                event_add((int)$a['bidder'], 'You won the auction for ' . e($what) . ' at ' . money_formatter((int)$a['bid']) . '. It is in your inventory.');
                event_add((int)$a['seller'], 'Your auction of ' . e($what) . ' sold for ' . money_formatter((int)$a['bid']) . '. After the house\'s ' . MCC_AUCTION_CUT_PERCENT . '% you received ' . money_formatter((int)$a['bid'] - $cut) . '.');
            } else {
                $db->exec("UPDATE `v3_auctions` SET `status` = 'expired' WHERE `id` = ?", [(int)$a['id']]);
                item_add((int)$a['seller'], (int)$a['item'], (int)$a['qty']);
                event_add((int)$a['seller'], 'Nobody bid on your auction of ' . e($what) . '. It is back in your inventory.');
            }
            $closed++;
        });
    }
    return $closed;
}

/** Auctions still running, soonest to close first. */
function auction_open(int $limit = 60): array
{
    global $db;
    return $db->all("SELECT `a`.*, `i`.`itmname`, `s`.`username` AS `sname`, `b`.`username` AS `bname` FROM `v3_auctions` AS `a` LEFT JOIN `items` AS `i` ON `i`.`itmid` = `a`.`item`
                     LEFT JOIN `users` AS `s` ON `s`.`userid` = `a`.`seller` LEFT JOIN `users` AS `b` ON `b`.`userid` = `a`.`bidder`
                     WHERE `a`.`status` = 'open' ORDER BY `a`.`ends` LIMIT " . max(1, min($limit, 200)));
}
