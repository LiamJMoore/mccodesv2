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
$cg_price = 500000;
if ($ir['money'] < $cg_price)
{
    echo "You don't have enough money. You need " . money_formatter($cg_price)
            . '.';
    $h->endpage();
    exit;
}
if ($ir['gang'])
{
    echo "You're already in a gang!";
    $h->endpage();
    exit;
}
if (isset($_POST['submit']) && isset($_POST['desc'])
        && !empty($_POST['name']))
{
    if (!isset($_POST['verf'])
            || !verify_csrf_code('creategang', stripslashes($_POST['verf'])))
    {
        echo '<h3>Error</h3><hr />
    This transaction has been blocked for your security.<br />
    Please create your gang quickly after you open the form - do not leave it open in tabs.<br />
    &gt; <a href="creategang.php">Try Again</a>';
        $h->endpage();
        exit;
    }
    $name =
            $db->escape(
                    htmlentities(stripslashes($_POST['name']), ENT_QUOTES,
                            'ISO-8859-1'));
    $desc =
            $db->escape(
                    htmlentities(stripslashes($_POST['desc']), ENT_QUOTES,
                            'ISO-8859-1'));
    // engine v3: the gang is founded and paid for together, and only by a player who is not in a gang and has the
    // money at that moment
    $created = $db->attempt(function () use ($db, $userid, $name, $desc, $cg_price) {
        $db->query(
                "INSERT INTO `gangs`
                    (`gangNAME`, `gangDESC`, `gangRESPECT`, `gangPRESIDENT`, `gangVICEPRES`, `gangCAPACITY`, `gangAMENT`)
                     VALUES('$name', '$desc', 100, $userid, $userid, 5, '')");
        $i = $db->insert_id();
        return $db->exec('UPDATE `users` SET `gang` = ?, `money` = `money` - ? WHERE `userid` = ? AND `gang` = 0 AND `money` >= ?', [$i, $cg_price, $userid, $cg_price]) === 1;
    });
    if (!$created)
    {
        echo "You're already in a gang, or you no longer have " . money_formatter($cg_price) . '.';
        $h->endpage();
        exit;
    }
    echo 'Gang created!';
}
else
{
    $code = request_csrf_code('creategang');
    echo "<h3> Create A Gang </h3>
    <form action='creategang.php' method='post'>
    <input type='hidden' name='submit' value='1' />
    Name:<input type='text' name='name' /><br />
    Description:<br />
    <textarea name='desc' cols='40' rows='7'></textarea>
    <br />
    <input type='hidden' name='verf' value='{$code}' />
    <input type='submit' value='Create Gang for " . money_formatter($cg_price)
            . "' />
    </form>";
}
$h->endpage();
