<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: marriage.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Marriage</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['propose', 'withdraw', 'accept', 'decline', 'deposit', 'draw', 'movein', 'moveout', 'divorce'], '', $_POST);
    if (!verify_csrf_code('marriage', in_str('verf', 64, '', $_POST)) || $do === '') {
        echo '<p>That form had expired. Please try again.</p>';
    } else {
        $who = in_int('who', 0, $_POST);
        $amount = in_int('amount', 0, $_POST);
        $msg = match ($do) {
            'propose' => marriage_propose($userid, $who) ?? 'You have proposed. Now you wait.',
            'withdraw' => marriage_unpropose($userid, $userid) ? 'Proposal withdrawn. The ring money is back in your pocket.' : 'You have no proposal out.',
            'decline' => marriage_unpropose($userid, $who) ? 'You said no.' : 'That proposal is no longer open.',
            'accept' => marriage_accept($userid, $who) ?? 'You are married!',
            'deposit' => marriage_deposit($userid, $amount) ? 'Paid into the joint account.' : 'You do not have that much in your pocket.',
            'draw' => marriage_withdraw($userid, $amount) ? 'Taken from the joint account.' : 'The joint account does not hold that much.',
            'movein' => marriage_move_in($userid) ?? 'You have moved in.',
            'moveout' => marriage_move_out($userid) ? 'You are back at your own place.' : 'You were not living with your spouse.',
            'divorce' => !empty($_POST['sure']) ? (marriage_divorce($userid) ?? 'You are divorced.') : 'Tick the box to confirm.',
        };
        echo '<p><b>' . e($msg) . '</b></p>';
    }
}
$token = request_csrf_html('marriage');
$form = fn (string $do, string $label, string $extra = ''): string => "<form action='marriage.php' method='post' style='display:inline-block;margin:4px 6px 4px 0;'>{$token}<input type='hidden' name='do' value='{$do}' />{$extra}<input type='submit' value='" . e($label) . "' /></form>";
$sp = marriage_spouse($userid);
if ($sp !== null) {
    $spouse = (int)$sp['spouse'];
    $me = $db->row('SELECT `money`, `maxwill` FROM `users` WHERE `userid` = ?', [$userid]);
    $guest = $sp['own_will'] !== null;
    $hosting = marriage_is_guest($spouse);
    echo "<div class='season-head'><div><small>Married to</small><b><a href='viewuser.php?u={$spouse}'>" . perk_name($spouse, (string)$sp['username']) . "</a></b></div><div><small>For</small><b>" . intdiv(time() - (int)$sp['since'], 86400) . " days</b></div>
    <div><small>Joint account</small><b>" . money_formatter(marriage_joint($userid, $spouse)) . '</b></div><div><small>In your pocket</small><b>' . money_formatter((int)$me['money']) . "</b></div></div>
    <h4>Joint account</h4><p>Either of you can pay in or take out. It cannot be mugged.</p>"
        . $form('deposit', 'Pay in', "\$<input type='text' name='amount' size='10' aria-label='Amount to pay in' /> ")
        . $form('draw', 'Take out', "\$<input type='text' name='amount' size='10' aria-label='Amount to take out' /> ")
        . '<h4>Living together</h4>';
    if ($guest) {
        echo '<p>You live at your spouse\'s house (will bar ' . (int)$me['maxwill'] . '). Your own place (will bar ' . (int)$sp['own_will'] . ') is waiting; you cannot use the estate agent until you move back.</p>' . $form('moveout', 'Move back to my own place');
    } elseif ($hosting) {
        echo '<p>Your spouse lives with you. If you change house, theirs changes with it.</p>';
    } elseif ((int)$sp['maxwill'] > (int)$me['maxwill']) {
        echo '<p>Their house has a will bar of ' . (int)$sp['maxwill'] . '; yours is ' . (int)$me['maxwill'] . '. Move in and you take on theirs. You keep your own house and can move back whenever you like.</p>' . $form('movein', 'Move in with my spouse');
    } else {
        echo '<p>Your house is the better one. Your spouse can move in with you from their own Marriage page.</p>';
    }
    echo '<h4>Divorce</h4><p>Filing costs ' . money_formatter(MCC_DIVORCE_PRICE) . '. The joint account is split evenly and anyone living with their spouse moves back to their own place.</p>'
        . $form('divorce', 'File for divorce', "<label><input type='checkbox' name='sure' value='1' /> I am sure</label> ");
} else {
    $out = $db->row('SELECT `p`.`target`, `u`.`username` FROM `v3_proposals` AS `p` INNER JOIN `users` AS `u` ON `u`.`userid` = `p`.`target` WHERE `p`.`proposer` = ?', [$userid]);
    $in = $db->all('SELECT `p`.`proposer`, `u`.`username`, `u`.`level` FROM `v3_proposals` AS `p` INNER JOIN `users` AS `u` ON `u`.`userid` = `p`.`proposer` WHERE `p`.`target` = ? ORDER BY `p`.`at`', [$userid]);
    echo '<p>Marry another player and you get a joint account, your names on each other\'s profiles, and you can live together in the better of your two houses.</p>';
    foreach ($in as $p) {
        echo "<div class='store-note ok'><a href='viewuser.php?u={$p['proposer']}'>" . perk_name((int)$p['proposer'], (string)$p['username']) . "</a> (level {$p['level']}) has proposed to you. "
            . $form('accept', 'Accept', "<input type='hidden' name='who' value='{$p['proposer']}' />") . $form('decline', 'Decline', "<input type='hidden' name='who' value='{$p['proposer']}' />") . '</div>';
    }
    if ($out !== null) {
        echo "<p>You have proposed to <a href='viewuser.php?u={$out['target']}'>" . e($out['username']) . '</a> and are waiting for an answer.</p>' . $form('withdraw', 'Withdraw the proposal');
    } else {
        echo '<h4>Propose</h4><p>A ring costs ' . money_formatter(MCC_RING_PRICE) . '. If they say no, or you change your mind first, you get it back.</p>'
            . $form('propose', 'Propose', "Player ID <input type='text' name='who' size='8' value='" . (in_int('to', 0, $_GET) ?: '') . "' /> ");
    }
}
$h->endpage();
