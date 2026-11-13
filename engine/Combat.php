<?php
declare(strict_types=1);

/**
 * How a won fight ends. v2 had three pages (mug, hospitalise, leave) each carrying its own copy of the same code,
 * and each read the loser's row, decided, then wrote: so two requests arriving together both passed the "already
 * finished?" check, and a mugging could take the loser's money twice. Here the loser's row is locked while the
 * outcome is worked out and written, so a fight is finished once.
 *
 * Anything that wants to react to a fight being won (bounties, for one) registers with fight_listen().
 */
const MCC_FIGHT_ENDINGS = ['mug', 'hospitalise', 'leave'];

/** Things to call when a fight is won: fn(array $winner, array $loser, string $how, array $result): ?string (a line to show the winner). */
$GLOBALS['mcc_fight_listeners'] ??= [];

function fight_listen(callable $listener): void
{
    $GLOBALS['mcc_fight_listeners'][] = $listener;
}

/**
 * Finish a fight $me (the winner's player row, as in $ir) has won against player $loser_id.
 *
 * Returns null if there is nothing to finish (no such player, or they have already been dealt with). Otherwise:
 * ['loser' => their row, 'stole' => money taken, 'exp' => experience gained, 'hospital' => minutes,
 *  'respect' => gang respect gained, 'bot_money' => challenge-bot prize, 'notes' => lines from listeners]
 */
function fight_win(array $me, int $loser_id, string $how, string $log = ''): ?array
{
    global $db;
    if (!in_array($how, MCC_FIGHT_ENDINGS, true)) {
        throw new InvalidArgumentException('Not a way to end a fight.');
    }
    $userid = (int)$me['userid'];
    $result = null;
    $db->transaction(function () use ($db, $me, $userid, $loser_id, $how, $log, &$result) {
        // lock the loser: anything else finishing this fight waits here, then sees hp = 1 and stops
        $r = $db->row('SELECT * FROM `users` WHERE `userid` = ? FOR UPDATE', [$loser_id]);
        if ($r === null || (int)$r['hp'] === 1 || $loser_id === $userid) {
            return;
        }
        $stole = 0;
        $exp = 0;
        $link = "<a href='viewuser.php?u={$userid}'>{$me['username']}</a>";
        if ($how === 'mug') {
            $stole = (int)round((int)$r['money'] / (rand(200, 5000) / 10));
            $hospital = rand(20, 40) + (int)floor($me['level'] / 8);
            $reason = "Mugged by {$link}";
            $event = "{$link} mugged you and stole " . money_formatter($stole) . '.';
            $logged = $stole;
        } elseif ($how === 'hospitalise') {
            $hospital = rand(50, 150) + (int)floor($me['level'] / 2);
            $reason = "Hospitalized by {$link}";
            $event = "{$link} beat you up.";
            $logged = -1;
        } else {
            $qe = (int)$r['level'] ** 3;
            $exp = rand(intdiv($qe, 2), $qe);
            $hospital = rand(10, 20);
            $reason = "Left by {$link}";
            $event = "{$link} attacked you and left you lying outside the hospital.";
            $logged = -2;
        }
        $db->exec('UPDATE `users` SET `hp` = 1, `money` = `money` - ?, `hospital` = ?, `hospreason` = ? WHERE `userid` = ?', [$stole, $hospital, $reason, $loser_id]);
        $db->exec('UPDATE `users` SET `exp` = `exp` + ?, `money` = `money` + ?, `attacking` = 0 WHERE `userid` = ?', [$exp, $stole, $userid]);
        event_add($loser_id, $event);
        $db->insert('attacklogs', ['attacker' => $userid, 'attacked' => $loser_id, 'result' => 'won', 'time' => time(), 'stole' => $logged, 'attacklog' => $log]);

        // gangs at war: respect moves from the loser's gang to the winner's, and a gang with none left is disbanded
        $respect = 0;
        if ((int)$me['gang'] > 0 && (int)$r['gang'] > 0) {
            $ga = $db->row('SELECT `gangRESPECT`, `gangID` FROM `gangs` WHERE `gangID` = ? FOR UPDATE', [(int)$r['gang']]);
            if ($ga !== null) {
                $at_war = (int)$db->value(
                    'SELECT COUNT(*) FROM `gangwars` WHERE (`warDECLARER` = ? AND `warDECLARED` = ?) OR (`warDECLARED` = ? AND `warDECLARER` = ?)',
                    [(int)$me['gang'], (int)$r['gang'], (int)$me['gang'], (int)$r['gang']]);
                if ($at_war > 0) {
                    $respect = ['mug' => 2, 'hospitalise' => 3, 'leave' => 1][$how];
                    $db->exec('UPDATE `gangs` SET `gangRESPECT` = `gangRESPECT` - ? WHERE `gangID` = ?', [$respect, (int)$r['gang']]);
                    $db->exec('UPDATE `gangs` SET `gangRESPECT` = `gangRESPECT` + ? WHERE `gangID` = ?', [$respect, (int)$me['gang']]);
                    $ga['gangRESPECT'] -= $respect;
                }
                if ($ga['gangRESPECT'] <= 0) {
                    $db->exec('UPDATE `users` SET `gang` = 0 WHERE `gang` = ?', [(int)$r['gang']]);
                    $db->exec('DELETE FROM `gangs` WHERE `gangID` = ?', [(int)$r['gang']]);
                    $db->exec('DELETE FROM `gangwars` WHERE `warDECLARER` = ? OR `warDECLARED` = ?', [(int)$ga['gangID'], (int)$ga['gangID']]);
                }
            }
        }

        // challenge bots pay a prize the first time a player beats them. (v2 had this test the wrong way round:
        // it paid only players already recorded as having beaten the bot, which nobody could be, so it never paid.)
        $bot_money = 0;
        if ((int)$r['user_level'] === 0) {
            $prize = $db->value('SELECT `cb_money` FROM `challengebots` WHERE `cb_npcid` = ?', [$loser_id]);
            if ($prize !== null && (int)$db->value('SELECT COUNT(*) FROM `challengesbeaten` WHERE `userid` = ? AND `npcid` = ?', [$userid, $loser_id]) === 0) {
                $bot_money = (int)$prize;
                $db->exec('UPDATE `users` SET `money` = `money` + ? WHERE `userid` = ?', [$bot_money, $userid]);
                $db->insert('challengesbeaten', ['userid' => $userid, 'npcid' => $loser_id]);
            }
        }
        $result = ['loser' => $r, 'stole' => $stole, 'exp' => $exp, 'hospital' => $hospital, 'respect' => $respect, 'bot_money' => $bot_money, 'notes' => []];
    });
    if ($result === null) {
        return null;
    }
    if (function_exists('progress_add')) {
        progress_add($userid, 'fights_won');
    }
    foreach ($GLOBALS['mcc_fight_listeners'] as $listener) {
        $note = $listener($me, $result['loser'], $how, $result);
        if (is_string($note) && $note !== '') {
            $result['notes'][] = $note;
        }
    }
    return $result;
}

/** The lines every "you won" page ends with: gang respect, a challenge-bot prize, and anything listeners added. */
function fight_win_footer(array $result): string
{
    $out = '';
    if ($result['respect'] > 0) {
        $out .= "<br />You earnt {$result['respect']} respect for your gang!";
    }
    if ($result['bot_money'] > 0) {
        $out .= '<br /> You gained ' . money_formatter($result['bot_money']) . " for beating the challenge bot {$result['loser']['username']}";
    }
    foreach ($result['notes'] as $note) {
        $out .= '<br />' . $note;
    }
    return $out;
}
