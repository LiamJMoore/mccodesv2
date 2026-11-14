<?php
declare(strict_types=1);

/**
 * Items and market listings that cannot be used twice.
 *
 * The same weakness as money in v2: a page read "the player has 5" or "the listing has 5", then took 5 in a later
 * statement. Two requests together both saw 5 and both took it, so one item could be sold twice and one market
 * listing bought twice. Here the check and the change are a single statement.
 */

/** Take $qty of an item from a player's inventory. False, and nothing changes, if they do not hold that many. */
function item_take(int $user, int $itemid, int $qty): bool
{
    global $db;
    if ($qty < 1) {
        return false;
    }
    if ($db->exec('UPDATE `inventory` SET `inv_qty` = `inv_qty` - ? WHERE `inv_userid` = ? AND `inv_itemid` = ? AND `inv_qty` >= ? LIMIT 1', [$qty, $user, $itemid, $qty]) !== 1) {
        return false;
    }
    $db->exec('DELETE FROM `inventory` WHERE `inv_userid` = ? AND `inv_itemid` = ? AND `inv_qty` <= 0', [$user, $itemid]);
    return true;
}

/** Take $qty from one inventory row (a specific stack), which must belong to $user. */
function item_take_row(int $user, int $inv_id, int $qty): bool
{
    global $db;
    if ($qty < 1) {
        return false;
    }
    if ($db->exec('UPDATE `inventory` SET `inv_qty` = `inv_qty` - ? WHERE `inv_id` = ? AND `inv_userid` = ? AND `inv_qty` >= ?', [$qty, $inv_id, $user, $qty]) !== 1) {
        return false;
    }
    $db->exec('DELETE FROM `inventory` WHERE `inv_id` = ? AND `inv_qty` <= 0', [$inv_id]);
    return true;
}

/** The markets: table => [id column, quantity column]. */
const MCC_LISTINGS = ['itemmarket' => ['imID', 'imQTY'], 'crystalmarket' => ['cmID', 'cmQTY']];

/**
 * Take $qty out of a market listing; the listing is removed when it reaches zero.
 * False, and nothing changes, if the listing has gone or holds fewer than $qty.
 */
function listing_claim(string $market, int $id, int $qty): bool
{
    global $db;
    if (!isset(MCC_LISTINGS[$market]) || $qty < 1) {
        return false;
    }
    [$idcol, $qtycol] = MCC_LISTINGS[$market];
    if ($db->exec("UPDATE `{$market}` SET `{$qtycol}` = `{$qtycol}` - ? WHERE `{$idcol}` = ? AND `{$qtycol}` >= ?", [$qty, $id, $qty]) !== 1) {
        return false;
    }
    $db->exec("DELETE FROM `{$market}` WHERE `{$idcol}` = ? AND `{$qtycol}` <= 0", [$id]);
    return true;
}
