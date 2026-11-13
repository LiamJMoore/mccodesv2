# Engine v3

The same game, on a new core. Players see no difference; the code underneath is safer and can be tested.

## What changed from v2

| | v2 | v3 |
|---|---|---|
| Start-up | Three near-copies: `globals.php`, `sglobals.php`, `globals_nonauth.php` | One file, `engine/boot.php`. The three old files are a few lines each and pick a mode: `player`, `staff` or `public` |
| Database | SQL built as a string; every value escaped or cast by hand | `$db->row()`, `value()`, `all()`, `exec()`, `insert()`, `transaction()` with values bound (`engine/DbPrepared.php`). `$db->query()` still works |
| Money | Check the balance, then take the money in a second step | One guarded statement (`engine/Economy.php`): a balance cannot be spent twice or go below zero |
| Input | A different hand-written line on every page | `in_int()`, `in_str()`, `in_choice()` and `e()` (`engine/helpers.php`) |
| Tests | None | `tests/engine.php`, `tools/dev/smoke.py`, `tests/flows.py`, run on every push |

## The cheat this closes

In v2 a player with $1,000 in the bank, logged in on ten devices and withdrawing on all of them at the same moment,
was paid $5,000 and left the bank at -$4,000. The page checked the balance it had read when it started, and by the
time each request took the money the others had passed the same check. `tests/flows.py` sends exactly that and now
sees one payment. The same applied to the Cyber Bank and to sending cash, crystals and bank money between players.

## Writing a page on v3

```php
require_once('globals.php');                       // or sglobals.php for a staff page

$id     = in_int('ID', 0, $_GET);                  // a whole number, never negative
$amount = in_int('amount', 0, $_POST);

$target = $db->row('SELECT `userid`, `username` FROM `users` WHERE `userid` = ?', [$id]);
if ($target === null) {
    echo 'That player does not exist.';
} elseif (!verify_csrf_code('gift', in_str('verf', 64, '', $_POST))) {
    echo "<form method='post'>" . request_csrf_html('gift') . " ... </form>";
} elseif (!wallet_send($userid, $id, 'money', $amount)) {
    echo 'You do not have that much.';
} else {
    echo 'Sent ' . money_formatter($amount) . ' to ' . e($target['username']) . '.';
}
$h->endpage();
```

Three rules:

1. **Values never go into the SQL string.** Use `?` and pass them in the array.
2. **Money moves through `wallet_*`.** Never check a balance in PHP and then change it in a second statement.
3. **Anything that changes the game is a POST with a token** (`request_csrf_html` on the form, `verify_csrf_code` before acting).

## Pages on v3 so far

| Page | Bound queries | Guarded money | Form token on every change |
|---|---|---|---|
| start-up (`engine/boot.php`) | yes | | |
| `bank.php` | yes | yes | yes |
| `cyberbank.php` | partly | yes | yes |
| `sendcash.php`, `sendcrys.php`, `sendbank.php`, `sendcyber.php` | partly | yes | yes (as v2) |

Every other page still runs its v2 code on the v3 start-up, and still passes the smoke test.

One behaviour changed on purpose: the Cyber Bank's withdrawal fee. v2 described a 7.5% fee but took only the after-fee
amount out of the bank, so the fee stayed in the player's account and was never charged. v3 takes the whole amount
out and pays the rest, as the page says.

## Still to move (in the order that matters)

1. Everything else that moves money or items: shops, item market, crystal market, gym, crimes, attacking, gangs' vaults, estate, casino pages.
2. Messaging and forums (stored text printed back to other players).
3. Staff pages.
4. The crons.

## Running it locally

```sh
# a database: MariaDB or MySQL, with an empty database and a user for it
php tools/dev/setup.php --host=127.0.0.1 --user=mcc --pass=mccpass --db=mcctest --force
php tests/engine.php          # the engine's own checks
python3 tools/dev/smoke.py    # every page loads for a player and an admin with no PHP error
python3 tests/flows.py        # banks and sending, end to end, including several logins at once
```

`setup.php` wipes the database it is given and refuses one that already has players unless `--force` is passed.
Logins it creates: `admin` / `adminpass1`, `player1` / `playerpass1`, and so on. Never run it against a live game.
