<?php
declare(strict_types=1);

/**
 * Reading what the player sent, and writing text back, in one place.
 *
 * v2 pages repeat lines like
 *     $_GET['ID'] = (isset($_GET['ID']) && is_numeric($_GET['ID'])) ? abs(intval($_GET['ID'])) : '';
 * on every page, each slightly different. These do the same jobs with one name each.
 */

/** A whole number from the form or the address, never negative. $default when it is missing or not a number. */
function in_int(string $key, int $default = 0, ?array $from = null): int
{
    $v = ($from ?? $_POST)[$key] ?? ($from === null ? ($_GET[$key] ?? null) : null);
    if (is_int($v)) {
        return abs($v);
    }
    if (!is_string($v) || !preg_match('/^\s*-?\d{1,18}\s*$/', $v)) {
        return $default;
    }
    return abs((int)$v);
}

/** Text from the form or the address: trimmed, cut to $max characters, never an array. */
function in_str(string $key, int $max = 255, string $default = '', ?array $from = null): string
{
    $v = ($from ?? $_POST)[$key] ?? ($from === null ? ($_GET[$key] ?? null) : null);
    if (!is_string($v)) {
        return $default;
    }
    $v = trim(str_replace("\0", '', $v));
    return $max > 0 ? mb_substr($v, 0, $max, '8bit') : $v;
}

/** One of a fixed list of answers, or $default. */
function in_choice(string $key, array $allowed, string $default = '', ?array $from = null): string
{
    $v = in_str($key, 64, $default, $from);
    return in_array($v, $allowed, true) ? $v : $default;
}

/** Text made safe to print inside HTML. The game's pages are ISO-8859-1, as v2 wrote them. */
function e(string|int|float|null $text): string
{
    return htmlentities((string)$text, ENT_QUOTES, 'ISO-8859-1');
}

/**
 * A token for links that change something (delete this, lock that). A form uses request_csrf_html(); a plain link
 * cannot carry a single-use token, so it carries this one, which lasts for the login. Without it, a link such as
 * "delete topic 5" works from anywhere: another site, or an image in a forum post, could make a moderator's browser
 * follow it.
 *
 *     echo "<a href='forums.php?act=lock&amp;topic=5&amp;tk=" . link_token() . "'>Lock</a>";
 *     if (!link_token_ok()) { ...refuse... }
 */
function link_token(): string
{
    if (empty($_SESSION['mcc_link_token'])) {
        $_SESSION['mcc_link_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['mcc_link_token'];
}

function link_token_ok(): bool
{
    $given = (string)($_GET['tk'] ?? $_POST['tk'] ?? '');
    return $given !== '' && !empty($_SESSION['mcc_link_token']) && hash_equals($_SESSION['mcc_link_token'], $given);
}

/**
 * A web address a player typed, made safe to use as a link or an image: http or https only, or '' if it is anything
 * else (javascript:, data:, a scheme hidden behind HTML entities). The result is ready to put inside quotes.
 */
function safe_url(string $url): string
{
    // undo entities first (twice, in case of &amp;#x61;), so "jav&#x61;script:" is seen for what it is
    $plain = html_entity_decode(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = trim((string)preg_replace('/[\x00-\x20\x7f]+/', '', $plain));
    if (!preg_match('~^https?://[A-Za-z0-9.-]+(:\d+)?([/?#][^\s<>"\'`\\\\]*)?$~', $plain)) {
        return '';
    }
    return htmlspecialchars($plain, ENT_QUOTES, 'ISO-8859-1');
}

/**
 * Work something out once per page load. The menu, the dashboard and its suggestions all want the same facts about
 * the player (today's reward, the season, the missions); this lets the first to ask fetch them and the rest reuse
 * the answer. Only for things read while a page is being drawn: never around something a page then changes.
 */
function once(string $key, callable $make): mixed
{
    static $kept = [];
    if (!array_key_exists($key, $kept)) {
        $kept[$key] = $make();
    }
    return $kept[$key];
}

