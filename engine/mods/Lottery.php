<?php
declare(strict_types=1);

/**
 * The daily lottery. Tickets cost game money. At midnight (server time) one ticket is drawn and its owner wins the
 * pot: most of what was spent on tickets that day, plus a little from the house. The rest leaves the game.
 *
 * It is played with game money only, the odds are shown before you buy, and there is a cap on tickets a day, so
 * nobody can buy the draw.
 */
const MCC_LOTTO_TICKET = 1000;             // game money per ticket
const MCC_LOTTO_MAX_A_DAY = 25;            // tickets one player may buy for one draw
const MCC_LOTTO_POT_PERCENT = 80;          // share of ticket money that goes into the pot
const MCC_LOTTO_HOUSE_ADDS = 10000;        // added to every pot, so an early ticket is still worth buying

/** Today's draw so far: ['day', 'tickets' => all, 'players', 'pot', 'mine' => this player's tickets]. */
function lotto_today(int $userid, ?string $day = null): array
{
    global $db;
    $day ??= date('Y-m-d');
    $t = $db->row('SELECT COALESCE(SUM(`tickets`), 0) AS `tickets`, COUNT(*) AS `players` FROM `v3_lotto_tickets` WHERE `day` = ? AND `tickets` > 0', [$day]);
    return ['day' => $day, 'tickets' => (int)$t['tickets'], 'players' => (int)$t['players'], 'pot' => lotto_pot((int)$t['tickets']),
            'mine' => (int)$db->value('SELECT `tickets` FROM `v3_lotto_tickets` WHERE `day` = ? AND `userid` = ?', [$day, $userid], 0)];
}

function lotto_pot(int $tickets): int
{
    return $tickets > 0 ? intdiv($tickets * MCC_LOTTO_TICKET * MCC_LOTTO_POT_PERCENT, 100) + MCC_LOTTO_HOUSE_ADDS : 0;
}

/** Buy tickets for today's draw. Null when bought, or why not. */
function lotto_buy(int $userid, int $tickets, ?string $day = null): ?string
{
    global $db;
    $day ??= date('Y-m-d');
    if ($tickets < 1) {
        return 'Say how many tickets.';
    }
    $why = 'You can buy ' . MCC_LOTTO_MAX_A_DAY . ' tickets for each draw.';
    $ok = $db->attempt(function () use ($db, $userid, $tickets, $day, &$why) {
        $db->run('INSERT IGNORE INTO `v3_lotto_tickets` (`day`, `userid`, `tickets`) VALUES (?, ?, 0)', [$day, $userid]);
        // the cap is in the statement, so it holds whatever arrives together
        if ($db->exec('UPDATE `v3_lotto_tickets` SET `tickets` = `tickets` + ? WHERE `day` = ? AND `userid` = ? AND `tickets` + ? <= ?', [$tickets, $day, $userid, $tickets, MCC_LOTTO_MAX_A_DAY]) !== 1) {
            return false;
        }
        if (!wallet_take($userid, 'money', $tickets * MCC_LOTTO_TICKET)) {
            $why = 'You need ' . money_formatter($tickets * MCC_LOTTO_TICKET) . ' in your pocket.';
            return false;
        }
        return true;
    });
    return $ok ? null : $why;
}

/**
 * Draw every day that has ended and not been drawn. One request claims the job. Returns the number of draws made.
 * $pick is for tests: given the number of tickets, it returns the winning ticket (1 to that number).
 */
function lotto_settle(?string $today = null, ?callable $pick = null): int
{
    global $db, $set;
    $today ??= date('Y-m-d');
    $last = (string)$db->value("SELECT `conf_value` FROM `settings` WHERE `conf_name` = 'engine_lotto_day'", [], '');
    $set['engine_lotto_day'] = $today;
    if ($last === $today || $db->exec("UPDATE `settings` SET `conf_value` = ? WHERE `conf_name` = 'engine_lotto_day' AND `conf_value` = ?", [$today, $last]) !== 1) {
        return 0;
    }
    $drawn = 0;
    foreach ($db->all('SELECT `t`.`day` FROM `v3_lotto_tickets` AS `t` LEFT JOIN `v3_lotto_draws` AS `d` ON `d`.`day` = `t`.`day` WHERE `t`.`day` < ? AND `d`.`day` IS NULL GROUP BY `t`.`day` ORDER BY `t`.`day`', [$today]) as $d) {
        $day = (string)$d['day'];
        $db->transaction(function () use ($db, $day, $pick, &$drawn) {
            $entries = $db->all('SELECT `userid`, `tickets` FROM `v3_lotto_tickets` WHERE `day` = ? AND `tickets` > 0 ORDER BY `userid` FOR UPDATE', [$day]);
            $total = array_sum(array_map('intval', array_column($entries, 'tickets')));
            if ($total < 1) {
                return;
            }
            $ticket = $pick !== null ? (int)$pick($total) : random_int(1, $total);
            $winner = 0;
            foreach ($entries as $e) {
                $ticket -= (int)$e['tickets'];
                if ($ticket <= 0) {
                    $winner = (int)$e['userid'];
                    break;
                }
            }
            $pot = lotto_pot($total);
            // the draw's own row is its receipt: a day is drawn, and paid, once
            if ($db->exec('INSERT IGNORE INTO `v3_lotto_draws` (`day`, `tickets`, `players`, `pot`, `winner`) VALUES (?, ?, ?, ?, ?)', [$day, $total, count($entries), $pot, $winner]) !== 1) {
                return;
            }
            wallet_give($winner, 'money', $pot);
            event_add($winner, '<b>You won the lottery!</b> The draw for ' . date('j F', strtotime($day)) . ' paid you ' . money_formatter($pot) . '.');
            $drawn++;
        });
    }
    $db->exec('DELETE FROM `v3_lotto_tickets` WHERE `day` < ?', [date('Y-m-d', strtotime($today . ' -30 days'))]);
    return $drawn;
}
