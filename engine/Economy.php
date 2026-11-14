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
    if ($amount === 0) {
        return true;
    }
    if ($db->exec("UPDATE `users` SET `{$wallet}` = `{$wallet}` - ? WHERE `userid` = ? AND `{$wallet}` >= ?", [$amount, $userid, $amount]) !== 1) {
        return false;
    }
    ledger_add($userid, $wallet, -$amount);
    return true;
}

/** Add $amount to one balance. False if the player does not exist, or has not opened that account. */
function wallet_give(int $userid, string $wallet, int $amount): bool
{
    global $db;
    wallet_check($wallet, $amount);
    if ($amount === 0) {
        return true;
    }
    if ($db->exec("UPDATE `users` SET `{$wallet}` = `{$wallet}` + ? WHERE `userid` = ? AND `{$wallet}` >= 0", [$amount, $userid]) !== 1) {
        return false;
    }
    ledger_add($userid, $wallet, $amount);
    return true;
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
    if ($db->exec(
        "UPDATE `users` SET `{$from}` = `{$from}` - ?, `{$to}` = `{$to}` + ? WHERE `userid` = ? AND `{$from}` >= ? AND `{$to}` >= 0",
        [$take, $give, $userid, $take]) !== 1) {
        return false;
    }
    ledger_add($userid, $from, -$take);
    ledger_add($userid, $to, $give);
    return true;
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
    if ($db->exec(
        "UPDATE `users` SET `{$pay_from}` = `{$pay_from}` - ?, `{$account}` = 0 WHERE `userid` = ? AND `{$pay_from}` >= ? AND `{$account}` = -1",
        [$fee, $userid, $fee]) !== 1) {
        return false;
    }
    ledger_add($userid, $pay_from, -$fee, 'opened an account');
    return true;
}

/**
 * The bars a player spends to act. Each has a column for its maximum beside it.
 */
const MCC_BARS = ['energy', 'brave', 'will', 'hp'];

/**
 * Spend from a bar (energy to train, brave to commit a crime). True if the player had that much and it was taken;
 * false, with nothing changed, if not. One statement, so it cannot be spent twice by requests arriving together.
 */
function bar_spend(int $userid, string $bar, int $amount): bool
{
    global $db;
    if (!in_array($bar, MCC_BARS, true) || $amount < 0) {
        throw new InvalidArgumentException('Not a bar, or a negative amount.');
    }
    if ($amount === 0) {
        return true;
    }
    return $db->exec("UPDATE `users` SET `{$bar}` = `{$bar}` - ? WHERE `userid` = ? AND `{$bar}` >= ?", [$amount, $userid, $amount]) === 1;
}

/**
 * A gang's vault. Take money and crystals out together: true if the vault held both and they were taken; false,
 * with nothing changed, if not.
 */
function vault_take(int $gang, int $money, int $crystals = 0): bool
{
    global $db;
    if ($money < 0 || $crystals < 0) {
        throw new InvalidArgumentException('A negative amount.');
    }
    if ($money === 0 && $crystals === 0) {
        return true;
    }
    return $db->exec('UPDATE `gangs` SET `gangMONEY` = `gangMONEY` - ?, `gangCRYSTALS` = `gangCRYSTALS` - ? WHERE `gangID` = ? AND `gangMONEY` >= ? AND `gangCRYSTALS` >= ?',
        [$money, $crystals, $gang, $money, $crystals]) === 1;
}

/** Put money and crystals into a gang's vault. False if there is no such gang. */
function vault_give(int $gang, int $money, int $crystals = 0): bool
{
    global $db;
    if ($money < 0 || $crystals < 0) {
        throw new InvalidArgumentException('A negative amount.');
    }
    return $db->exec('UPDATE `gangs` SET `gangMONEY` = `gangMONEY` + ?, `gangCRYSTALS` = `gangCRYSTALS` + ? WHERE `gangID` = ?', [$money, $crystals, $gang]) === 1
        || (int)$db->value('SELECT COUNT(*) FROM `gangs` WHERE `gangID` = ?', [$gang]) === 1;
}

/**
 * The ledger: one line for every change to a player's money or crystals, with the page it happened on. It is
 * written inside the same transaction as the change, so a change that is undone leaves no line.
 *
 * The wallet functions above write to it themselves. A page that changes a balance with its own SQL calls
 * ledger_add() beside that statement. Lines are kept for MCC_LEDGER_DAYS and then cleared out.
 */
const MCC_LEDGER_DAYS = 90;

function ledger_add(int $userid, string $wallet, int $delta, string $note = ''): void
{
    global $db;
    if ($delta === 0) {
        return;
    }
    static $page = null;
    $page ??= substr(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'cli')), 0, 40);
    $db->run('INSERT INTO `v3_ledger` (`userid`, `wallet`, `delta`, `at`, `page`, `note`) VALUES (?, ?, ?, ?, ?, ?)', [$userid, $wallet, $delta, time(), $page, substr($note, 0, 80)]);
}

/** Lines from the ledger, newest first. $filter may hold 'userid', 'wallet', 'min' (smallest size of change), 'page'. */
function ledger_lines(array $filter = [], int $limit = 50, int $offset = 0): array
{
    global $db;
    $where = ['1 = 1'];
    $params = [];
    if (!empty($filter['userid'])) {
        $where[] = '`l`.`userid` = ?';
        $params[] = (int)$filter['userid'];
    }
    if (!empty($filter['wallet']) && in_array($filter['wallet'], MCC_WALLETS, true)) {
        $where[] = '`l`.`wallet` = ?';
        $params[] = $filter['wallet'];
    }
    if (!empty($filter['min'])) {
        $where[] = 'ABS(`l`.`delta`) >= ?';
        $params[] = (int)$filter['min'];
    }
    if (!empty($filter['page'])) {
        $where[] = '`l`.`page` = ?';
        $params[] = (string)$filter['page'];
    }
    return $db->all('SELECT `l`.`id`, `l`.`userid`, `l`.`wallet`, `l`.`delta`, `l`.`at`, `l`.`page`, `l`.`note`, `u`.`username` FROM `v3_ledger` AS `l` LEFT JOIN `users` AS `u` ON `u`.`userid` = `l`.`userid`
                     WHERE ' . implode(' AND ', $where) . ' ORDER BY `l`.`id` DESC LIMIT ' . max(1, min($limit, 200)) . ' OFFSET ' . max(0, $offset), $params);
}

/** What a ledger line is, in words, from the page it happened on. */
function ledger_label(string $page, string $note = ''): string
{
    static $names = ['bank.php' => 'Bank', 'cyberbank.php' => 'Cyber Bank', 'sendcash.php' => 'Sent or received cash', 'sendcrys.php' => 'Sent or received crystals', 'sendbank.php' => 'Bank transfer',
        'sendcyber.php' => 'Cyber Bank transfer', 'itembuy.php' => 'Shop', 'itemsell.php' => 'Sold an item', 'itemmarket.php' => 'Item market', 'cmarket.php' => 'Crystal market', 'docrime.php' => 'Crime',
        'daily.php' => 'Daily reward', 'missions.php' => 'Mission', 'season.php' => 'Season pass', 'bounties.php' => 'Bounty', 'attackwon.php' => 'Fight', 'attackbeat.php' => 'Fight', 'attacktake.php' => 'Fight',
        'stocks.php' => 'Stock market', 'trade.php' => 'Trading post', 'invest.php' => 'Investment bank', 'lottery.php' => 'Lottery', 'pet.php' => 'Pet', 'craft.php' => 'Workshop', 'crystalshop.php' => 'Crystal shop',
        'crystaltemple.php' => 'Crystal temple', 'slotsmachine.php' => 'Slots', 'roulette.php' => 'Roulette', 'lucky.php' => 'Lucky boxes', 'estate.php' => 'Estate agent', 'monorail.php' => 'Travel',
        'jailbail.php' => 'Bail', 'yourgang.php' => 'Gang', 'creategang.php' => 'Founded a gang', 'stripe_webhook.php' => 'Store purchase', 'marriage.php' => 'Marriage', 'auction.php' => 'Auction house', 'loanshark.php' => 'Loan shark', 'vehicles.php' => 'Garage'];
    $name = $names[$page] ?? ucfirst(str_replace(['.php', '_'], ['', ' '], $page));
    return $note !== '' ? "{$name}: {$note}" : $name;
}

