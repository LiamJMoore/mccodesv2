<?php
declare(strict_types=1);
global $db, $_CONFIG;
if (!defined('MONO_ON')) {
    exit;
}
const CRON_OVERRIDE = true;
require_once __DIR__ . '/CronHandler.php';
$get_crons = $db->query(
    'SELECT * FROM cron_times',
);
/**
 * @var $crons
 * Holds the key-value paired data of crons and when they last ran
 * cron-name -> last runtime
 * (ex: minute-1 -> 2024-06-21 03:25:16)
 */
$crons = [];
while ($row = $db->fetch_row($get_crons)) {
    $crons[$row['name']] = $row['last_run'];
}
$db->free_result($get_crons);

/**
 * @param string $cron the "last_run" timestamp of a cron
 * @throws Exception
 */
function get_time_diff(string $cron): int
{
    // Get current time
    $now = new DateTime('now');
    // Get last run time
    $then = new DateTime($cron);
    // Return the difference in seconds
    return (int)($now->format('U') - $then->format('U'));
}

$diffs = [
    'minute-1' => [
        'cron' => $crons['minute-1'],
        'diff' => 60,
    ],
    'minute-5' => [
        'cron' => $crons['minute-5'],
        'diff' => 300,
    ],
    'hour-1' => [
        'cron' => $crons['hour-1'],
        'diff' => 3600,
    ],
    'day-1' => [
        'cron' => $crons['day-1'],
        'diff' => 86400,
    ],
];
foreach ($diffs as $name => $conf) {
    $diff = get_time_diff($crons[$name]);
    if ($diff >= $conf['diff']) {
        $times = floor($diff / $conf['diff']);
        // Engine v3: claim the run before doing it. Every page load that arrives while a run is due sees the same
        // old time; in v2 each of them ran the cron, so loading several pages at the tick regenerated energy and
        // brave several times over. This statement moves the time on only if it is still the one we read, so
        // exactly one request wins and the rest carry on without running anything.
        $d = (int)$conf['diff'];
        $claimed = $db->exec(
            "UPDATE `cron_times` SET `last_run` = CONCAT(DATE_FORMAT(NOW(), '%Y-%m-%d'), ' ', SEC_TO_TIME((TIME_TO_SEC(NOW()) DIV {$d}) * {$d}))
             WHERE `name` = ? AND `last_run` = ?", [$name, $crons[$name]]);
        if ($claimed !== 1) {
            continue;
        }
        (CronHandler::getInstance($db))->run($name, (int)$times);
    }
}
