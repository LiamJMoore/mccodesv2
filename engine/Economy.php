<?php
declare(strict_types=1);

/**
 * Moving money safely.
 *
 * v2 pages check "has the player enough?" against the figure read when the page started, then run an UPDATE that
 * takes the amount whatever the balance is by then. Two requests arriving together both pass the check and both
 * take the money: a player with $1,000 in the bank can withdraw it several times over from several logins at once.
 *
 * Here the check and the change are one statement, so the database decides, one request at a time:
 *
 *     UPDATE users SET bankmoney = bankmoney - 1000, money = money + 1000 WHERE userid = 2 AND bankmoney >= 1000
 *
 * If the balance is no longer enough, no row changes and the function returns false. A balance can never go below
 * zero through these functions.
 */

/** The balances a player holds, and the column each lives in. bankmoney and cybermoney are -1 until an account is opened. */
const MCC_WALLETS = ['money', 'crystals', 'bankmoney', 'cybermoney'];

function wallet_check(string $wallet, int $amount): void
{
    if (!in_array($wallet, MCC_WALLETS, true)) {
        throw new InvalidArgumentException("Not a wallet: {$wallet}");
    }
    if ($amount < 0) {
        throw new InvalidArgumentException('An amount cannot be negative.');
    }
}

/** Take $amount from one balance. False, and nothing changes, if the player does not have it. */
function wallet_take(int $userid, string $wallet, int $amount): bool
{
    global $db;
    wallet_check($wallet, $amount);
    return $db->exec("UPDATE `users` SET `{$wallet}` = `{$wallet}` - ? WHERE `userid` = ? AND `{$wallet}` >= ?", [$amount, $userid, $amount]) === 1 || $amount === 0;
}

/** Add $amount to one balance. False if the player does not exist, or has not opened that account. */
function wallet_give(int $userid, string $wallet, int $amount): bool
{
    global $db;
    wallet_check($wallet, $amount);
    return $db->exec("UPDATE `users` SET `{$wallet}` = `{$wallet}` + ? WHERE `userid` = ? AND `{$wallet}` >= 0", [$amount, $userid]) === 1 || $amount === 0;
}

/**
 * Move money between two of one player's own balances: $take leaves $from and $give arrives in $to
 * ($give is $take less any fee). One statement: false, and nothing changes, if $from is short or $to is not open.
 */
function wallet_move(int $userid, string $from, string $to, int $take, ?int $give = null): bool
{
    global $db;
    $give ??= $take;
    wallet_check($from, $take);
    wallet_check($to, $give);
    if ($from === $to) {
        throw new InvalidArgumentException('The two balances must differ.');
    }
    return $db->exec(
        "UPDATE `users` SET `{$from}` = `{$from}` - ?, `{$to}` = `{$to}` + ? WHERE `userid` = ? AND `{$from}` >= ? AND `{$to}` >= 0",
        [$take, $give, $userid, $take]) === 1;
}

/**
 * Send money from one player to another: it leaves the sender and reaches the receiver together, or not at all.
 * $to_wallet lets cash be sent into the receiver's bank. False if the sender is short or the receiver cannot take it.
 */
function wallet_send(int $from_user, int $to_user, string $wallet, int $amount, ?string $to_wallet = null): bool
{
    global $db;
    $to_wallet ??= $wallet;
    wallet_check($wallet, $amount);
    wallet_check($to_wallet, $amount);
    if ($from_user === $to_user || $amount === 0) {
        return false;
    }
    try {
        return (bool)$db->transaction(function () use ($from_user, $to_user, $wallet, $to_wallet, $amount) {
            if (!wallet_take($from_user, $wallet, $amount) || !wallet_give($to_user, $to_wallet, $amount)) {
                throw new DomainException('not moved');      // undoes the take if the give failed
            }
            return true;
        });
    } catch (DomainException) {
        return false;
    }
}

/** Open an account that costs money: the fee is taken and the account opened in one statement. */
function wallet_open(int $userid, string $account, int $fee, string $pay_from = 'money'): bool
{
    global $db;
    wallet_check($account, 0);
    wallet_check($pay_from, $fee);
    return $db->exec(
        "UPDATE `users` SET `{$pay_from}` = `{$pay_from}` - ?, `{$account}` = 0 WHERE `userid` = ? AND `{$pay_from}` >= ? AND `{$account}` = -1",
        [$fee, $userid, $fee]) === 1;
}
