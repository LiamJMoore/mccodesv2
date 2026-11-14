<?php
declare(strict_types=1);
/**
 * MCCodes v2 by Dabomstew & ColdBlooded
 * 
 * Repository: https://github.com/davemacaulay/mccodesv2
 * License: MIT License
 */

$housequery = 1;
global $db, $ir, $userid, $h, $cm, $fm;
require_once('globals.php');

// Save the notepad first so the page shows the new text straight away.
$pn_msg = '';
$_POST['pn_update'] =
        (isset($_POST['pn_update']))
                ? strip_tags(stripslashes($_POST['pn_update'])) : '';
if (!empty($_POST['pn_update']))
{
    if (strlen($_POST['pn_update']) > 500)
    {
        $pn_msg = '<p class="dash-msg bad">You may only enter 500 or less characters here.</p>';
    }
    else
    {
        $pn_update_db = $db->escape($_POST['pn_update']);
        $db->query(
                "UPDATE `users`
        			SET `user_notepad` = '{$pn_update_db}'
        			WHERE `userid` = {$userid}");
        $ir['user_notepad'] = $_POST['pn_update'];
        $pn_msg = '<p class="dash-msg good">Personal notepad updated.</p>';
    }
}

// Engine v3: the home page is the command centre. Everything here is read from the player's state as it is now.
$pc = fn ($now, $max): int => (int) max(0, min(100, $max > 0 ? floor($now / $max * 100) : 0));
$exp = $pc($ir['exp'], $ir['exp_needed']);
$name = perk_name((int) $ir['userid'], (string) $ir['username']);
$house = htmlspecialchars((string) ($ir['hNAME'] ?? 'No fixed address'), ENT_QUOTES, 'ISO-8859-1');
$where = $db->row('SELECT (SELECT `cityname` FROM `cities` WHERE `cityid` = ?) AS `city`, (SELECT `gangNAME` FROM `gangs` WHERE `gangID` = ?) AS `gang`', [(int) $ir['location'], (int) $ir['gang']]);
$gang = (string) ($where['gang'] ?? '');
$city = (string) ($where['city'] ?? '');
$daily = once('daily', fn () => daily_status($userid, (int) $ir['level']));
$season = once('season', fn () => season_status($userid));
$missions = once('missions', fn () => missions_today($userid, (int) $ir['level']));
$done = count(array_filter($missions, fn ($m) => (int) $m['progress'] >= (int) $m['target']));
$worth = home_net_worth($ir);
$stats = ['Strength' => ['strength', (float) $ir['strength']], 'Agility' => ['agility', (float) $ir['agility']], 'Guard' => ['guard', (float) $ir['guard']],
          'Labour' => ['labour', (float) $ir['labour']], 'IQ' => ['IQ', (float) $ir['IQ']]];
$ts = array_sum(array_column($stats, 1));
$ranks = home_ranks($ir);
$tsrank = $ranks['total'];

echo "<div class='dash'>
<section class='dash-hero'>
  <div>
    <p class='dash-eyebrow'>" . e(profile_rank((int) $ir['level'])) . ' &middot; ' . e($city) . "</p>
    <h2>{$name}" . (is_staff() ? "<span class='staff-tag'>Staff</span>" : '') . "</h2>
    <p class='dash-sub'>Level {$ir['level']} &middot; " . ($gang !== '' ? "<a href='yourgang.php'>{$gang}</a>" : 'no gang') . " &middot; living in {$house}</p>
  </div>
  <div class='dash-ring' style='--v:{$exp}'><span>{$exp}%</span><small>to level " . ($ir['level'] + 1) . "</small></div>
</section>

<section class='dash-tiles'>
  <a class='tile' href='bank.php'><span>Cash</span><b>{$fm}</b></a>
  <a class='tile' href='bank.php'><span>Bank</span><b>" . ((int) $ir['bankmoney'] >= 0 ? money_formatter((int) $ir['bankmoney']) : 'No account') . "</b></a>
  <a class='tile' href='crystalshop.php'><span>Crystals</span><b>{$cm}</b></a>
  <a class='tile' href='record.php'><span>Net worth</span><b>" . money_formatter($worth) . "</b></a>
</section>

<section class='dash-bars'>";
foreach (['Health' => ['hp', 'm-hp'], 'Energy' => ['energy', 'm-energy'], 'Will' => ['will', 'm-will'], 'Brave' => ['brave', 'm-brave']] as $label => [$col, $class]) {
    $p = $pc($ir[$col], $ir['max' . $col]);
    echo "<div class='dash-bar {$class}'><div><span>{$label}</span><b>{$ir[$col]} / {$ir['max' . $col]}</b></div><i style='--v:{$p}%'></i></div>";
}
echo "</section>

<div class='dash-cols'>
<section class='dash-card'>
  <header><h3>What to do now</h3></header>
  <div class='dash-todo'>";
$todo = array_slice(home_suggestions($ir), 0, 6);
foreach ($todo as $t) {
    echo "<a href='{$t['href']}'" . ($t['urgent'] ? " class='urgent'" : '') . "><span class='hub-ic'>" . ui_icon($t['icon'], 20) . "</span><span><b>{$t['title']}</b><small>{$t['text']}</small></span></a>";
}
if (!$todo) {
    echo "<p class='dash-quiet'>Nothing pressing. <a href='explore.php'>Explore the city</a>.</p>";
}
echo "</div>
</section>

<section class='dash-card'>
  <header><h3>Today</h3></header>
  <div class='dash-today'>
    <a href='daily.php'><span>Daily reward</span><b>" . ($daily['claimed'] ? 'Claimed' : 'Waiting') . "</b><small>Streak {$daily['streak']} day" . ($daily['streak'] === 1 ? '' : 's') . "</small></a>
    <a href='missions.php'><span>Missions</span><b>{$done} of " . count($missions) . "</b><small>finished today</small></a>
    <a href='season.php'><span>Season pass</span><b>Tier {$season['tier']}</b><small>" . number_format($season['xp']) . " points</small></a>
    <a href='events.php'><span>Unread</span><b>" . ((int) $ir['new_events'] + (int) $ir['new_mail']) . "</b><small>{$ir['new_events']} events, {$ir['new_mail']} mail</small></a>
  </div>
  <header style='margin-top:18px'><h3>Recent events</h3><a class='pill' href='events.php'>All events</a></header>
  <div class='dash-feed'>";
$feed = home_events($userid);
foreach ($feed as $ev) {
    echo '<div' . ((int) $ev['unread'] ? " class='new'" : '') . '><small>' . notify_ago((int) $ev['time']) . '</small><span>' . $ev['text'] . '</span></div>';
}
if (!$feed) {
    echo "<p class='dash-quiet'>Nothing has happened to you yet.</p>";
}
echo "</div>
</section>
</div>

<section class='dash-card'>
  <header><h3>Battle stats</h3><span class='pill'>Total " . number_format($ts) . " &middot; rank #{$tsrank}</span></header>
  <div class='dash-stats'>";
foreach ($stats as $label => $s)
{
    $rank = $ranks[$s[0]];
    $share = $ts > 0 ? (int) round($s[1] / $ts * 100) : 0;
    echo "<div class='stat'><div class='stat-top'><span>{$label}</span><b>"
            . number_format($s[1])
            . "</b></div><div class='stat-bar'><i style='--v:{$share}%'></i></div><small>Rank #{$rank} &middot; {$share}% of total</small></div>";
}
echo "</div>
</section>

<section class='dash-card'>
  <header><h3>Personal notepad</h3><span class='pill'>Only you can see this</span></header>
  {$pn_msg}
  <form action='index.php' method='post' class='dash-note'>
    <textarea rows='6' name='pn_update' maxlength='500'>"
        . htmlentities((string) $ir['user_notepad'], ENT_QUOTES, 'ISO-8859-1')
        . "</textarea>
    <input type='submit' value='Update Notes' />
  </form>
</section>
</div>";
$h->endpage();
