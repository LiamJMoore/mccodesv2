<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: the loan shark.
 */
global $db, $ir, $userid, $h;
require_once('globals.php');
echo '<h3>Loan Shark</h3>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = in_choice('do', ['borrow', 'repay'], '', $_POST);
    if (!verify_csrf_code('loan', in_str('verf', 64, '', $_POST)) || $do === '') {
        echo '<p>That form had expired. Please try again.</p>';
    } elseif ($do === 'borrow') {
        $why = loan_take($userid, in_int('amount', 0, $_POST), (int)$ir['level']);
        echo $why === null ? '<p><b>He counts it out.</b> Do not be late.</p>' : '<p><b>No.</b> ' . e($why) . '</p>';
    } else {
        $paid = loan_repay($userid, in_int('amount', 0, $_POST));
        echo $paid > 0 ? '<p><b>Paid ' . money_formatter($paid) . '.</b></p>' : '<p>Nothing was paid: you owe nothing, or you do not have that in your pocket.</p>';
    }
}
$l = loan_of($userid);
$money = (int)$db->value('SELECT `money` FROM `users` WHERE `userid` = ?', [$userid]);
$token = request_csrf_html('loan');
echo '<p>Cash now, more back later. He adds ' . (loan_rate_permille($userid) / 10) . '% of what you borrowed every day, starting today, and wants it all within ' . MCC_LOAN_TERM_DAYS
    . ' days. After that his men come for it: they take what is in your pocket and your bank, and if that is not enough they put you in hospital and come back tomorrow.</p>';
if ($l === null) {
    echo "<form action='loanshark.php' method='post'>{$token}<input type='hidden' name='do' value='borrow' />Borrow \$<input type='text' name='amount' size='10' value='" . loan_max((int)$ir['level']) . "' /> <input type='submit' value='Borrow' />
    <small>(between " . money_formatter(MCC_LOAN_MIN) . ' and ' . money_formatter(loan_max((int)$ir['level'])) . ' at your level)</small></form>';
} else {
    $left = $l['due'] - time();
    echo "<div class='season-head'><div><small>Borrowed</small><b>" . money_formatter($l['principal']) . '</b></div><div><small>Interest so far</small><b>' . money_formatter($l['interest']) . '</b></div><div><small>Paid back</small><b>'
        . money_formatter($l['repaid']) . '</b></div><div><small>You owe</small><b>' . money_formatter($l['owed']) . '</b></div><div><small>' . ($l['overdue'] ? 'Status' : 'Due in') . '</small><b>'
        . ($l['overdue'] ? 'Overdue' : intdiv($left, 86400) . 'd ' . intdiv($left % 86400, 3600) . 'h') . "</b></div></div>
    <form action='loanshark.php' method='post'>{$token}<input type='hidden' name='do' value='repay' />Pay back \$<input type='text' name='amount' size='10' value='" . min($money, $l['owed']) . "' /> <input type='submit' value='Pay' /> <small>(you have " . money_formatter($money) . ' in your pocket)</small></form>';
}
$h->endpage();
