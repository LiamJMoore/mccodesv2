<?php
declare(strict_types=1);

/**
 * Pets and pet racing. A player adopts one animal, feeds it, trains it, and enters it in races against five rivals
 * of its own level. Training makes it faster and tougher; a well-fed pet runs better; racing costs an entry fee and
 * pays the first three home.
 *
 * Races are a place to spend money more than make it: evenly matched, the prizes pay back less than the fees. A pet
 * that is fed and trained every day does better than break even, which is the point of looking after it.
 */
const MCC_PET_TYPES = ['pitbull' => 'Pit Bull', 'alleycat' => 'Alley Cat', 'hawk' => 'Hawk', 'ferret' => 'Ferret', 'greyhound' => 'Greyhound', 'python' => 'Python'];
const MCC_PET_PRICE = 5000;               // to adopt
const MCC_PET_FEED_PRICE = 250;           // fills it up
const MCC_PET_HUNGER_PER_HOUR = 4;        // fullness lost each hour, from 100
const MCC_PET_TRAIN_ENERGY = 5;
const MCC_PET_TRAIN_EVERY = 3600;         // seconds between training sessions
const MCC_PET_XP_PER_LEVEL = 100;
const MCC_PET_RACES_A_DAY = 10;
const MCC_PET_FEE_PER_LEVEL = 200;        // entry fee is this times the pet's level
const MCC_PET_PRIZES = [1 => 3.0, 2 => 1.5, 3 => 0.5];     // times the entry fee
const MCC_PET_FED_BONUS = 1.10;           // a pet at 70% full or better runs this much better

/** A player's pet with its fullness worked out for now, or null. */
function pet_of(int $userid, ?int $now = null): ?array
{
    global $db;
    $p = $db->row('SELECT * FROM `v3_pets` WHERE `userid` = ?', [$userid]);
    if ($p === null) {
        return null;
    }
    $now ??= time();
    $p['full'] = max(0, 100 - intdiv(max(0, $now - (int)$p['last_fed']) * MCC_PET_HUNGER_PER_HOUR, 3600));
    $p['can_train_in'] = max(0, (int)$p['last_trained'] + MCC_PET_TRAIN_EVERY - $now);
    $p['fee'] = MCC_PET_FEE_PER_LEVEL * (int)$p['level'];
    return $p;
}

/** Adopt a pet. Null when adopted, or why not. */
function pet_adopt(int $userid, string $type, string $name): ?string
{
    global $db;
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    if (!isset(MCC_PET_TYPES[$type])) {
        return 'Choose an animal from the list.';
    }
    if (!preg_match('/^[A-Za-z][A-Za-z \'-]{1,19}$/', $name)) {
        return 'A name is 2 to 20 letters (spaces, hyphens and apostrophes are fine).';
    }
    $why = 'You already have a pet.';
    $ok = $db->attempt(function () use ($db, $userid, $type, $name, &$why) {
        // one pet per player: the primary key refuses a second
        if ($db->exec('INSERT IGNORE INTO `v3_pets` (`userid`, `name`, `type`, `level`, `xp`, `speed`, `stamina`, `last_fed`, `last_trained`, `races`, `wins`) VALUES (?, ?, ?, 1, 0, 10, 10, ?, 0, 0, 0)',
                [$userid, $name, $type, time()]) !== 1) {
            return false;
        }
        if (!wallet_take($userid, 'money', MCC_PET_PRICE)) {
            $why = 'Adopting costs ' . money_formatter(MCC_PET_PRICE) . '.';
            return false;
        }
        return true;
    });
    return $ok ? null : $why;
}

function pet_feed(int $userid, ?int $now = null): ?string
{
    global $db;
    $now ??= time();
    $p = pet_of($userid, $now);
    if ($p === null) {
        return 'You have no pet.';
    }
    if ($p['full'] >= 95) {
        return $p['name'] . ' is not hungry.';
    }
    $ok = $db->attempt(function () use ($db, $userid, $now, $p) {
        return $db->exec('UPDATE `v3_pets` SET `last_fed` = ? WHERE `userid` = ? AND `last_fed` = ?', [$now, $userid, (int)$p['last_fed']]) === 1 && wallet_take($userid, 'money', MCC_PET_FEED_PRICE);
    });
    return $ok ? null : 'Food costs ' . money_formatter(MCC_PET_FEED_PRICE) . '.';
}

/** Train. Returns ['stat' => 'speed'|'stamina', 'gain' => , 'levelled' => bool] or why not. */
function pet_train(int $userid, ?int $now = null): array|string
{
    global $db;
    $now ??= time();
    $p = pet_of($userid, $now);
    if ($p === null) {
        return 'You have no pet.';
    }
    if ($p['full'] < 30) {
        return $p['name'] . ' is too hungry to train. Feed it first.';
    }
    $stat = mt_rand(0, 1) ? 'speed' : 'stamina';
    $gain = mt_rand(1, 3);
    $out = $p['name'] . ' needs a rest before training again.';
    $db->attempt(function () use ($db, $userid, $now, $stat, $gain, &$out) {
        // the rest period is in the statement, so one session is one session
        if ($db->exec("UPDATE `v3_pets` SET `{$stat}` = `{$stat}` + ?, `xp` = `xp` + 10, `last_trained` = ? WHERE `userid` = ? AND `last_trained` <= ?", [$gain, $now, $userid, $now - MCC_PET_TRAIN_EVERY]) !== 1) {
            return false;
        }
        if (!bar_spend($userid, 'energy', MCC_PET_TRAIN_ENERGY)) {
            $out = 'Training takes ' . MCC_PET_TRAIN_ENERGY . ' energy.';
            return false;
        }
        $out = ['stat' => $stat, 'gain' => $gain, 'levelled' => pet_level_up($userid)];
        return true;
    });
    return $out;
}

/** Turn experience into levels. True if the pet went up. */
function pet_level_up(int $userid): bool
{
    global $db;
    return $db->exec('UPDATE `v3_pets` SET `level` = `level` + 1, `xp` = `xp` - ? WHERE `userid` = ? AND `xp` >= ?', [MCC_PET_XP_PER_LEVEL, $userid, MCC_PET_XP_PER_LEVEL]) === 1;
}

/** How fast a runner goes this race: mostly what it is made of, partly the day it is having. */
function pet_run(int $speed, int $stamina, float $bonus = 1.0): float
{
    return ($speed * (0.7 + mt_rand(0, 60) / 100) + $stamina * (0.7 + mt_rand(0, 60) / 100)) * $bonus;
}

/**
 * Enter a race. Returns ['place' => 1-6, 'fee' => , 'prize' => , 'field' => [['name', 'you' => bool], ...] in
 * finishing order] or why not.
 */
function pet_race(int $userid, ?string $today = null): array|string
{
    global $db;
    $p = pet_of($userid);
    if ($p === null) {
        return 'You have no pet.';
    }
    if ($p['full'] < 30) {
        return $p['name'] . ' is too hungry to race. Feed it first.';
    }
    $fee = (int)$p['fee'];
    $out = 'You have run today\'s ' . MCC_PET_RACES_A_DAY . ' races. More tomorrow.';
    $db->attempt(function () use ($db, $userid, $p, $fee, $today, &$out) {
        if (!perk_use($userid, 'pet_race', MCC_PET_RACES_A_DAY, $today)) {
            return false;
        }
        if (!wallet_take($userid, 'money', $fee)) {
            $out = 'The entry fee is ' . money_formatter($fee) . '.';
            return false;
        }
        $runners = [['name' => $p['name'], 'you' => true, 'score' => pet_run((int)$p['speed'], (int)$p['stamina'], $p['full'] >= 70 ? MCC_PET_FED_BONUS : 1.0)]];
        $names = ['Biscuit', 'Razor', 'Duchess', 'Knuckles', 'Smoke', 'Lucky', 'Tank', 'Whisper', 'Bandit', 'Queenie', 'Diesel', 'Ghost'];
        shuffle($names);
        // rivals are what a pet of this level is expected to be: 20 points of speed and stamina a level
        $typical = 10 + 10 * (int)$p['level'];
        for ($i = 0; $i < 5; $i++) {
            $runners[] = ['name' => $names[$i], 'you' => false, 'score' => pet_run((int)round($typical * mt_rand(85, 115) / 100), (int)round($typical * mt_rand(85, 115) / 100))];
        }
        usort($runners, fn ($a, $b) => $b['score'] <=> $a['score']);
        $place = 1 + (int)array_search(true, array_column($runners, 'you'), true);
        $prize = (int)round($fee * (MCC_PET_PRIZES[$place] ?? 0));
        if ($prize > 0) {
            wallet_give($userid, 'money', $prize);
        }
        $db->exec('UPDATE `v3_pets` SET `races` = `races` + 1, `wins` = `wins` + ?, `xp` = `xp` + ? WHERE `userid` = ?', [$place === 1 ? 1 : 0, $place === 1 ? 15 : 5, $userid]);
        pet_level_up($userid);
        $out = ['place' => $place, 'fee' => $fee, 'prize' => $prize, 'field' => array_map(fn ($r) => ['name' => $r['name'], 'you' => $r['you']], $runners)];
        return true;
    });
    return $out;
}

/** The best racers: [['userid', 'username', 'name', 'type', 'level', 'wins', 'races'], ...]. */
function pets_top(int $limit = 10): array
{
    global $db;
    return $db->all('SELECT `p`.`userid`, `u`.`username`, `p`.`name`, `p`.`type`, `p`.`level`, `p`.`wins`, `p`.`races` FROM `v3_pets` AS `p` INNER JOIN `users` AS `u` ON `u`.`userid` = `p`.`userid`
                     WHERE `p`.`races` > 0 ORDER BY `p`.`wins` DESC, `p`.`level` DESC, `p`.`userid` LIMIT ' . max(1, min($limit, 50)));
}
