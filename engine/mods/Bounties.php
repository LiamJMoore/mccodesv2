<?php
declare(strict_types=1);

/**
 * Bounties and the hitlist. Put money on another player's head; whoever beats them in a fight and mugs or
 * hospitalises them collects it. (Leaving them outside the hospital is mercy, and mercy is not paid.)
 *
 * The money leaves the placer's pocket when the bounty is placed and is held by the game until it is collected or
 * withdrawn, so a bounty can always be paid. The fee is not refunded: it is the price of using the service and
 * takes money out of the game.
 */
const MCC_BOUNTY_MIN = 1000;          // smallest bounty
const MCC_BOUNTY_MAX = 100000000;     // largest single bounty
const MCC_BOUNTY_FEE_PERCENT = 10;    // charged on top, never refunded
const MCC_BOUNTY_OPEN_LIMIT = 10;     // open bounties one player may have at a time

function bounty_fee(int $amount): int
{
    return (int)ceil($amount * MCC_BOUNTY_FEE_PERCENT / 100);
}

/**
 * Place a bounty. Returns null when placed, or the reason it was not, in words for the player. Nothing is taken
 * unless the bounty is placed.
 */
function bounty_place(int $placer, int $target, int $amount, bool $anonymous = false): ?string
{
    global $db;
    if ($target === $placer) {
        return 'You cannot put a bounty on yourself.';
    }
    if ($amount < MCC_BOUNTY_MIN || $amount > MCC_BOUNTY_MAX) {
        return 'A bounty must be between ' . money_formatter(MCC_BOUNTY_MIN) . ' and ' . money_formatter(MCC_BOUNTY_MAX) . '.';
    }
    if ($db->value('SELECT `userid` FROM `users` WHERE `userid` = ?', [$target]) === null) {
        return 'There is no such player.';
    }
    if ((int)$db->value("SELECT COUNT(*) FROM `v3_bounties` WHERE `placer` = ? AND `status` = 'open'", [$placer]) >= MCC_BOUNTY_OPEN_LIMIT) {
        return 'You already have ' . MCC_BOUNTY_OPEN_LIMIT . ' bounties out. Wait for one to be collected, or withdraw one.';
    }
    $placed = $db->attempt(function () use ($db, $placer, $target, $amount, $anonymous) {
        if (!wallet_take($placer, 'money', $amount + bounty_fee($amount))) {
            return false;
        }
        $db->insert('v3_bounties', ['target' => $target, 'placer' => $placer, 'amount' => $amount, 'anonymous' => $anonymous ? 1 : 0, 'placed' => time(), 'status' => 'open']);
        return true;
    });
    return $placed ? null : 'You do not have ' . money_formatter($amount + bounty_fee($amount)) . ' (the bounty plus the ' . MCC_BOUNTY_FEE_PERCENT . '% fee) in your pocket.';
}

/** Take back a bounty nobody has collected. The bounty is refunded; the fee is not. True if it was withdrawn. */
function bounty_withdraw(int $placer, int $bounty_id): bool
{
    global $db;
    return $db->attempt(function () use ($db, $placer, $bounty_id) {
        // open -> withdrawn can happen once, and not after it has been collected
        if ($db->exec("UPDATE `v3_bounties` SET `status` = 'withdrawn', `closed` = ? WHERE `id` = ? AND `placer` = ? AND `status` = 'open'", [time(), $bounty_id, $placer]) !== 1) {
            return false;
        }
        return wallet_give($placer, 'money', (int)$db->value('SELECT `amount` FROM `v3_bounties` WHERE `id` = ?', [$bounty_id]));
    });
}

/** The total on a player's head right now. */
function bounty_on(int $target): int
{
    global $db;
    return (int)$db->value("SELECT COALESCE(SUM(`amount`), 0) FROM `v3_bounties` WHERE `target` = ? AND `status` = 'open'", [$target]);
}

/** The hitlist: players with a price on their head, biggest first. */
function bounty_hitlist(int $limit = 50): array
{
    global $db;
    return $db->all(
        "SELECT `b`.`target`, SUM(`b`.`amount`) AS `total`, COUNT(*) AS `bounties`, `u`.`username`, `u`.`level`, `u`.`hospital`, `u`.`jail`, `u`.`location`, `u`.`hp`
         FROM `v3_bounties` AS `b` INNER JOIN `users` AS `u` ON `u`.`userid` = `b`.`target`
         WHERE `b`.`status` = 'open' GROUP BY `b`.`target` ORDER BY `total` DESC, `b`.`target` LIMIT " . max(1, min($limit, 200)));
}

/**
 * Pay out every open bounty on $target to $collector, except any the collector placed themselves (you cannot be
 * paid for your own contract). Returns ['count' => , 'total' => ]. Each bounty goes from open to collected in a
 * locked step, so it is paid once.
 */
function bounty_collect(int $collector, int $target, string $collector_name = ''): array
{
    global $db;
    $got = ['count' => 0, 'total' => 0];
    $db->transaction(function () use ($db, $collector, $target, $collector_name, &$got) {
        $open = $db->all("SELECT `id`, `amount`, `placer` FROM `v3_bounties` WHERE `target` = ? AND `status` = 'open' AND `placer` <> ? FOR UPDATE", [$target, $collector]);
        if (!$open) {
            return;
        }
        $target_name = (string)$db->value('SELECT `username` FROM `users` WHERE `userid` = ?', [$target], 'someone');
        foreach ($open as $b) {
            $db->exec("UPDATE `v3_bounties` SET `status` = 'collected', `collector` = ?, `closed` = ? WHERE `id` = ?", [$collector, time(), (int)$b['id']]);
            $got['count']++;
            $got['total'] += (int)$b['amount'];
            event_add((int)$b['placer'], "Your bounty of " . money_formatter((int)$b['amount']) . " on <a href='viewuser.php?u={$target}'>" . e($target_name)
                . "</a> was collected by <a href='viewuser.php?u={$collector}'>" . e($collector_name !== '' ? $collector_name : 'a hunter') . '</a>.');
        }
        wallet_give($collector, 'money', $got['total']);
    });
    if ($got['count'] > 0 && function_exists('progress_add')) {
        progress_add($collector, 'bounties', $got['count']);
    }
    return $got;
}

// a fight was won: if the loser was mugged or hospitalised, the winner collects what is on their head
fight_listen(function (array $winner, array $loser, string $how, array $result): ?string {
    if ($how === 'leave') {
        return bounty_on((int)$loser['userid']) > 0 ? 'There was a bounty on their head, but it is only paid for a mugging or a hospitalisation.' : null;
    }
    $got = bounty_collect((int)$winner['userid'], (int)$loser['userid'], (string)$winner['username']);
    return $got['count'] > 0
        ? '<b>Bounty collected!</b> There was a price on their head: you are paid ' . money_formatter($got['total']) . '.'
        : null;
});
