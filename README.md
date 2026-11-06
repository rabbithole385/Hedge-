# HedgeInv — Investment Platform (MySQL Production Build)

Plain PHP + MySQL. No framework, no build step. Copy trading, AI bots, and
self-directed investment plans, all running on a real MySQL database.

## 1. Create the MySQL database (cPanel)

1. cPanel → **MySQL Databases**
2. Under "Create New Database", type a name (e.g. `hedgeinv`) → Create.
   cPanel will save it as `yourcpaneluser_hedgeinv`.
3. Under "MySQL Users → Add New User", create a user + strong password.
   cPanel saves it as `yourcpaneluser_dbuser`.
4. Under "Add User to Database", select that user + that database → **All
   Privileges** → Make Changes.

## 2. Fill in your credentials

Open `config/db_credentials.php` and replace the placeholders with the exact
values cPanel showed you in step 1 (the full prefixed names, not what you
typed):

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'yourcpaneluser_hedgeinv');
define('DB_USER', 'yourcpaneluser_dbuser');
define('DB_PASS', 'your-database-password-here');
```

## 3. Upload and go live

1. Upload everything **inside** the project folder to your domain's document
   root (`public_html` or a subdomain folder).
2. Make sure `assets/uploads/experts/` is writable (755/775) — this is the
   only folder that needs write access now; the database itself lives in
   MySQL, not on disk.
3. Visit the site once in a browser. `config/database.php` checks whether
   the `users` table exists — if not, it runs `database/schema.sql`
   automatically and seeds:
   - Admin: username `admin`, password `ChangeMe123!`
   - 4 demo expert traders, 3 demo AI bots, 3 demo investment plans
   - Placeholder crypto addresses + a random cron secret
4. **Log into `/admin/login.php` and change the admin password immediately**
   (see Known limitations for how, until a change-password UI exists).
5. Go to `/admin/settings.php` and replace the placeholder crypto addresses
   with real receiving wallets.

If the site errors on first visit instead of auto-creating tables (some
hosts restrict multi-statement SQL execution), import `database/schema.sql`
manually instead: phpMyAdmin → your database → Import → choose the file →
Go. Then reload the site once so it seeds the demo data.

## 4. Cron Jobs — set this up, it's what keeps bots/plans moving reliably

Bots and investment plans work by "ticking" on a timer — each active
subscription checks its interval (e.g. every 60 minutes) and applies a
random result. Right now, that check only fires **when someone loads a
page** (dashboard, portfolio, bots, or plans). With no visitors, nothing
ticks, and the system can look "stuck" or "broken" even though it's fine —
that's exactly the problem you flagged.

**Set up a cPanel Cron Job so ticking happens on schedule, independent of
traffic:**

1. cPanel → **Cron Jobs**
2. Under "Add New Cron Job":
   - **Common Settings**: choose "Every 5 Minutes" (or 15 — your call)
   - **Command**:
     ```
     curl -s "https://hedgeinv.com/cron/tick.php?key=YOUR_SECRET_HERE" > /dev/null
     ```
3. Click **Add New Cron Job**.

### About "the exact key"

I can't hand you a fixed key up front — there isn't one yet. It's a random
64-character secret (`bin2hex(random_bytes(16))`) that gets generated **the
first time the site connects to its database**, and it's unique to your
install. It's not something I can predict or pre-set for you.

Here's exactly how to get yours, right after you deploy:

1. Visit the live site once (this triggers the database setup + secret
   generation).
2. Log into `/admin/login.php`.
3. Go to `/admin/settings.php` — the "Automation (cron)" card shows the
   **exact, ready-to-paste command** with your real secret already filled
   in. Copy that whole line straight into the cPanel cron command box.

If you'd rather set your own fixed key instead of the auto-generated one
(e.g. so you can write it down before deployment), you can do that in
phpMyAdmin after the first visit:

```sql
UPDATE settings SET value = 'your-own-secret-string' WHERE setting_key = 'cron_secret';
```

Then use that same string in the cron URL.

### How to confirm it's actually working

- Visit `https://hedgeinv.com/cron/tick.php?key=YOUR_SECRET` directly in a
  browser. You should see something like:
  `OK. Bot ticks processed: 2. Plan ticks processed: 1.`
- Wrong or missing key returns `403 Forbidden`.
- Check `/admin/dashboard.php` — "Active bot value" / "Active plan value"
  will shift slightly each time ticks run.

## 5. Suggested test order once it's live

1. Register a test user → confirm the $1,000 welcome balance shows up.
2. Post a trade result for an expert (as admin) with someone copying →
   confirm the copier's balance updates.
3. Activate a bot / invest in a plan → wait one tick interval (or hit the
   cron URL manually) → refresh dashboard → confirm value moved.
4. Submit a crypto deposit request → approve it as admin → confirm wallet
   credited.

## File map

```
hedgeinv/
  config/
    db_credentials.php        Your real MySQL host/db/user/pass - edit this
    database.php               Connection + auto-schema + seed data + settings helpers
  database/schema.sql          MySQL table definitions (InnoDB, utf8mb4)
  includes/
    auth.php                   Session/auth helpers (user + admin)
    functions.php                Formatting + copy-trading engine + bot/plan tick engine
    header.php / footer.php      Shared layout
  assets/
    css/style.css                Blue theme design system
    uploads/experts/               Uploaded photos (experts, bots, plans)
  auth/                           register.php, login.php, logout.php
  user/                           dashboard, experts (copy trading), bots, plans,
                                   copy.php / bot_subscribe.php / plan_invest.php,
                                   portfolio, transactions, deposit, deposit_crypto, withdraw
  admin/                          login, dashboard, experts, trades, bots, plans,
                                   deposits (crypto review), users, settings
  cron/tick.php                   Cron endpoint for reliable bot/plan automation
  index.php                       Public landing page
```

## Known limitations (flag these before real customer funds are involved)

- No admin "change password" UI yet - rotate it now via phpMyAdmin:
  `UPDATE admins SET password_hash = '<hash>' WHERE username = 'admin';`
  (generate `<hash>` with `password_hash('yourpass', PASSWORD_DEFAULT)` in
  any throwaway PHP snippet).
- No email verification or password-reset flow yet.
- Crypto deposits are manual-approval only - no blockchain/webhook
  verification. That's by design for testing; make sure this is clearly
  communicated to users until real verification is wired up.
- Instant "simulated deposit" has no real payment gateway behind it.
- No rate-limiting on login attempts yet - needed before public launch.
- `config/db_credentials.php` contains a plaintext DB password - make sure
  it's outside any public git repo, or add a `.gitignore` entry for it.

Tell me which of these you want hardened next.

## Market-linked investment plans (Nov 2026)

Investment plans no longer use random returns. Each plan tracks a basket of real
markets (picked in Admin > Plans). On every update, a plan's value moves by the
real % change of its basket since the last update, multiplied by its exposure.

- Prices: Binance (crypto), Yahoo Finance and Stooq. No API keys needed. Cached in
  `live_market_quotes`, fetched at most once a minute per market.
- If any market in a plan has no fresh price, that plan waits. Nothing is guessed.
- Profit and loss stay inside the plan and reach the wallet once, on withdrawal.
- Plans without markets are hidden from clients and frozen until linked.
- Your server must allow outgoing HTTPS. Check "Price feed status" in Admin > Plans.
- Keep the cron job (`/cron/tick.php?key=...`) running every 5–15 minutes.

## Live markets, AI bots and balance correction (Nov 2026)

- **Trade / Live Markets:** all 140 instruments use real prices, from Binance (crypto) and
  Yahoo Finance (stocks, forex, metals, commodities). Bonds follow the SHY/IEF/TLT ETFs.
  Prices sync at most once a minute. Markets without a fresh price can't be traded.
  When an instrument first switches to its live price, existing holdings are rescaled so
  each client keeps exactly the same value and P&L %. Every change is logged in
  `live_migration_log`.
- **AI bots:** each bot trades chosen markets with one rule (trend following, mean
  reversion or long-only) and moves only with real price changes. Profit reaches the
  wallet once, on deactivation.
- **Admin > Balance Correction:** a one-time fix for positions that the old engine paid
  twice. Preview first, then type CORRECT to apply. Every change goes to the Audit Log.
- **Icons:** coin icons from cryptocurrency-icons (CC0) and flags from flag-icons (MIT),
  in `assets/icons/`. Chart.js is bundled in `assets/js/`.

## Deploy on Render (Nov 2026)

1. Push this folder to a **private** GitHub repo. `.gitignore` already keeps your cPanel
   password (`config/db_credentials.cpanel.php`) and clients' KYC/deposit files out.
2. Render: **New > Blueprint**, pick the repo, then **Apply**. `render.yaml` creates the website
   and a private MariaDB 11.4 database, both with persistent disks, and generates all passwords.
3. (Optional) To bring your existing data: in the `hedgeinv` web service, go to
   **Environment > Secret Files**, add a file named `import.sql` (your phpMyAdmin export),
   then redeploy. It is imported once, only into an empty database, and never again.
4. Without an import, log in at `/admin/login.php` as `admin` with the `ADMIN_INITIAL_PASSWORD`
   value shown in the service's Environment tab.

No cron job is needed: the container refreshes live prices, bots and plans every
`TICK_SECONDS` (default 120). Uploads live on the disk at `/var/data/uploads`.
For cPanel hosting, keep using `config/db_credentials.cpanel.php` as before.
