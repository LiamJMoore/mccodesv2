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
$_GET['ID'] =
        (isset($_GET['ID']) && is_numeric($_GET['ID']))
                ? abs(intval($_GET['ID'])) : '';
if (!$_GET['ID'])
{
    echo 'Invalid use of file';
}
else
{
    $i =
            $db->query(
                    "SELECT `effect1`, `effect2`, `effect3`,
                     `effect1_on`, `effect2_on`, `effect3_on`,
                     `itmname`, `inv_itemid`
                     FROM `inventory` AS `iv`
                     INNER JOIN `items` AS `i`
                     ON `iv`.`inv_itemid` = `i`.`itmid`
                     WHERE `iv`.`inv_id` = {$_GET['ID']}
                     AND `iv`.`inv_userid` = $userid");
    if ($db->num_rows($i) == 0)
    {
        $db->free_result($i);
        echo 'Invalid item ID';
    }
    else
    {
        $r = $db->fetch_row($i);
        $db->free_result($i);
        if (!$r['effect1_on'] && !$r['effect2_on'] && !$r['effect3_on'])
        {
            echo 'Sorry, this item cannot be used as it has no effect.';
            $h->endpage();
            exit;
        }
        // engine v3: the item is used up first, in one statement that refuses if it is not there; only then does it
        // take effect. v2 applied the effect and removed the item afterwards, so one item used from many requests
        // at once worked every time. Effects are now added to what the player has, not written over it.
        if (!item_take_row($userid, $_GET['ID'], 1))
        {
            echo 'Invalid item ID';
            $h->endpage();
            exit;
        }
        $bars = ['energy', 'will', 'brave', 'hp'];
        $stats = ['strength', 'agility', 'guard', 'labour', 'IQ'];
        $others = ['money', 'crystals', 'cybermoney', 'bankmoney', 'exp', 'crimexp', 'hospital', 'jail', 'maxenergy', 'maxwill', 'maxbrave', 'maxhp', 'donatordays', 'mailban', 'fedjail'];
        for ($enum = 1; $enum <= 3; $enum++)
        {
            if (!$r["effect{$enum}_on"])
            {
                continue;
            }
            $einfo = unserialize((string) $r["effect{$enum}"], ['allowed_classes' => false]);
            $stat = is_array($einfo) ? (string) ($einfo['stat'] ?? '') : '';
            if (!in_array($stat, array_merge($bars, $stats, $others), true))
            {
                continue;       // not something an item may change
            }
            $is_bar = in_array($stat, $bars, true);
            $table = in_array($stat, $stats, true) ? 'userstats' : 'users';
            if (($einfo['inc_type'] ?? '') == 'percent')
            {
                $inc = round(($is_bar ? $ir['max' . $stat] : $ir[$stat]) / 100 * $einfo['inc_amount']);
            }
            else
            {
                $inc = $einfo['inc_amount'];
            }
            $inc = abs((float) $inc);
            if (($einfo['dir'] ?? '') == 'pos')
            {
                $db->exec($is_bar
                        ? "UPDATE `users` SET `{$stat}` = LEAST(GREATEST(`{$stat}`, `max{$stat}`), `{$stat}` + ?) WHERE `userid` = ?"
                        : "UPDATE `{$table}` SET `{$stat}` = `{$stat}` + ? WHERE `userid` = ?", [$inc, $userid]);
            }
            else
            {
                // an unopened bank account (-1) is left alone
                $db->exec("UPDATE `{$table}` SET `{$stat}` = GREATEST(`{$stat}` - ?, LEAST(`{$stat}`, 0)) WHERE `userid` = ?", [$inc, $userid]);
            }
        }
        echo $r['itmname'] . ' used successfully!';
    }
}
$h->endpage();
