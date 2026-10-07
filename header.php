<?php
declare(strict_types=1);

/**
 * MCCodes Version 2.0.5b
 * Copyright (C) 2005-2012 Dabomstew
 * All rights reserved.
 *
 * Redistribution of this code in any form is prohibited, except in
 * the specific cases set out in the MCCodes Customer License.
 *
 * This code license may be used to run one (1) game.
 * A game is defined as the set of users and other game database data,
 * so you are permitted to create alternative clients for your game.
 *
 * If you did not obtain this code from MCCodes.com, you are in all likelihood
 * using it illegally. Please contact MCCodes to discuss licensing options
 * in this case.
 *
 * File: header.php
 * Signature: 52c201ce2e8c549ae70d2936473022f0
 * Date: Fri, 20 Apr 12 08:50:30 +0000
 */

class headers
{

    /**
     * @return void
     */
    public function startheaders(): void
    {
        global $set;
        echo <<<EOF
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="iso-8859-1" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
<link href="css/game.css" type="text/css" rel="stylesheet" />
<title>{$set['game_name']}</title>
</head>
<body class="mc26">
<div class="app">
EOF;
    }

    /**
     * @param $ir
     * @param $lv
     * @param $fm
     * @param $cm
     * @param int $dosessh
     * @return void
     */
    public function userdata($ir, $lv, $fm, $cm, int $dosessh = 1): void
    {
        global $db, $userid, $set;
        $IP = $db->escape($_SERVER['REMOTE_ADDR']);
        $db->query(
            "UPDATE `users`
                 SET `laston` = {$_SERVER['REQUEST_TIME']}, `lastip` = '$IP'
                 WHERE `userid` = $userid");
        if (!$ir['email']) {
            global $domain;
            die(
            "<body>Your account may be broken. Please mail help@{$domain} stating your username and player ID.");
        }
        if (!isset($_SESSION['attacking'])) {
            $_SESSION['attacking'] = 0;
        }
        if ($dosessh && ($_SESSION['attacking'] || $ir['attacking'])) {
            echo 'You lost all your EXP for running from the fight.';
            $db->query(
                "UPDATE `users`
                     SET `exp` = 0, `attacking` = 0
                     WHERE `userid` = $userid");
            $_SESSION['attacking'] = 0;
        }
        $enperc = min((int)($ir['energy'] / $ir['maxenergy'] * 100), 100);
        $wiperc = min((int)($ir['will'] / $ir['maxwill'] * 100), 100);
        $experc = min((int)($ir['exp'] / $ir['exp_needed'] * 100), 100);
        $brperc = min((int)($ir['brave'] / $ir['maxbrave'] * 100), 100);
        $hpperc = min((int)($ir['hp'] / $ir['maxhp'] * 100), 100);
        $enopp  = 100 - $enperc;
        $wiopp  = 100 - $wiperc;
        $exopp  = 100 - $experc;
        $bropp  = 100 - $brperc;
        $hpopp  = 100 - $hpperc;
        $d      = '';
        $u      = $ir['username'];
        if ($ir['donatordays']) {
            $u = "<span style='color: red;'>{$ir['username']}</span>";
            $d =
                "<img src='donator.gif'
                     alt='Donator: {$ir['donatordays']} Days Left'
                     title='Donator: {$ir['donatordays']} Days Left' />";
        }

        $gn = '';
        $bgcolor = 'FFFFFF';

        $initial = strtoupper(substr((string)$ir['username'], 0, 1));
        print
            <<<OUT
<aside class="side">
<a class="brand" href="index.php"><span class="brand-mark">{$initial}</span><span class="brand-name">{$set['game_name']}</span></a>
<section class="player">
  <div class="player-top">
    <span class="avatar">{$initial}</span>
    <div class="who"><b>$gn{$u}</b> $d<small>ID #{$ir['userid']} &middot; Level {$ir['level']}</small></div>
  </div>
  <div class="wallet">
    <div><small>Money</small><b>{$fm}</b></div>
    <div><small>Crystals</small><b>{$ir['crystals']}</b></div>
  </div>
  <div class="meters">
    <div class="meter m-energy"><span>Energy</span><em>{$enperc}%</em><i style="--v:{$enperc}%"></i></div>
    <div class="meter m-will"><span>Will</span><em>{$wiperc}%</em><i style="--v:{$wiperc}%"></i></div>
    <div class="meter m-brave"><span>Brave</span><em>{$ir['brave']}/{$ir['maxbrave']}</em><i style="--v:{$brperc}%"></i></div>
    <div class="meter m-exp"><span>EXP</span><em>{$experc}%</em><i style="--v:{$experc}%"></i></div>
    <div class="meter m-hp"><span>Health</span><em>{$hpperc}%</em><i style="--v:{$hpperc}%"></i></div>
  </div>
  <a class="logout" href="logout.php">Emergency logout</a>
</section>
<nav class="side-nav">
<!-- Links -->
OUT;
        if ($ir['fedjail'] > 0) {
            $q =
                $db->query(
                    "SELECT *
                             FROM `fedjail`
                             WHERE `fed_userid` = $userid");
            $r = $db->fetch_row($q);
            die(
            "<span style='font-weight: bold; color:red;'>
                    You have been put in the {$set['game_name']} Federal Jail
                     for {$r['fed_days']} day(s).<br />
                    Reason: {$r['fed_reason']}
                    </span></body></html>");
        }
        if (file_exists('ipbans/' . $IP)) {
            die(
            "<span style='font-weight: bold; color:red;'>
                    Your IP has been banned from {$set['game_name']},
                     there is no way around this.
                    </span></body></html>");
        }
    }

    /**
     * @return void
     * @noinspection SpellCheckingInspection
     */
    public function menuarea(): void
    {
        define('JDSF45TJI', true);
        include 'mainmenu.php';
        global $ir, $set;
        $bgcolor = 'FFFFFF';
        print '</nav></aside><main class="main"><div class="notices">';
        if ($ir['hospital']) {
            echo "<div class='notice n-hosp'><b>In hospital</b> for {$ir['hospital']} minutes.</div>";
        }
        if ($ir['jail']) {
            echo "<div class='notice n-jail'><b>In jail</b> for {$ir['jail']} minutes.</div>";
        }
        echo "<a class='notice n-donate' href='donator.php'><b>Support {$set['game_name']}</b> and unlock donator perks &rarr;</a>";
        echo '</div><div class="page"><center>';
    }

    /**
     * @return void
     * @noinspection SpellCheckingInspection
     */
    public function smenuarea(): void
    {
        define('JDSF45TJI', true);
        include 'smenu.php';
        $bgcolor = 'FFFFFF';
        print '</nav></aside><main class="main staff"><div class="page"><center>';
    }

    /**
     * @return void
     */
    public function endpage(): void
    {
        global $db;
        $query_extra = '';
        if (isset($_GET['mysqldebug']) && check_access('administrator')) {
            $query_extra = '<br />' . implode('<br />', $db->queries);
        }
        print
            <<<OUT
</center></div>
<footer class="foot">{$db->num_queries} queries{$query_extra}</footer>
</main>
</div>
</body>
</html>
OUT;
    }
}
