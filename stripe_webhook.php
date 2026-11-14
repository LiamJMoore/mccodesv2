<?php
declare(strict_types=1);
/**
 * MCCodes engine v3: where Stripe tells the game a payment has gone through. Not a page for people.
 * Only a request signed with the webhook secret does anything. See engine/mods/Store.php.
 */
$MCC_BOOT = 'public';
require __DIR__ . '/engine/boot.php';
global $db, $set;
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$body = (string)file_get_contents('php://input');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || strlen($body) > 262144
    || !store_signature_ok($body, (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), store_setting('stripe_webhook_secret'))) {
    http_response_code(400);
    exit('refused');
}
$event = json_decode($body, true);
if (!is_array($event)) {
    http_response_code(400);
    exit('refused');
}
if ((int)($set['engine_schema'] ?? 0) < schema_latest()) {
    schema_upgrade();
}
// Any answer in the 200s tells Stripe not to send the event again; an error here makes it retry later, which is safe
// because a payment is credited once however often it is delivered.
echo store_handle_event($event);
