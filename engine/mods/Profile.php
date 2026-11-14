<?php
declare(strict_types=1);

/**
 * A richer profile: a rank title, how long the player has been around, their medals, a banner if they are in
 * hospital or jail, and comments other players leave.
 */
/** level reached => title. Edit freely. */
const MCC_RANKS = [1 => 'Nobody', 3 => 'Lookout', 6 => 'Runner', 10 => 'Hustler', 15 => 'Enforcer', 20 => 'Fixer', 30 => 'Lieutenant', 40 => 'Captain', 55 => 'Underboss', 75 => 'Boss', 100 => 'Kingpin', 150 => 'Legend'];
const MCC_COMMENT_MAX = 240;
const MCC_COMMENT_WAIT = 60;              // seconds between one player's comments

function profile_rank(int $level): string
{
    $title = 'Nobody';
    foreach (MCC_RANKS as $at => $name) {
        if ($level >= $at) {
            $title = $name;
        }
    }
    return $title;
}

/** The card shown at the top of a profile. $r is the player's row (userid, level, signedup, hospital, hospreason, jail, jail_reason, laston). */
function profile_card(array $r): string
{
    $id = (int)$r['userid'];
    $days = max(0, intdiv(time() - (int)$r['signedup'], 86400));
    $earned = function_exists('achievements_earned') ? achievements_earned($id) : [];
    $out = '';
    if ((int)($r['hospital'] ?? 0) > 0) {
        $out .= "<div class='profile-status hosp'><b>In hospital</b> for {$r['hospital']} minutes" . (($r['hospreason'] ?? '') !== '' ? '<br /><small>' . $r['hospreason'] . '</small>' : '') . '</div>';
    } elseif ((int)($r['jail'] ?? 0) > 0) {
        $out .= "<div class='profile-status jail'><b>In jail</b> for {$r['jail']} minutes" . (($r['jail_reason'] ?? '') !== '' ? '<br /><small>' . e((string)$r['jail_reason']) . '</small>' : '') . '</div>';
    }
    $out .= "<div class='season-head'><div><small>Level</small><b>{$r['level']}</b></div><div><small>Rank</small><b>" . e(profile_rank((int)$r['level'])) . "</b></div><div><small>Days in the game</small><b>" . number_format($days)
        . "</b></div><div><small>Medals</small><b>" . count($earned) . '</b></div><div><small>Status</small><b>' . ((int)$r['laston'] >= time() - 900 ? 'Online' : 'Offline') . '</b></div></div>';
    if ($earned) {
        arsort($earned);
        $out .= "<div class='profile-medals'>";
        foreach (array_slice(array_keys($earned), 0, 8) as $key) {
            if (isset(MCC_ACHIEVEMENTS[$key])) {
                $out .= "<span title='" . e(achievement_goal(MCC_ACHIEVEMENTS[$key])) . "'>" . e(MCC_ACHIEVEMENTS[$key]['name']) . '</span>';
            }
        }
        $out .= count($earned) > 8 ? "<a href='achievements.php?u={$id}'>all " . count($earned) . '</a>' : '';
        $out .= '</div>';
    }
    return $out;
}

/** Leave a comment on a profile. Null when left, or why not. Stored as plain text. */
function profile_comment_add(int $author, int $profile, string $text, ?int $now = null): ?string
{
    global $db;
    $now ??= time();
    $text = trim((string)preg_replace('/\s+/', ' ', $text));
    if ($text === '' || strlen($text) > MCC_COMMENT_MAX) {
        return 'A comment is 1 to ' . MCC_COMMENT_MAX . ' characters.';
    }
    if ($db->value('SELECT `userid` FROM `users` WHERE `userid` = ?', [$profile]) === null) {
        return 'There is no such player.';
    }
    if ((int)$db->value('SELECT COALESCE(MAX(`at`), 0) FROM `v3_profile_comments` WHERE `author` = ?', [$author]) > $now - MCC_COMMENT_WAIT) {
        return 'Wait a minute between comments.';
    }
    $db->insert('v3_profile_comments', ['profile' => $profile, 'author' => $author, 'at' => $now, 'text' => $text]);
    if ($author !== $profile) {
        event_add($profile, "<a href='viewuser.php?u={$author}'>Someone</a> left a comment on <a href='viewuser.php?u={$profile}'>your profile</a>.");
    }
    return null;
}

/** Delete a comment: its author, the profile's owner, or staff ($staff) may. */
function profile_comment_delete(int $userid, int $comment_id, bool $staff = false): bool
{
    global $db;
    return $staff
        ? $db->exec('DELETE FROM `v3_profile_comments` WHERE `id` = ?', [$comment_id]) === 1
        : $db->exec('DELETE FROM `v3_profile_comments` WHERE `id` = ? AND (`author` = ? OR `profile` = ?)', [$comment_id, $userid, $userid]) === 1;
}

function profile_comments(int $profile, int $limit = 15): array
{
    global $db;
    return $db->all('SELECT `c`.`id`, `c`.`author`, `c`.`at`, `c`.`text`, `u`.`username` FROM `v3_profile_comments` AS `c` LEFT JOIN `users` AS `u` ON `u`.`userid` = `c`.`author`
                     WHERE `c`.`profile` = ? ORDER BY `c`.`id` DESC LIMIT ' . max(1, min($limit, 50)), [$profile]);
}

/** The comments block for the bottom of a profile, handling its own form. */
function profile_comments_block(int $profile, int $viewer, bool $staff): string
{
    $out = '<h4>Comments</h4>';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profile_do'])) {
        if (!verify_csrf_code('profile_comment', in_str('verf', 64, '', $_POST))) {
            $out .= '<p>That form had expired. Please try again.</p>';
        } elseif ($_POST['profile_do'] === 'add') {
            $why = profile_comment_add($viewer, $profile, in_str('text', 2000, '', $_POST));
            $out .= $why === null ? '' : '<p>' . e($why) . '</p>';
        } elseif ($_POST['profile_do'] === 'delete') {
            profile_comment_delete($viewer, in_int('id', 0, $_POST), $staff);
        }
    }
    $token = request_csrf_html('profile_comment');
    $list = profile_comments($profile);
    if (!$list) {
        $out .= '<p>No comments yet.</p>';
    }
    foreach ($list as $c) {
        $can = $staff || (int)$c['author'] === $viewer || $profile === $viewer;
        $out .= "<div class='profile-comment'><b><a href='viewuser.php?u={$c['author']}'>" . perk_name((int)$c['author'], (string)$c['username']) . '</a></b> <small>' . notify_ago((int)$c['at']) . '</small>'
            . ($can ? " <form action='viewuser.php?u={$profile}' method='post' style='display:inline;'>{$token}<input type='hidden' name='profile_do' value='delete' /><input type='hidden' name='id' value='{$c['id']}' /><button type='submit' class='linkish'>delete</button></form>" : '')
            . '<br />' . e($c['text']) . '</div>';
    }
    return $out . "<form action='viewuser.php?u={$profile}' method='post'>{$token}<input type='hidden' name='profile_do' value='add' />
    <input type='text' name='text' maxlength='" . MCC_COMMENT_MAX . "' size='50' aria-label='Your comment' /> <input type='submit' value='Comment' /></form>";
}
