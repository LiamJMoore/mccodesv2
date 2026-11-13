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
$_POST['qty'] =
        (isset($_POST['qty']) && is_numeric($_POST['qty']))
                ? abs(intval($_POST['qty'])) : '';

if (empty($_GET['ID']) OR empty($_POST['qty']))
{
    echo 'Invalid use of file';
}
else
{
    $q =
            $db->query(
                    "SELECT `itmid`, `itmbuyprice`, `itmname`, `itmbuyable`, `shopLOCATION`
                     FROM `shopitems` AS `si`
                     INNER JOIN `shops` AS `s`
                     ON `si`.`sitemSHOP` = `s`.`shopID`
                     INNER JOIN `items` AS `i`
                     ON `si`.`sitemITEMID` = `i`.`itmid`
                     WHERE `sitemID` = {$_GET['ID']}");
    if ($db->num_rows($q) == 0)
    {
        echo 'Invalid item ID';
    }
    else
    {
        $itemd = $db->fetch_row($q);
        if (!isset($_POST['verf']) || !verify_csrf_code('shop_buy', stripslashes((string)$_POST['verf'])))
        {
            echo 'That shop page had expired. Nothing was bought; please go back to the shop and try again.
            <br />&gt; <a href="shops.php">Back to the shops</a>';
            $h->endpage();
            exit;
        }
        $price = (int)($itemd['itmbuyprice'] * $_POST['qty']);
        if ($itemd['itmbuyable'] == 0 || $itemd['shopLOCATION'] != $ir['location'])
        {
            // said in full below
        }
        elseif (!wallet_take($userid, 'money', $price))      // engine v3: the money is taken only if it is there
        {
            echo 'You don\'t have enough money to buy ' . $_POST['qty'] . ' '
                    . $itemd['itmname']
                    . '!<br />&gt; <a href="index.php">Go Home</a>';
            $h->endpage();
            exit;
        }
        if ($itemd['itmbuyable'] == 0)
        {
            echo 'This item can\'t be bought!
            <br />&gt; <a href="index.php">Go Home</a>';
            $h->endpage();
            exit;
        }
        if ($itemd['shopLOCATION'] != $ir['location'])
        {
            echo 'You can\'t buy items from other cities.
            <br />&gt; <a href="index.php">Go Home</a>';
            $h->endpage();
            exit;
        }

        item_add($userid, (int) $itemd['itmid'], (int) $_POST['qty']);
        $ib_log =
                $db->escape(
                        "{$ir['username']} bought {$_POST['qty']} "
                                . "{$itemd['itmname']}(s) for {$price}");
        $db->query(
                "INSERT INTO `itembuylogs`
                 VALUES (NULL, $userid, {$itemd['itmid']}, $price, {$_POST['qty']},
                  " . time() . ", '{$ib_log}')");
        echo 'You bought ' . $_POST['qty'] . ' ' . $itemd['itmname'] . ' '
                . (($_POST['qty'] > 1) ? 's' : '') . ' for '
                . money_formatter($price)
                . '<br />&gt; <a href="inventory.php">Goto your inventory</a>';
    }
    $db->free_result($q);
}
$h->endpage();
