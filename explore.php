<?php
declare(strict_types=1);
/**
 * MCCodes v2 by Dabomstew & ColdBlooded
 *
 * Repository: https://github.com/davemacaulay/mccodesv2
 * License: MIT License
 */

global $ir, $userid, $h, $set, $domain;
require_once('globals.php');
$tresder = rand(100, 999);
if ($ir['jail'] > 0 || $ir['hospital'] > 0)
{
    die('This page cannot be accessed while in jail or hospital.');
}
// Engine v3: the Explore page is a hub of cards, one for every place in the game.
$city = (string) $db->value('SELECT `cityname` FROM `cities` WHERE `cityid` = ?', [(int) $ir['location']], 'the city');
$section = fn (string $title, array $cards): string => "<section class='hub-sec'><h4>" . e($title) . "</h4><div class='hub-grid'>" . implode('', array_filter($cards)) . '</div></section>';
echo "<div class='hub-hero'><div><p class='dash-eyebrow'>You are in</p><h2>" . e($city) . "</h2><p class='dash-sub'>Pick somewhere to go.</p></div>" . ui_icon('compass', 64) . '</div>';
echo mugger_banner();
echo $section('Make money', [
    ui_card('criminal.php', 'mask', 'Crimes', 'Spend brave, earn cash and experience.', 'violet'),
    ui_card('trade.php', 'crate', 'Trading Post', 'Buy local goods, sell them in another city.', 'cyan'),
    ui_card('stocks.php', 'chart', 'Stock Market', 'Prices move every 15 minutes.', 'green'),
    ui_card('invest.php', 'vault', 'Investment Bank', 'Lock money away for interest.', 'gold'),
    ui_card('job.php', 'briefcase', 'Your Job', 'A steady wage and a career.'),
    ui_card('bank.php', 'bank', 'City Bank', 'Keep your money safe from muggers.'),
    ui_card('loanshark.php', 'bank', 'Loan Shark', 'Cash now, more back later.'),
    $ir['location'] == 5 ? ui_card('cyberbank.php', 'bank', 'Cyber Bank', 'An offshore account.') : '',
]);
echo $section('Get stronger', [
    ui_card('gym.php', 'dumbbell', 'Gym', 'Turn energy into strength, agility and guard.', 'green'),
    ui_card('education.php', 'cap', 'Local School', 'Courses that raise your stats.'),
    ui_card('battletent.php', 'swords', 'Battle Tent', 'Fight the challenge bots for prizes.', 'pink'),
    ui_card('crystaltemple.php', 'gem', 'Crystal Temple', 'Trade crystals for energy, IQ or cash.', 'violet'),
    ui_card('pet.php', 'paw', 'Your Pet', 'Feed it, train it, race it.', 'gold'),
]);
echo $section('Rivals and gangs', [
    ui_card('bounties.php', 'crosshair', 'Hitlist', 'Players with a price on their head.', 'pink'),
    ui_card('turf.php', 'flag', 'Territory', 'Hold a city, get paid every Monday.', 'cyan'),
    ui_card('gangcentral.php', 'users', 'Gangs', 'Join one, or start your own.'),
    ui_card('marriage.php', 'heart', 'Marriage', 'A joint account and a shared house.'),
    ui_card('gangwars.php', 'swords', 'Gang Wars', 'Who is fighting whom.'),
    ui_card('fedjail.php', 'bars', 'Federal Jail', 'Where the cheats end up.'),
]);
echo $section('Shops and markets', [
    ui_card('shops.php', 'cart', 'Shops', 'Weapons, armour and supplies.'),
    ui_card('itemmarket.php', 'bag', 'Item Market', 'Buy from and sell to other players.'),
    ui_card('cmarket.php', 'gem', 'Crystal Market', 'Crystals for cash, player to player.'),
    ui_card('craft.php', 'hammer', 'Workshop', 'Turn items into better ones.'),
    ui_card('auction.php', 'ticket', 'Auction House', 'Bid on what other players are selling.'),
    ui_card('estate.php', 'home', 'Estate Agent', 'A bigger house means more will.'),
    ui_card('monorail.php', 'plane', 'Travel Agency', 'Take the monorail to another city.', 'cyan'),
]);
echo $section('Try your luck', [
    ui_card('lottery.php', 'ticket', 'Lottery', 'One ticket wins the pot at midnight.', 'gold'),
    ui_card("slotsmachine.php?tresde={$tresder}", 'dice', 'Slots', 'Line up the numbers.'),
    ui_card("roulette.php?tresde={$tresder}", 'dice', 'Roulette', 'Pick a number from 0 to 36.'),
    ui_card('lucky.php', 'gift', 'Lucky Boxes', 'Five boxes a day.'),
    $ir['location'] == 5 ? ui_card('slotsmachine3.php', 'dice', 'Super Slots', 'Higher stakes.') : '',
]);
echo $section('Rewards', [
    ui_card('daily.php', 'gift', 'Daily Reward', 'Claim every day to build a streak.', 'pink'),
    ui_card('missions.php', 'target', 'Missions', 'Three jobs a day.', 'violet'),
    ui_card('season.php', 'star', 'Season Pass', 'Thirty tiers of rewards this month.', 'gold'),
    ui_card('achievements.php', 'medal', 'Achievements', 'Medals for what you have done.'),
    ui_card('store.php', 'cart', 'Store', 'Crystal packs.', 'cyan'),
    ui_card('crystalshop.php', 'gem', 'Crystal Shop', 'Refills, name colours, badges.', 'violet'),
]);
echo $section('People and records', [
    ui_card('userlist.php', 'list', 'User List', 'Everyone in the game.'),
    ui_card('usersonline.php', 'users', 'Users Online', 'Who is about right now.', 'green'),
    ui_card('record.php', 'trophy', 'Leaderboards', 'Where you rank.', 'gold'),
    ui_card('halloffame.php', 'trophy', 'Hall of Fame', 'The best of the best.'),
    ui_card('stafflist.php', 'shield', e($set['game_name']) . ' Staff', 'Who runs the place.'),
    ui_card('stats.php', 'poll', 'Game Stats', 'The game in numbers.'),
    ui_card('polling.php', 'poll', 'Polling Booth', 'Have your say.'),
]);
echo "<div class='hub-ref'><b>Invite a friend.</b> Every signup from your link earns you two crystals:<br /><code>https://{$domain}/register.php?REF={$userid}</code></div>";
$h->endpage();
