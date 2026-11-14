<?php
declare(strict_types=1);
/**
 * MCCodes v2 by Dabomstew & ColdBlooded
 *
 * Repository: https://github.com/davemacaulay/mccodesv2
 * License: MIT License
 */

global $db, $ir, $userid, $h;
require_once('globals.php');
if (!isset($_GET['type'])
        || !in_array($_GET['type'],
                ['equip_primary', 'equip_secondary', 'equip_armor'],
                true))
{
    echo 'This slot ID is not valid.';
    $h->endpage();
    exit;
}
if ($ir[$_GET['type']] == 0)
{
    echo 'You do not have anything equipped in this slot.';
    $h->endpage();
    exit;
}
// engine v3: the slot is emptied only if it still holds the item, and the item is handed back only if it was. v2
// handed one back for every request that arrived before the first finished: one weapon became many.
$unequipped = $db->attempt(function () use ($db, $userid, $ir) {
    if ($db->exec("UPDATE `users` SET `{$_GET['type']}` = 0 WHERE `userid` = ? AND `{$_GET['type']}` = ?", [$userid, (int) $ir[$_GET['type']]]) !== 1)
    {
        return false;
    }
    item_add($userid, (int) $ir[$_GET['type']], 1);
    return true;
});
if (!$unequipped)
{
    echo 'You do not have anything equipped in this slot.';
    $h->endpage();
    exit;
}
$names =
        ['equip_primary' => 'Primary Weapon',
                'equip_secondary' => 'Secondary Weapon',
                'equip_armor' => 'Armor'];
echo 'The item in your ' . $names[$_GET['type']]
        . ' slot was successfully unequipped.';
$h->endpage();
