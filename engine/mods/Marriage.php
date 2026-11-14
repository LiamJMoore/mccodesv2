<?php
declare(strict_types=1);

/**
 * Marriage. One player proposes (and buys the ring); the other accepts or declines. A married couple get a joint
 * account either can pay into or draw on, their names on each other's profile, and can live together in the better
 * of their two houses.
 *
 * Living together: one spouse moves in with the other and takes on that house's will bar. The house is still the
 * owner's: a guest cannot buy or sell at the estate agent until they move back to their own place, so a house is
 * never sold by someone who did not buy it. If the owner changes house, the guest's changes with it.
 */
const MCC_RING_PRICE = 10000;             // paid by the proposer; returned if the proposal is declined or withdrawn
const MCC_DIVORCE_PRICE = 5000;           // paid by whoever files

function marriage_spouse(int $userid): ?array
{
    global $db;
    return $db->row('SELECT `s`.`spouse`, `s`.`since`, `s`.`own_will`, `u`.`username`, `u`.`maxwill` FROM `v3_spouses` AS `s` INNER JOIN `users` AS `u` ON `u`.`userid` = `s`.`spouse` WHERE `s`.`userid` = ?', [$userid]);
}

/** Is this player living in their spouse's house? */
function marriage_is_guest(int $userid): bool
{
    global $db;
    return $db->value('SELECT `own_will` FROM `v3_spouses` WHERE `userid` = ? AND `own_will` IS NOT NULL', [$userid]) !== null;
}

function marriage_propose(int $from, int $to): ?string
{
    global $db;
    if ($from === $to) {
        return 'You cannot marry yourself.';
    }
    if ($db->value('SELECT `userid` FROM `users` WHERE `userid` = ? AND `user_level` > 0', [$to]) === null) {
        return 'There is no such player.';
    }
    if (marriage_spouse($from) !== null || marriage_spouse($to) !== null) {
        return 'One of you is already married.';
    }
    $why = 'You already have a proposal waiting for an answer.';
    $ok = $db->attempt(function () use ($db, $from, $to, &$why) {
        if ($db->exec('INSERT IGNORE INTO `v3_proposals` (`proposer`, `target`, `at`) VALUES (?, ?, ?)', [$from, $to, time()]) !== 1) {
            return false;
        }
        if (!wallet_take($from, 'money', MCC_RING_PRICE)) {
            $why = 'A ring costs ' . money_formatter(MCC_RING_PRICE) . '.';
            return false;
        }
        return true;
    });
    if ($ok) {
        event_add($to, "<a href='viewuser.php?u={$from}'>Someone</a> has proposed to you. <a href='marriage.php'>Give your answer</a>");
    }
    return $ok ? null : $why;
}

/** Take a proposal back (the proposer) or turn it down (the target). The ring money goes back to the proposer. */
function marriage_unpropose(int $userid, int $proposer): bool
{
    global $db;
    return $db->attempt(function () use ($db, $userid, $proposer) {
        return $db->exec('DELETE FROM `v3_proposals` WHERE `proposer` = ? AND (`proposer` = ? OR `target` = ?)', [$proposer, $userid, $userid]) === 1
            && wallet_give($proposer, 'money', MCC_RING_PRICE);
    });
}

function marriage_accept(int $target, int $proposer): ?string
{
    global $db;
    $why = 'That proposal is no longer open.';
    $ok = $db->attempt(function () use ($db, $target, $proposer, &$why) {
        // the proposal can be used once
        if ($db->exec('DELETE FROM `v3_proposals` WHERE `proposer` = ? AND `target` = ?', [$proposer, $target]) !== 1) {
            return false;
        }
        // each player's row is their one marriage: the primary key refuses a second
        $now = time();
        if ($db->exec('INSERT IGNORE INTO `v3_spouses` (`userid`, `spouse`, `since`) VALUES (?, ?, ?)', [$target, $proposer, $now]) !== 1
            || $db->exec('INSERT IGNORE INTO `v3_spouses` (`userid`, `spouse`, `since`) VALUES (?, ?, ?)', [$proposer, $target, $now]) !== 1) {
            $why = 'One of you is already married.';
            return false;
        }
        $db->run('INSERT IGNORE INTO `v3_joint` (`a`, `b`, `balance`) VALUES (?, ?, 0)', [min($target, $proposer), max($target, $proposer)]);
        return true;
    });
    if ($ok) {
        // any other proposals either of them had out, or had waiting, are closed and their rings refunded
        foreach ($db->all('SELECT `proposer` FROM `v3_proposals` WHERE `proposer` IN (?, ?) OR `target` IN (?, ?)', [$target, $proposer, $target, $proposer]) as $p) {
            $db->attempt(fn () => $db->exec('DELETE FROM `v3_proposals` WHERE `proposer` = ?', [(int)$p['proposer']]) === 1 && wallet_give((int)$p['proposer'], 'money', MCC_RING_PRICE));
        }
        event_add($proposer, "<a href='viewuser.php?u={$target}'>Your proposal</a> was accepted. You are married! <a href='marriage.php'>Your marriage</a>");
    }
    return $ok ? null : $why;
}

/** The couple's joint balance. */
function marriage_joint(int $userid, int $spouse): int
{
    global $db;
    return (int)$db->value('SELECT `balance` FROM `v3_joint` WHERE `a` = ? AND `b` = ?', [min($userid, $spouse), max($userid, $spouse)], 0);
}

function marriage_deposit(int $userid, int $amount): bool
{
    global $db;
    $sp = marriage_spouse($userid);
    if ($sp === null || $amount < 1) {
        return false;
    }
    return $db->attempt(fn () => wallet_take($userid, 'money', $amount)
        && $db->exec('UPDATE `v3_joint` SET `balance` = `balance` + ? WHERE `a` = ? AND `b` = ?', [$amount, min($userid, (int)$sp['spouse']), max($userid, (int)$sp['spouse'])]) === 1);
}

function marriage_withdraw(int $userid, int $amount): bool
{
    global $db;
    $sp = marriage_spouse($userid);
    if ($sp === null || $amount < 1) {
        return false;
    }
    return $db->attempt(fn () => $db->exec('UPDATE `v3_joint` SET `balance` = `balance` - ? WHERE `a` = ? AND `b` = ? AND `balance` >= ?', [$amount, min($userid, (int)$sp['spouse']), max($userid, (int)$sp['spouse']), $amount]) === 1
        && wallet_give($userid, 'money', $amount));
}

/** Move in with your spouse (if their house is better than yours and they are not living in yours). Null when done, or why not. */
function marriage_move_in(int $userid): ?string
{
    global $db;
    $why = 'You are not married.';
    $ok = $db->attempt(function () use ($db, $userid, &$why) {
        $me = $db->row('SELECT `s`.`spouse`, `s`.`own_will`, `u`.`maxwill` FROM `v3_spouses` AS `s` INNER JOIN `users` AS `u` ON `u`.`userid` = `s`.`userid` WHERE `s`.`userid` = ? FOR UPDATE', [$userid]);
        if ($me === null) {
            return false;
        }
        $them = $db->row('SELECT `s`.`own_will`, `u`.`maxwill` FROM `v3_spouses` AS `s` INNER JOIN `users` AS `u` ON `u`.`userid` = `s`.`userid` WHERE `s`.`userid` = ? FOR UPDATE', [(int)$me['spouse']]);
        if ($me['own_will'] !== null) {
            $why = 'You already live with your spouse.';
            return false;
        }
        if ($them === null || $them['own_will'] !== null) {
            $why = 'Your spouse is living at your place.';
            return false;
        }
        if ((int)$them['maxwill'] <= (int)$me['maxwill']) {
            $why = 'Your own house is at least as good as theirs. Ask them to move in with you.';
            return false;
        }
        $db->exec('UPDATE `v3_spouses` SET `own_will` = ? WHERE `userid` = ?', [(int)$me['maxwill'], $userid]);
        $db->exec('UPDATE `users` SET `maxwill` = ?, `will` = LEAST(`will`, ?) WHERE `userid` = ?', [(int)$them['maxwill'], (int)$them['maxwill'], $userid]);
        return true;
    });
    return $ok ? null : $why;
}

/** Move back to your own house. True if the player was a guest. */
function marriage_move_out(int $userid): bool
{
    global $db;
    return $db->attempt(function () use ($db, $userid) {
        $own = $db->value('SELECT `own_will` FROM `v3_spouses` WHERE `userid` = ? AND `own_will` IS NOT NULL FOR UPDATE', [$userid]);
        if ($own === null) {
            return false;
        }
        $db->exec('UPDATE `v3_spouses` SET `own_will` = NULL WHERE `userid` = ?', [$userid]);
        $db->exec('UPDATE `users` SET `maxwill` = ?, `will` = LEAST(`will`, ?) WHERE `userid` = ?', [(int)$own, (int)$own, $userid]);
        return true;
    });
}

/** The owner of a house has bought or sold: a spouse living there moves with them, or back home if home is now better. */
function marriage_house_sync(int $owner): void
{
    global $db;
    $guest = $db->row('SELECT `g`.`userid`, `g`.`own_will` FROM `v3_spouses` AS `g` WHERE `g`.`spouse` = ? AND `g`.`own_will` IS NOT NULL', [$owner]);
    if ($guest === null) {
        return;
    }
    $will = (int)$db->value('SELECT `maxwill` FROM `users` WHERE `userid` = ?', [$owner]);
    if ($will <= (int)$guest['own_will']) {
        marriage_move_out((int)$guest['userid']);
    } else {
        $db->exec('UPDATE `users` SET `maxwill` = ?, `will` = LEAST(`will`, ?) WHERE `userid` = ?', [$will, $will, (int)$guest['userid']]);
    }
}

/** End the marriage. Whoever files pays; the joint account is split evenly. Null when done, or why not. */
function marriage_divorce(int $userid): ?string
{
    global $db;
    $sp = marriage_spouse($userid);
    if ($sp === null) {
        return 'You are not married.';
    }
    $spouse = (int)$sp['spouse'];
    $why = 'You are not married.';
    $ok = $db->attempt(function () use ($db, $userid, $spouse, &$why) {
        // anyone living with their spouse goes home first; if the filing fails below, this is undone with it
        marriage_move_out($userid);
        marriage_move_out($spouse);
        if ($db->exec('DELETE FROM `v3_spouses` WHERE `userid` = ? AND `spouse` = ?', [$userid, $spouse]) !== 1) {
            return false;
        }
        $db->exec('DELETE FROM `v3_spouses` WHERE `userid` = ? AND `spouse` = ?', [$spouse, $userid]);
        if (!wallet_take($userid, 'money', MCC_DIVORCE_PRICE)) {
            $why = 'Filing for divorce costs ' . money_formatter(MCC_DIVORCE_PRICE) . '.';
            return false;
        }
        $a = min($userid, $spouse);
        $b = max($userid, $spouse);
        $joint = (int)$db->value('SELECT `balance` FROM `v3_joint` WHERE `a` = ? AND `b` = ? FOR UPDATE', [$a, $b], 0);
        $db->exec('DELETE FROM `v3_joint` WHERE `a` = ? AND `b` = ?', [$a, $b]);
        wallet_give($spouse, 'money', intdiv($joint, 2));
        wallet_give($userid, 'money', $joint - intdiv($joint, 2));
        return true;
    });
    if ($ok) {
        event_add($spouse, "<a href='viewuser.php?u={$userid}'>Your spouse</a> has divorced you. Your half of the joint account is in your pocket.");
    }
    return $ok ? null : $why;
}
