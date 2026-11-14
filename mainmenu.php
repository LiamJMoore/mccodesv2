<?php
declare(strict_types=1);
/**
 * MCCodes v2 by Dabomstew & ColdBlooded
 *
 * Repository: https://github.com/davemacaulay/mccodesv2
 * License: MIT License
 */

/** @noinspection SpellCheckingInspection */
if (!defined('JDSF45TJI'))
{
    echo 'This file cannot be accessed directly.';
    die;
}
global $db, $c, $ir, $set;
// Engine v3: the menu is built in groups, each link with an icon and, where something is waiting, a count beside it.
$hc = (int) $set['hospital_count'];
$jc = (int) $set['jail_count'];
$in_hosp = $ir['hospital'] > 0;
$in_jail = $ir['jail'] > 0;
$free = !$in_hosp && !$in_jail;
$mcc_daily = once('daily', fn () => daily_status((int) $ir['userid'], (int) $ir['level']));
$mcc_season = season_waiting((int) $ir['userid'], once('season', fn () => season_status((int) $ir['userid'])));

echo ui_group('You', [
    $free ? ui_link('index.php', 'home', 'Home') : '',
    $in_hosp ? ui_link('hospital.php', 'cross', 'Hospital', $hc, true) : '',
    $in_jail && !$in_hosp ? ui_link('jail.php', 'bars', 'Jail', $jc, true) : '',
    $free ? ui_link('explore.php', 'compass', 'Explore') : '',
    ui_link('events.php', 'bell', 'Events', (int) $ir['new_events'], $ir['new_events'] > 0),
    ui_link('mailbox.php', 'mail', 'Mailbox', (int) $ir['new_mail'], $ir['new_mail'] > 0),
    !$in_jail || $in_hosp ? ui_link('inventory.php', 'bag', 'Inventory') : '',
]);
if (!$in_hosp) {
    echo ui_group('The city', [
        ui_link('gym.php', 'dumbbell', $in_jail ? 'Jail Gym' : 'Gym'),
        $free ? ui_link('criminal.php', 'mask', 'Crimes') : '',
        $free ? ui_link('job.php', 'briefcase', 'Your Job') : '',
        $free ? ui_link('education.php', 'cap', 'Local School') : '',
        ui_link('hospital.php', 'cross', 'Hospital', $hc),
        $free ? ui_link('jail.php', 'bars', 'Jail', $jc) : '',
    ]);
} else {
    echo ui_group('The city', [ui_link('jail.php', 'bars', 'Jail', $jc)]);
}
echo ui_group('Rewards', [
    ui_link('daily.php', 'gift', 'Daily Reward', $mcc_daily['claimed'] ? '' : 'claim', !$mcc_daily['claimed']),
    ui_link('missions.php', 'target', 'Missions'),
    ui_link('season.php', 'star', 'Season Pass', $mcc_season, $mcc_season > 0),
    ui_link('achievements.php', 'medal', 'Achievements'),
    ui_link('merits.php', 'star', 'Merits'),
    ui_link('fame.php', 'trophy', 'Fame'),
]);
echo ui_group('Money', [
    ui_link('trade.php', 'crate', 'Trading Post'),
    ui_link('stocks.php', 'chart', 'Stock Market'),
    ui_link('invest.php', 'vault', 'Investment Bank'),
    ui_link('loanshark.php', 'bank', 'Loan Shark'),
    ui_link('lottery.php', 'ticket', 'Lottery'),
    ui_link('craft.php', 'hammer', 'Workshop'),
    ui_link('auction.php', 'ticket', 'Auction House'),
    ui_link('stall.php', 'crate', 'Market Stalls'),
    ui_link('business.php', 'briefcase', 'Your Business'),
]);
echo ui_group('Rivals', [
    ui_link('bounties.php', 'crosshair', 'Hitlist'),
    ui_link('turf.php', 'flag', 'Territory'),
    !$in_jail && $ir['gang'] ? ui_link('yourgang.php', 'users', 'Your Gang') : '',
    ui_link('pet.php', 'paw', 'Your Pet'),
    ui_link('marriage.php', 'heart', 'Marriage'),
    ui_link('record.php', 'trophy', 'Record &amp; Leaderboards'),
]);
echo ui_group('Shop', [
    ui_link('store.php', 'cart', 'Store'),
    ui_link('crystalshop.php', 'gem', 'Crystal Shop'),
]);
echo ui_group('Community', [
    ui_link('forums.php', 'chat', 'Forums'),
    ui_link('announcements.php', 'megaphone', 'Announcements', (int) $ir['new_announcements'], $ir['new_announcements'] > 0),
    ui_link('newspaper.php', 'news', 'Newspaper'),
    ui_link('search.php', 'search', 'Search'),
]);
if (is_staff()) {
    echo "<div class='nav-group'><b>Staff</b>" . ui_link('staff.php', 'shield', 'Staff Panel') . "<div class='nav-online'>";
    foreach (get_online_staff() as $r) {
        echo '<a href="viewuser.php?u=' . $r['userid'] . '">' . $r['username'] . '</a> <small>' . datetime_parse($r['laston']) . '</small>';
    }
    echo '</div></div>';
}
if ($ir['donatordays']) {
    echo ui_group('Donators', [ui_link('friendslist.php', 'heart', 'Friends List'), ui_link('blacklist.php', 'ban', 'Black List')]);
}
echo ui_group('Account', [
    ui_link("viewuser.php?u={$ir['userid']}", 'user', 'My Profile'),
    ui_link('ledger.php', 'list', 'Statement'),
    ui_link('preferences.php', 'gear', 'Preferences'),
    ui_link('apikey.php', 'key', 'API Key'),
    ui_link('tickets.php', 'help', 'Support'),
    ui_link('preport.php', 'alert', 'Player Report'),
    ui_link('helptutorial.php', 'help', 'Help Tutorial'),
    ui_link('gamerules.php', 'book', 'Game Rules'),
    ui_link('logout.php', 'logout', 'Logout'),
]);
echo "<div class='nav-clock'>" . date('F j, Y') . ' &middot; ' . date('g:i a') . '</div>';
