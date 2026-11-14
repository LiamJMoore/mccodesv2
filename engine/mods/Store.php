<?php
declare(strict_types=1);

/**
 * The Store: players buy crystal packs with real money through Stripe Checkout, which offers Apple Pay, Google Pay
 * and cards on one page hosted by Stripe. The game never sees a card number.
 *
 * How a purchase goes:
 *   1. store.php asks Stripe for a checkout page for one pack and sends the player to it.
 *   2. The player pays on Stripe's page.
 *   3. Stripe calls stripe_webhook.php. That call is signed; the game checks the signature, checks the amount
 *      against the pack, and credits the player once. The "thank you" page the player returns to credits nothing:
 *      anyone can open an address, only Stripe can sign.
 *
 * Setting it up (once): put these in config.php, from the Stripe dashboard:
 *     $_CONFIG['stripe_secret'] = 'sk_live_...';            // Developers > API keys
 *     $_CONFIG['stripe_webhook_secret'] = 'whsec_...';      // Developers > Webhooks > your endpoint
 * and add a webhook endpoint in Stripe pointing at https://YOUR-GAME/stripe_webhook.php for the events
 * checkout.session.completed, checkout.session.async_payment_succeeded and charge.refunded.
 * Never put the keys anywhere else: config.php is not in the repository.
 */
const MCC_STORE_CURRENCY = 'gbp';
/** The packs. 'price' is in pence. The key is stored with each payment, so never reuse a key for a different pack. */
const MCC_STORE_PACKS = [
    'starter' => ['name' => 'Starter pack', 'price' => 299, 'crystals' => 100, 'donator_days' => 30],
    'regular' => ['name' => 'Regular pack', 'price' => 799, 'crystals' => 300, 'donator_days' => 30],
    'big' => ['name' => 'Big pack', 'price' => 1499, 'crystals' => 650, 'donator_days' => 60],
    'huge' => ['name' => 'Huge pack', 'price' => 3999, 'crystals' => 2000, 'donator_days' => 120],
];
const MCC_STORE_SIGNATURE_AGE = 300;      // a webhook older than this many seconds is refused

function store_setting(string $name): string
{
    global $_CONFIG;
    return (string)($_CONFIG[$name] ?? getenv(strtoupper($name)) ?: '');
}

/** Can the store take payments? (Both keys are in place.) */
function store_ready(): bool
{
    return store_setting('stripe_secret') !== '' && store_setting('stripe_webhook_secret') !== '';
}

/** 299 -> "£2.99" */
function store_price(int $pence): string
{
    $sign = ['gbp' => '&pound;', 'usd' => '$', 'eur' => '&euro;'][MCC_STORE_CURRENCY] ?? strtoupper(MCC_STORE_CURRENCY) . ' ';
    return $sign . number_format($pence / 100, 2);
}

/** Send a form to Stripe. Returns [http status, decoded answer]. Tests replace this with $GLOBALS['mcc_store_http']. */
function store_stripe_post(string $path, array $fields): array
{
    if (isset($GLOBALS['mcc_store_http'])) {
        return ($GLOBALS['mcc_store_http'])($path, $fields);
    }
    $ch = curl_init('https://api.stripe.com/v1/' . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_USERPWD => store_setting('stripe_secret') . ':', CURLOPT_HTTPHEADER => ['Stripe-Version: 2024-06-20']]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, is_string($body) ? (json_decode($body, true) ?: []) : []];
}

/**
 * Start a purchase. Returns the address of Stripe's checkout page to send the player to, or null if it could not
 * be started.
 */
function store_checkout(int $userid, string $pack_key, string $base_url): ?string
{
    $pack = MCC_STORE_PACKS[$pack_key] ?? null;
    if ($pack === null || !store_ready()) {
        return null;
    }
    [$status, $session] = store_stripe_post('checkout/sessions', [
        'mode' => 'payment',
        'success_url' => $base_url . '/store.php?paid=1',
        'cancel_url' => $base_url . '/store.php?cancelled=1',
        'client_reference_id' => (string)$userid,
        'metadata' => ['userid' => (string)$userid, 'pack' => $pack_key],
        'line_items' => [['quantity' => 1, 'price_data' => ['currency' => MCC_STORE_CURRENCY, 'unit_amount' => $pack['price'],
            'product_data' => ['name' => $pack['name'] . ': ' . $pack['crystals'] . ' crystals']]]],
    ]);
    $url = (string)($session['url'] ?? '');
    return $status === 200 && str_starts_with($url, 'https://') ? $url : null;
}

/**
 * Is this webhook really from Stripe? Stripe signs "timestamp.body" with the endpoint's secret and sends
 * "t=timestamp,v1=signature" in the Stripe-Signature header.
 */
function store_signature_ok(string $body, string $header, string $secret, ?int $now = null): bool
{
    if ($secret === '') {
        return false;
    }
    $t = 0;
    $given = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') {
            $t = (int)$v;
        } elseif ($k === 'v1') {
            $given[] = $v;
        }
    }
    if ($t <= 0 || abs(($now ?? time()) - $t) > MCC_STORE_SIGNATURE_AGE) {
        return false;
    }
    $expected = hash_hmac('sha256', $t . '.' . $body, $secret);
    foreach ($given as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}

/**
 * Act on a verified event from Stripe. Returns a short word saying what was done, for the log:
 * 'credited', 'already', 'unpaid', 'mismatch', 'unknown', 'refunded', 'ignored'.
 */
function store_handle_event(array $event): string
{
    global $db;
    $type = (string)($event['type'] ?? '');
    $o = $event['data']['object'] ?? [];
    if ($type === 'checkout.session.completed' || $type === 'checkout.session.async_payment_succeeded') {
        if (($o['payment_status'] ?? '') !== 'paid') {
            return 'unpaid';                       // a slow payment method: Stripe sends async_payment_succeeded when it clears
        }
        $pack_key = (string)($o['metadata']['pack'] ?? '');
        $userid = (int)($o['metadata']['userid'] ?? 0);
        $pack = MCC_STORE_PACKS[$pack_key] ?? null;
        if ($pack === null || $userid <= 0 || $db->value('SELECT `userid` FROM `users` WHERE `userid` = ?', [$userid]) === null) {
            return 'unknown';
        }
        // what was paid must be what the pack costs: the amount comes from Stripe, not from anything the player sent
        if ((int)($o['amount_total'] ?? -1) !== $pack['price'] || strtolower((string)($o['currency'] ?? '')) !== MCC_STORE_CURRENCY) {
            return 'mismatch';
        }
        $result = 'already';
        $db->attempt(function () use ($db, $o, $userid, $pack_key, $pack, &$result) {
            // the checkout's id is unique in the table: Stripe can send the same event twice, the player is credited once
            if ($db->exec('INSERT IGNORE INTO `v3_payments` (`provider`, `ref`, `intent`, `userid`, `pack`, `amount`, `currency`, `crystals`, `donator_days`, `created`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    ['stripe', (string)$o['id'], (string)($o['payment_intent'] ?? ''), $userid, $pack_key, $pack['price'], MCC_STORE_CURRENCY, $pack['crystals'], $pack['donator_days'], time(), 'paid']) !== 1) {
                return false;
            }
            $db->exec('UPDATE `users` SET `crystals` = `crystals` + ?, `donatordays` = `donatordays` + ? WHERE `userid` = ?', [$pack['crystals'], $pack['donator_days'], $userid]);
            ledger_add($userid, 'crystals', $pack['crystals'], $pack['name']);
            event_add($userid, 'Thank you! Your ' . e($pack['name']) . " has arrived: {$pack['crystals']} crystals and {$pack['donator_days']} days of donator status.");
            $result = 'credited';
            return true;
        });
        return $result;
    }
    if ($type === 'charge.refunded') {
        // Recorded for staff to see. Crystals are not taken back automatically: they may be spent, and that is a
        // decision for a person.
        $intent = (string)($o['payment_intent'] ?? '');
        return $intent !== '' && $db->exec("UPDATE `v3_payments` SET `status` = 'refunded' WHERE `intent` = ? AND `status` = 'paid'", [$intent]) > 0 ? 'refunded' : 'ignored';
    }
    return 'ignored';
}

/** Money taken: ['today' => pence, 'month' => pence, 'count_month' => n, 'refunded_month' => n]. */
function store_takings(): array
{
    global $db;
    $r = $db->row("SELECT COALESCE(SUM(IF(`created` >= ? AND `status` = 'paid', `amount`, 0)), 0) AS `today`, COALESCE(SUM(IF(`status` = 'paid', `amount`, 0)), 0) AS `month`,
                          SUM(`status` = 'paid') AS `count_month`, SUM(`status` = 'refunded') AS `refunded_month`
                   FROM `v3_payments` WHERE `created` >= ?", [strtotime('today'), time() - 30 * 86400]);
    return array_map('intval', $r);
}
