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

## Pages on v3

Every page starts through `engine/boot.php`. These pages have also been moved onto the safe functions, each with a
test that sends the same request from several logins at the same moment:

| Area | Pages | What v2 allowed |
|---|---|---|
| Banks | `bank.php`, `cyberbank.php` | One balance withdrawn many times; a negative bank balance |
| Sending | `sendcash.php`, `sendcrys.php`, `sendbank.php`, `sendcyber.php` | The same money sent many times |
| Shops and markets | `shops.php`, `itembuy.php`, `itemsell.php`, `imadd.php`, `itemmarket.php`, `cmarket.php` | One item sold twice; one listing bought twice |
| Gym, crimes, temple | `gym.php`, `docrime.php`, `crystaltemple.php` | Many trains or crimes on one bar of energy or brave |
| Fights | `attack.php`, `attackwon.php`, `attackbeat.php`, `attacktake.php`, `attacklost.php` | One loser mugged many times; the "you lost" page open to anyone |
| Casino | `slotsmachine.php`, `roulette.php`, `lucky.php` | Unlimited plays on one stake |
| Town | `estate.php`, `monorail.php`, `jailbail.php`, `jailbust.php` | One house sold many times |
| Items | `itemuse.php`, `itemsend.php`, `equip_weapon.php`, `equip_armor.php`, `unequip.php` | One item used, sent or unequipped many times |
| Gangs | `creategang.php`, `yourgang.php` (donate, vault, capacity, mass payment) | The vault paid out more than it held |
| Timed updates | `crons/cronless_crons.php`, `check_level()` | Energy and brave regenerated, and levels gained, once per simultaneous page load |

Other fixes made on the way:

- Crime success formulas were run through PHP's `eval()`. `engine/Formula.php` works them out as arithmetic only, and
  the staff form refuses anything else.
- Challenge bots never paid their prize (the test was the wrong way round). They now pay the first time.
- The Cyber Bank's withdrawal fee was described but never charged. It is now charged, as the page says.
- The staff "fire cron" form had no anti-forgery token.
- The staff index loaded a page from mccodes.com on every visit. It no longer does.
- An item can only change a listed set of player columns, and adds to them rather than overwriting.
- Forums: a link written as `[url=jav&#x61;script:...]` ran script when clicked, and `[style=...]` accepted any CSS.
  Links must now be plain http or https addresses and styles may set only colour, weight and size.
- Forums: any player could move a topic (the permission test was written the wrong way round), and moderator links
  (lock, pin, move, delete) worked from anywhere. They now need the moderator role and a link token (`link_token()`).
- Forums: every image in a post was fetched by the game's own server each time the post was shown. It no longer is.
- Forums: an address that erased every forum, topic and post (`?empty=1&code=kill`) has been removed.

## The database upgrades itself

`engine/Schema.php` holds numbered steps. The start-up path compares the game's step (in the `settings` table) with
the latest and applies what is missing, once, under a lock. Nobody runs `.sql` files. To change the database, add a
step; never edit a released one.

## New features

Each lives in `engine/mods/`, has its own page, and is linked from the menu.

| Feature | Page | File | In short |
|---|---|---|---|
| Daily reward | `daily.php` | `Daily.php` | Once a day; grows over a week; crystals on day 7; a bonus on day 30; resets if a day is missed |
| Achievements | `achievements.php` | `Achievements.php` | 30 badges, each unlocked and paid once. Add one by adding a line |
| Daily missions | `missions.php` | `Missions.php` | Three a day per player, a bonus for all three |
| Bounties | `bounties.php` | `Bounties.php` | Money on a player's head, paid to whoever mugs or hospitalises them. 10% fee leaves the game |
| Crafting | `craft.php`, `staff_crafting.php` | `Crafting.php` | Staff define recipes; a craft is all or nothing |
| Notifications | the bell in the header | `Notify.php`, `js/notify.js` | Unread events and mail, refreshed every half minute |
| Player API | `api.php`, `apikey.php` | `Api.php` | Read-only JSON. Keys are stored as fingerprints and limited to 60 requests a minute |
| Record | `record.php` | `Api.php` | A player's counters with ranks, fight record, nine leaderboards |
| Staff dashboard | `staff_dashboard.php` | `Dashboard.php` | Totals, a books-balance check, 30-day trends, a watchlist, system health |
| Season Pass | `season.php` | `Season.php` | A season a month; 30 tiers; a free track and a premium one bought with crystals; nothing random |
| Installable app | `manifest.webmanifest`, `sw.js` | | The game installs to a phone's home screen and shows a friendly page when offline. Game pages are never stored |
| Crystal shop | `crystalshop.php` | `Perks.php` | Refills with a daily limit, a name colour, profile badges, a fourth mission |
| Stock market | `stocks.php` | `Stocks.php` | Eight companies, prices move every 15 minutes; a fee on every trade and a cap on shares by level |
| Territory | `turf.php` | `Turf.php` | Gangs earn influence in the city they are in; each Monday every city pays its holder's vault |
| Live sidebar | the sidebar | `js/live.js`, `api.php?do=live` | Money, crystals, level and bars refresh every 20 seconds with no reload |
| Lottery | `lottery.php` | `Lottery.php` | A daily draw for game money; 80% of ticket money is the pot; a cap on tickets |
| Pets and races | `pet.php` | `Pets.php` | Adopt, feed and train one animal, and race it against five rivals of its level |
| Travel trading | `trade.php` | `Trade.php` | Each city makes three goods worth 35% more elsewhere; limited shelves, a carry limit and a daily customs allowance |
| Investments | `invest.php` | `Invest.php` | Lock money away for 7, 14 or 30 days for interest; out early pays no interest; one at a time, capped by level |
| Profiles | `viewuser.php` | `Profile.php` | Rank title, days in the game, medals, a hospital or jail banner, and comments |
| Ledger | `ledger.php`, `staff_ledger.php` | `Economy.php` | Every money and crystal change, with the page it came from; a statement for players, an audit view for staff |
| Marriage | `marriage.php` | `Marriage.php` | Propose with a ring, a joint account, live together in the better house (only its owner can sell it) |
| Auction house | `auction.php` | `Auction.php` | Bids are paid when made and returned when beaten; 5% of each sale leaves the game |
| Loan shark | `loanshark.php` | `Loan.php` | One loan at a time, 5% a day on the amount borrowed, a week to pay; overdue debts are collected from pocket and bank |
| Support tickets | `tickets.php`, `staff_tickets.php` | `Tickets.php` | Plain-text conversations between a player and staff; replies are in the staff log |
| Store | `store.php`, `stripe_webhook.php` | `Store.php` | Crystal packs paid through Stripe Checkout (Apple Pay, Google Pay, cards). A signed message from Stripe credits the player, once |

Two small systems sit under these:

- `engine/Progress.php` counts what players do (`progress_add($userid, 'crimes')`). Achievements and missions listen
  to it, so a page reports what happened and never needs to know which rewards exist.
- `engine/Combat.php` finishes a won fight in one locked step and tells listeners (`fight_listen()`), which is how
  bounties are collected.

Rewards, targets, fees and limits are constants at the top of each mod file. Change the number, save the file.

## Taking payments (Stripe: Apple Pay, Google Pay, cards)

The store is closed until two keys are in `config.php` (which is not in the repository):

```php
$_CONFIG['stripe_secret'] = 'sk_live_...';          // Stripe dashboard > Developers > API keys
$_CONFIG['stripe_webhook_secret'] = 'whsec_...';    // Stripe dashboard > Developers > Webhooks > your endpoint
```

1. Open a Stripe account and finish its identity checks.
2. In Settings > Payment methods, switch on Apple Pay and Google Pay. Checkout is hosted by Stripe, so there is no
   Apple domain file to upload.
3. Add a webhook endpoint at `https://YOUR-GAME/stripe_webhook.php` for `checkout.session.completed`,
   `checkout.session.async_payment_succeeded` and `charge.refunded`. Copy its signing secret into `config.php`.
4. Try it with Stripe's test keys (`sk_test_...`) and a test card before using live keys.

Packs and prices are the `MCC_STORE_PACKS` constant in `engine/mods/Store.php`. The player is credited only by the
signed message from Stripe, never by the page they come back to, and the amount paid is checked against the pack.
A refund is recorded and shown on the staff dashboard; crystals are not taken back automatically.

The old PayPal page (`donator.php`) is still there and unchanged. Once the store is open the banner points to the
store instead.

## The look

Black and charcoal surfaces with one accent, gold.

- `css/global.css` holds the design tokens: every colour, radius and font, named once. Change the look there.
- `css/game.css` builds the components from them (shell, menu, cards, tables, forms, buttons, tiles, bars) and
  restyles the markup the older pages already print, so no page carries its own styling.
- `engine/Ui.php` holds the icon set (drawn in code: no icon font, no image files) and the helpers the menu and the
  Explore hub are built from: `ui_link()`, `ui_group()`, `ui_card()`.
- On a phone the player card is a slim header, the menu opens as a sheet and five links sit in a bar at the bottom.
- All motion stops when a device asks for reduced motion.

## The dashboard

`index.php` is the command centre: identity, rank and city, cash, bank, crystals and net worth, the four bars,
"what to do now", today's progress, recent events, battle stats and the notepad. "What to do now" comes from
`home_suggestions()` in `engine/mods/Home.php`, which reads the player's state (hurt, a bounty on them, an
unclaimed reward, brave or energy to spend, a matured investment, a hungry pet) and lists the most pressing first.

## Still on v2 code

These run on the v3 start-up path but their own code is unchanged: jobs and education,
mail, the newspaper, polls, donator pages, and most staff pages.

## Running it locally

```sh
# a database: MariaDB or MySQL, with an empty database and a user for it
php tools/dev/setup.php --host=127.0.0.1 --user=mcc --pass=mccpass --db=mcctest --force
php tests/engine.php          # the engine's own checks
python3 tools/dev/smoke.py    # every page loads for a player and an admin with no PHP error
python3 tests/flows.py        # the real pages, end to end, including several logins at once
```

`setup.php` wipes the database it is given and refuses one that already has players unless `--force` is passed.
Logins it creates: `admin` / `adminpass1`, `player1` / `playerpass1`, and so on. Never run it against a live game.
