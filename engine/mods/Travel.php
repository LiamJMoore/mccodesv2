<?php
declare(strict_types=1);

/**
 * Travel takes time, and vehicles make it quicker.
 *
 * A journey is a row in v3_travel. Until it ends the player is still counted in the city they left, cannot be
 * attacked, and can only use pages that make sense from a seat (mail, events, the forums, their account).
 * They arrive the next time they, or the live update in their browser, load a page after the arrival time.
 *
 * Without a vehicle you take the monorail and pay the fare. With one you drive: no fare, and a shorter journey.
 * A player owns one vehicle at a time; buying another trades the old one in.
 */
const MCC_TRAVEL_FARE = 1000;             // the monorail ticket, as it has always been
const MCC_TRAVEL_SECONDS = 300;           // the monorail
const MCC_VEHICLE_RESALE_PERCENT = 50;
const MCC_VEHICLES = [
    'bike' => ['name' => 'Motorbike', 'price' => 50000, 'seconds' => 240],
    'car' => ['name' => 'Saloon', 'price' => 250000, 'seconds' => 180],
    'sports' => ['name' => 'Sports Car', 'price' => 1500000, 'seconds' => 120],
    'jet' => ['name' => 'Private Jet', 'price' => 10000000, 'seconds' => 60],
];
/** Pages a player can use while travelling. Everything else waits until they arrive. */
const MCC_TRAVEL_PAGES = ['index.php', 'loggedin.php', 'monorail.php', 'vehicles.php', 'events.php', 'mailbox.php', 'contactlist.php', 'friendslist.php', 'blacklist.php', 'forums.php', 'newspaper.php',
    'announcements.php', 'viewuser.php', 'userlist.php', 'usersonline.php', 'stafflist.php', 'search.php', 'searchname.php', 'searchlocation.php', 'halloffame.php', 'stats.php', 'preferences.php', 'preport.php',
    'tickets.php', 'ledger.php', 'merits.php', 'achievements.php', 'record.php', 'apikey.php', 'api.php', 'logout.php', 'helptutorial.php', 'gamerules.php', 'polling.php', 'polls_view.php', 'donator.php', 'store.php',
    'fame.php', 'inventory.php', 'iteminfo.php', 'season.php', 'explore.php'];

/** The key of the player's vehicle, or null. */
function vehicle_of(int $userid): ?string
{
    global $db;
    $v = $db->value('SELECT `vehicle` FROM `v3_vehicles` WHERE `userid` = ?', [$userid]);
    return is_string($v) && isset(MCC_VEHICLES[$v]) ? $v : null;
}

function vehicle_resale(string $vehicle): int
{
    return intdiv(MCC_VEHICLES[$vehicle]['price'] * MCC_VEHICLE_RESALE_PERCENT, 100);
}

/** Buy a vehicle, trading in the one the player has. Null when bought, or why not. */
function vehicle_buy(int $userid, string $vehicle): ?string
{
    global $db;
    if (!isset(MCC_VEHICLES[$vehicle])) {
        return 'There is no such vehicle.';
    }
    $why = 'You cannot afford it.';
    $db->attempt(function () use ($db, $userid, $vehicle, &$why) {
        $db->value('SELECT `userid` FROM `users` WHERE `userid` = ? FOR UPDATE', [$userid]);       // one purchase at a time
        $old = vehicle_of($userid);
        if ($old === $vehicle) {
            $why = 'You already own one.';
            return false;
        }
        $net = MCC_VEHICLES[$vehicle]['price'] - ($old === null ? 0 : vehicle_resale($old));
        if ($net > 0 ? !wallet_take($userid, 'money', $net) : ($net < 0 && !wallet_give($userid, 'money', -$net))) {
            return false;
        }
        $db->exec('INSERT INTO `v3_vehicles` (`userid`, `vehicle`, `bought`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `vehicle` = VALUES(`vehicle`), `bought` = VALUES(`bought`)', [$userid, $vehicle, time()]);
        $why = null;
        return true;
    });
    return $why;
}

/** Sell the player's vehicle for its resale value. Returns what was paid, or 0. */
function vehicle_sell(int $userid): int
{
    global $db;
    $paid = 0;
    $db->attempt(function () use ($db, $userid, &$paid) {
        $v = $db->value('SELECT `vehicle` FROM `v3_vehicles` WHERE `userid` = ? FOR UPDATE', [$userid]);
        if ($v === null || $db->exec('DELETE FROM `v3_vehicles` WHERE `userid` = ?', [$userid]) !== 1) {
            return false;
        }
        $paid = isset(MCC_VEHICLES[$v]) ? vehicle_resale($v) : 0;
        return $paid === 0 || wallet_give($userid, 'money', $paid);
    });
    return $paid;
}

/** How long this player's journeys take, in seconds. */
function travel_seconds(int $userid): int
{
    $v = vehicle_of($userid);
    return $v === null ? MCC_TRAVEL_SECONDS : MCC_VEHICLES[$v]['seconds'];
}

/** The journey a player is on, or null: ['dest', 'cityname', 'arrives', 'left' => seconds to go]. */
function travel_of(int $userid, ?int $now = null): ?array
{
    global $db;
    $now ??= time();
    $t = $db->row('SELECT `t`.`dest`, `t`.`arrives`, `c`.`cityname` FROM `v3_travel` AS `t` LEFT JOIN `cities` AS `c` ON `c`.`cityid` = `t`.`dest` WHERE `t`.`userid` = ? AND `t`.`arrives` > ?', [$userid, $now]);
    return $t === null ? null : ['dest' => (int)$t['dest'], 'cityname' => (string)$t['cityname'], 'arrives' => (int)$t['arrives'], 'left' => (int)$t['arrives'] - $now];
}

/**
 * Set off for a city. Null when under way, or a string saying why not. The fare (when there is one) and the
 * journey are one piece of work, and a player can only be on one journey.
 */
function travel_start(int $userid, int $dest, ?int $now = null): ?string
{
    global $db;
    $now ??= time();
    $why = null;
    $db->attempt(function () use ($db, $userid, $dest, $now, &$why) {
        $u = $db->row('SELECT `location`, `level`, `jail`, `hospital` FROM `users` WHERE `userid` = ? FOR UPDATE', [$userid]);
        travel_arrive($userid, $now);
        $u['location'] = $db->value('SELECT `location` FROM `users` WHERE `userid` = ?', [$userid]);
        if ((int)$u['jail'] > 0 || (int)$u['hospital'] > 0) {
            $why = 'You cannot travel from ' . ((int)$u['jail'] > 0 ? 'jail' : 'hospital') . '.';
        } elseif ($db->value('SELECT 1 FROM `v3_travel` WHERE `userid` = ?', [$userid]) !== null) {
            $why = 'You are already on your way somewhere.';
        } elseif ((int)$u['location'] === $dest) {
            $why = 'You are already here.';
        } elseif ($db->value('SELECT 1 FROM `cities` WHERE `cityid` = ? AND `cityminlevel` <= ?', [$dest, (int)$u['level']]) === null) {
            $why = 'This city either does not exist or you cannot go there.';
        } elseif (vehicle_of($userid) === null && !wallet_take($userid, 'money', MCC_TRAVEL_FARE)) {
            $why = 'You don\'t have enough money for the ticket.';
        }
        if ($why !== null) {
            return false;
        }
        $db->exec('INSERT INTO `v3_travel` (`userid`, `dest`, `left_at`, `arrives`) VALUES (?, ?, ?, ?)', [$userid, $dest, $now, $now + travel_seconds($userid)]);
        return true;
    });
    return $why;
}

/** If the player's journey is over, put them in the city. Returns the city they arrived in, or null. */
function travel_arrive(int $userid, ?int $now = null): ?int
{
    global $db;
    $now ??= time();
    $dest = $db->value('SELECT `dest` FROM `v3_travel` WHERE `userid` = ? AND `arrives` <= ?', [$userid, $now]);
    if ($dest === null) {
        return null;                        // nearly every page load ends here
    }
    $arrived = null;
    $db->attempt(function () use ($db, $userid, $now, $dest, &$arrived) {
        if ($db->exec('DELETE FROM `v3_travel` WHERE `userid` = ? AND `arrives` <= ?', [$userid, $now]) !== 1) {
            return false;                   // another request got them off the train
        }
        $db->exec('UPDATE `users` SET `location` = ? WHERE `userid` = ?', [(int)$dest, $userid]);
        $arrived = (int)$dest;
        return true;
    });
    return $arrived;
}

function travel_clock(int $seconds): string
{
    return intdiv($seconds, 60) . 'm ' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT) . 's';
}
