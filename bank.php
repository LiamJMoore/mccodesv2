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
echo '<h3>Bank</h3>';
$bank_cost = 50000;
$bank_maxfee = 3000;
$bank_feepercent = 15;
$action = in_choice('action', ['deposit', 'withdraw', 'buy'], '', $_GET);
// Engine v3: every change here needs the form's own token, and the money moves in one guarded statement
// (engine/Economy.php), so a balance cannot be spent twice by sending the same request several times at once.
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($action !== '' && (!$posted || !verify_csrf_code('bank_' . $action, in_str('verf', 64, '', $_POST))))
{
    $action = '';
    if ($posted)
    {
        echo 'That form had expired. Nothing was changed; please try again.<br />';
    }
}
if ($ir['bankmoney'] > -1)
{
    switch ($action)
    {
    case 'deposit':
        deposit();
        break;

    case 'withdraw':
        withdraw();
        break;

    default:
        index();
        break;
    }

} elseif ($action === 'buy') {
    if (wallet_open($userid, 'bankmoney', $bank_cost)) {
        echo 'Congratulations, you bought a bank account for '
            . money_formatter($bank_cost)
            . "!<br />
<a href='bank.php'>Start using my account</a>";
    } else {
        echo "You do not have enough money to open an account.
<a href='explore.php'>Back to town...</a>";
    }
} else {
    echo 'Open a bank account today, just ' . money_formatter($bank_cost)
        . "!<br />
<form action='bank.php?action=buy' method='post'>" . request_csrf_html('bank_buy') . "
<input type='submit' value='Yes, sign me up!' /></form>";
}

/**
 * @return void
 */
function index(): void
{
    global $ir, $bank_maxfee, $bank_feepercent;
    echo "\n<b>You currently have" . money_formatter($ir['bankmoney'])
            . " in the bank.</b><br />
At the end of each day, your bank balance will go up by 2%.<br />
<table width='75%' cellspacing=1 class='table'> <tr> <td width='50%'><b>Deposit Money</b><br />
It will cost you {$bank_feepercent}% of the money you deposit, rounded up. The maximum fee is "
            . money_formatter($bank_maxfee)
            . ".<form action='bank.php?action=deposit' method='post'>" . request_csrf_html('bank_deposit') . "
Amount: <input type='text' name='deposit' value='{$ir['money']}' /><br />
<input type='submit' value='Deposit' /></form></td> <td>
<b>Withdraw Money</b><br />
There is no fee on withdrawals.<form action='bank.php?action=withdraw' method='post'>" . request_csrf_html('bank_withdraw') . "
Amount: <input type='text' name='withdraw' value='{$ir['bankmoney']}' /><br />
<input type='submit' value='Withdraw' /></form></td> </tr> </table>";
}

/**
 * @return void
 */
function deposit(): void
{
    global $ir, $userid, $bank_maxfee, $bank_feepercent;
    $amount = in_int('deposit', 0, $_POST);
    $fee = (int)min(ceil($amount * $bank_feepercent / 100), $bank_maxfee);
    $gain = $amount - $fee;
    if ($amount < 1 || !wallet_move($userid, 'money', 'bankmoney', $amount, $gain))
    {
        echo 'You do not have enough money to deposit this amount.';
    }
    else
    {
        $ir['bankmoney'] += $gain;
        echo 'You hand over ' . money_formatter($amount)
                . ' to be deposited, <br />
after the fee is taken (' . money_formatter($fee) . ', '
                . money_formatter($gain)
                . ' is added to your account. <br />
<b>You now have ' . money_formatter($ir['bankmoney'])
                . " in the bank.</b><br />
<a href='bank.php'>&gt; Back</a>";
    }
}

/**
 * @return void
 */
function withdraw(): void
{
    global $ir, $userid;
    $gain = in_int('withdraw', 0, $_POST);
    if ($gain < 1 || !wallet_move($userid, 'bankmoney', 'money', $gain))
    {
        echo 'You do not have enough banked money to withdraw this amount.';
    }
    else
    {
        $ir['bankmoney'] -= $gain;
        echo 'You ask to withdraw ' . money_formatter($gain)
                . ', <br />
the banking lady grudgingly hands it over. <br />
<b>You now have ' . money_formatter($ir['bankmoney'])
                . " in the bank.</b><br />
<a href='bank.php'>&gt; Back</a>";
    }
}
$h->endpage();
