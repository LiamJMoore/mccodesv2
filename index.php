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

$exp = $ir['exp_needed'] > 0 ? (int) ($ir['exp'] / $ir['exp_needed'] * 100) : 0;
$exp = max(0, min(100, $exp));
$hp_pct = $ir['maxhp'] > 0 ? (int) ($ir['hp'] / $ir['maxhp'] * 100) : 0;
$hp_pct = max(0, min(100, $hp_pct));
$stats = [
    'Strength' => ['strength', (float) $ir['strength']],
    'Agility'  => ['agility', (float) $ir['agility']],
    'Guard'    => ['guard', (float) $ir['guard']],
    'Labour'   => ['labour', (float) $ir['labour']],
    'IQ'       => ['IQ', (float) $ir['IQ']],
];
$ts = 0;
foreach ($stats as $s)
{
    $ts += $s[1];
}
$tsrank = get_rank($ts, 'strength+agility+guard+labour+IQ');
$name = htmlspecialchars((string) $ir['username'], ENT_QUOTES, 'ISO-8859-1');
$house = htmlspecialchars((string) $ir['hNAME'], ENT_QUOTES, 'ISO-8859-1');

echo "<div class='dash'>
<section class='dash-hero'>
  <div>
    <p class='dash-eyebrow'>Welcome back</p>
    <h2>{$name}</h2>
    <p class='dash-sub'>Level {$ir['level']} &middot; living in {$house}</p>
  </div>
  <div class='dash-ring' style='--v:{$exp}'><span>{$exp}%</span><small>to level " . ($ir['level'] + 1) . "</small></div>
</section>

<section class='dash-tiles'>
  <div class='tile'><span>Money</span><b>{$fm}</b></div>
  <div class='tile'><span>Crystals</span><b>{$cm}</b></div>
  <div class='tile'><span>Health</span><b>{$ir['hp']} / {$ir['maxhp']}</b><i style='--v:{$hp_pct}%'></i></div>
  <div class='tile'><span>Property</span><b>{$house}</b></div>
</section>

<section class='dash-card'>
  <header><h3>Battle stats</h3><span class='pill'>Total " . number_format($ts) . " &middot; rank #{$tsrank}</span></header>
  <div class='dash-stats'>";
foreach ($stats as $label => $s)
{
    $rank = get_rank($s[1], $s[0]);
    $share = $ts > 0 ? (int) round($s[1] / $ts * 100) : 0;
    echo "<div class='stat'><div class='stat-top'><span>{$label}</span><b>"
            . number_format($s[1])
            . "</b></div><div class='stat-bar'><i style='--v:{$share}%'></i></div><small>Rank #{$rank} &middot; {$share}% of total</small></div>";
}
echo "</div>
</section>

<section class='dash-card'>
  <header><h3>Quick actions</h3></header>
  <div class='dash-actions'>
    <a href='gym.php'>Train at the gym</a>
    <a href='criminal.php'>Commit a crime</a>
    <a href='job.php'>Go to work</a>
    <a href='education.php'>Study</a>
    <a href='explore.php'>Explore the city</a>
    <a href='inventory.php'>Inventory</a>
  </div>
</section>

<section class='dash-card'>
  <header><h3>Personal notepad</h3><span class='pill'>Only you can see this</span></header>
  {$pn_msg}
  <form action='index.php' method='post' class='dash-note'>
    <textarea rows='8' name='pn_update' maxlength='500'>"
        . htmlentities((string) $ir['user_notepad'], ENT_QUOTES, 'ISO-8859-1')
        . "</textarea>
    <input type='submit' value='Update Notes' />
  </form>
</section>
</div>";
$h->endpage();
