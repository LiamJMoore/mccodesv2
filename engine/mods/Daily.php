<?php
declare(strict_types=1);

/**
 * The daily reward. Claim once a day; claim on consecutive days and the reward grows.
 *
 * Day 1 to 6 of a week pay cash that rises each day. Every 7th day in a row pays cash and crystals. Every 30th day
 * in a row pays a large bonus on top. Miss a day and the streak starts again at day 1. "A day" is the server's date.
 */
const MCC_DAILY_CASH = [1 => 500, 2 => 750, 3 => 1000, 4 => 1500, 5 => 2000, 6 => 3000, 7 => 5000];
const MCC_DAILY_WEEK_CRYSTALS = 5;       // on every 7th day in a row
const MCC_DAILY_MONTH_CASH = 50000;      // extra, on every 30th day in a row
const MCC_DAILY_MONTH_CRYSTALS = 25;

/** What day $streak of a streak pays: ['money' => , 'crystals' => ]. Scales with the player's level so it stays worth having. */
function daily_reward(int $streak, int $level = 1): array
{
    $pos = (($streak - 1) % 7) + 1;
    $money = MCC_DAILY_CASH[$pos] * max(1, min($level, 50));
    $crystals = $pos === 7 ? MCC_DAILY_WEEK_CRYSTALS : 0;
    if ($streak % 30 === 0) {
        $money += MCC_DAILY_MONTH_CASH;
        $crystals += MCC_DAILY_MONTH_CRYSTALS;
    }
    return ['money' => $money, 'crystals' => $crystals];
}

/**
 * Where the player stands today:
 * ['claimed' => claimed today?, 'streak' => their streak as it counts today, 'next' => the day number a claim now
 *  would be, 'best' => longest ever, 'claims' => total, 'reward' => what claiming now pays]
 */
function daily_status(int $userid, int $level = 1, ?string $today = null): array
{
    global $db;
    $today ??= date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
    $r = $db->row('SELECT `last_claim`, `streak`, `best`, `claims` FROM `v3_daily` WHERE `userid` = ?', [$userid]);
    $claimed = $r !== null && $r['last_claim'] === $today;
    $alive = $r !== null && ($r['last_claim'] === $today || $r['last_claim'] === $yesterday);
    $streak = $alive ? (int)$r['streak'] : 0;
    $next = $claimed ? $streak : $streak + 1;
    return ['claimed' => $claimed, 'streak' => $streak, 'next' => $next, 'best' => (int)($r['best'] ?? 0), 'claims' => (int)($r['claims'] ?? 0),
            'reward' => daily_reward($claimed ? $streak + 1 : $next, $level)];
}

/**
 * Claim today's reward. Returns what was paid (['day' => , 'money' => , 'crystals' => ]) or null if today's was
 * already claimed. The claim is recorded in one guarded statement, so it cannot be taken twice however many
 * requests arrive together.
 */
function daily_claim(int $userid, int $level = 1, ?string $today = null): ?array
{
    global $db;
    $today ??= date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
    $paid = null;
    $db->attempt(function () use ($db, $userid, $level, $today, $yesterday, &$paid) {
        // a first-ever claim creates the row; the primary key lets only one request do that
        $db->run('INSERT IGNORE INTO `v3_daily` (`userid`, `last_claim`, `streak`, `best`, `claims`) VALUES (?, ?, 0, 0, 0)', [$userid, '2000-01-01']);
        $changed = $db->exec(
            'UPDATE `v3_daily`
             SET `streak` = IF(`last_claim` = ?, `streak` + 1, 1), `best` = GREATEST(`best`, `streak`),   /* MySQL sets left to right: `streak` here is already the new value */
                 `claims` = `claims` + 1, `last_claim` = ?
             WHERE `userid` = ? AND `last_claim` < ?', [$yesterday, $today, $userid, $today]);
        if ($changed !== 1) {
            return false;
        }
        $day = (int)$db->value('SELECT `streak` FROM `v3_daily` WHERE `userid` = ?', [$userid]);
        $reward = daily_reward($day, $level);
        wallet_give($userid, 'money', $reward['money']);
        if ($reward['crystals'] > 0) {
            wallet_give($userid, 'crystals', $reward['crystals']);
        }
        $paid = ['day' => $day] + $reward;
        return true;
    });
    return $paid;
}
