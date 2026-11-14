<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: bounties and the hitlist.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>The Hitlist</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['place', 'withdraw'], '', $_POST);
    if (!verify_csrf_code('bounty_' . $do, in_str('verf', 64, '', $_POST))) {
        echo '<p>That form had expired. Please try again.</p>';
    } elseif ($do === 'place') {
        $target = in_int('target', 0, $_POST);
        $amount = in_int('amount', 0, $_POST);
        $why = bounty_place($userid, $target, $amount, !empty($_POST['anonymous']));
        if ($why !== null) {
            echo '<p><b>Not placed.</b> ' . e($why) . '</p>';
        } else {
            $ir['money'] -= $amount + bounty_fee($amount);
            echo '<p><b>Bounty placed.</b> ' . money_formatter($amount) . ' is on their head; the fee was ' . money_formatter(bounty_fee($amount)) . '.</p>';
            if (empty($_POST['anonymous'])) {
                event_add($target, "<a href='viewuser.php?u={$userid}'>" . e($ir['username']) . '</a> has put a bounty of ' . money_formatter($amount) . " on your head. <a href='bounties.php'>See the hitlist</a>");
            } else {
                event_add($target, 'Someone has put a bounty of ' . money_formatter($amount) . " on your head. <a href='bounties.php'>See the hitlist</a>");
            }
        }
    } elseif ($do === 'withdraw') {
        echo bounty_withdraw($userid, in_int('id', 0, $_POST))
            ? '<p><b>Bounty withdrawn.</b> The money is back in your pocket. The fee is not refunded.</p>'
            : '<p>That bounty cannot be withdrawn: it has been collected or withdrawn already.</p>';
    }
}
echo '<p>Put a price on another player. Whoever beats them in a fight and <b>mugs</b> or <b>hospitalises</b> them is paid it.</p>';
$list = bounty_hitlist();
if (!$list) {
    echo '<p>Nobody has a price on their head. Yet.</p>';
} else {
    echo "<table width='95%' cellspacing='1' class='table'><tr><th>Player</th><th>Level</th><th>On their head</th><th>Bounties</th><th>Where</th><th></th></tr>";
    foreach ($list as $r) {
        $where = (int)$r['hospital'] > 0 ? 'In hospital' : ((int)$r['jail'] > 0 ? 'In jail' : ((int)$r['location'] === (int)$ir['location'] ? 'In your city' : 'Another city'));
        $can = (int)$r['target'] !== $userid && (int)$r['hospital'] === 0 && (int)$r['jail'] === 0 && (int)$r['location'] === (int)$ir['location'];
        echo "<tr><td><a href='viewuser.php?u={$r['target']}'>" . perk_name((int)$r['target'], (string)$r['username']) . "</a> [{$r['target']}]</td><td>{$r['level']}</td><td><b>" . money_formatter((int)$r['total'])
            . "</b></td><td>{$r['bounties']}</td><td>{$where}</td><td>" . ($can ? "<a href='attack.php?ID={$r['target']}'>Attack</a>" : ((int)$r['target'] === $userid ? 'You' : '')) . '</td></tr>';
    }
    echo '</table>';
}
$target = in_int('target', 0, $_GET);
echo "<h4>Place a bounty</h4>
<form action='bounties.php' method='post'>" . request_csrf_html('bounty_place') . "<input type='hidden' name='do' value='place' />
<table cellspacing='1' class='table'>
<tr><td>Player ID</td><td><input type='text' name='target' value='" . ($target ?: '') . "' size='8' /></td></tr>
<tr><td>Bounty</td><td><input type='text' name='amount' value='" . MCC_BOUNTY_MIN . "' size='12' /> (at least " . money_formatter(MCC_BOUNTY_MIN) . ', plus a ' . MCC_BOUNTY_FEE_PERCENT . "% fee)</td></tr>
<tr><td>Anonymous</td><td><label><input type='checkbox' name='anonymous' value='1' /> Do not tell them who placed it</label></td></tr>
<tr><td colspan='2' align='center'><input type='submit' value='Place bounty' /></td></tr>
</table></form>
<p>You have " . money_formatter((int)$ir['money']) . ' in your pocket.</p>';
$mine = $db->all("SELECT `b`.`id`, `b`.`target`, `b`.`amount`, `b`.`placed`, `u`.`username` FROM `v3_bounties` AS `b` LEFT JOIN `users` AS `u` ON `u`.`userid` = `b`.`target`
                  WHERE `b`.`placer` = ? AND `b`.`status` = 'open' ORDER BY `b`.`id` DESC", [$userid]);
if ($mine) {
    $token = request_csrf_html('bounty_withdraw');
    echo "<h4>Your open bounties</h4><table width='75%' cellspacing='1' class='table'><tr><th>On</th><th>Bounty</th><th>Placed</th><th></th></tr>";
    foreach ($mine as $b) {
        echo "<tr><td><a href='viewuser.php?u={$b['target']}'>" . e((string)$b['username']) . '</a></td><td>' . money_formatter((int)$b['amount']) . '</td><td>' . date('j M, H:i', (int)$b['placed'])
            . "</td><td><form action='bounties.php' method='post' style='margin:0;'>{$token}<input type='hidden' name='do' value='withdraw' /><input type='hidden' name='id' value='{$b['id']}' /><input type='submit' value='Withdraw' /></form></td></tr>";
    }
    echo '</table>';
}
$recent = $db->all("SELECT `b`.`amount`, `b`.`closed`, `b`.`target`, `b`.`collector`, `t`.`username` AS `tname`, `c`.`username` AS `cname`
                    FROM `v3_bounties` AS `b` LEFT JOIN `users` AS `t` ON `t`.`userid` = `b`.`target` LEFT JOIN `users` AS `c` ON `c`.`userid` = `b`.`collector`
                    WHERE `b`.`status` = 'collected' ORDER BY `b`.`closed` DESC, `b`.`id` DESC LIMIT 10");
if ($recent) {
    echo "<h4>Recently collected</h4><table width='75%' cellspacing='1' class='table'><tr><th>Hunter</th><th>Target</th><th>Paid</th><th>When</th></tr>";
    foreach ($recent as $b) {
        echo "<tr><td><a href='viewuser.php?u={$b['collector']}'>" . e((string)$b['cname']) . "</a></td><td><a href='viewuser.php?u={$b['target']}'>" . e((string)$b['tname']) . '</a></td><td>'
            . money_formatter((int)$b['amount']) . '</td><td>' . date('j M, H:i', (int)$b['closed']) . '</td></tr>';
    }
    echo '</table>';
}
$h->endpage();
