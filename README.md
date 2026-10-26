# WooCommerce Fake Account Blocker

Stops bot registrations and spam customer accounts on WooCommerce, and finds and removes the fake accounts you already have.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## What it checks

Every sign-up goes through these checks, cheapest and surest first. The first one that fails refuses the sign-up (or, in test mode, only logs it) and is recorded in the sign-up log with the email, IP, form and reason.

| Check | Where | What it looks for |
|---|---|---|
| Blocked IPs | All | The visitor's IP is on the admin's list (IPs or CIDR ranges) |
| Lockout | Not checkout | The IP (IPv6: its /64) was refused 5 times in the last hour; refused for an hour without running anything else |
| Honeypot | Forms | The hidden `wcfab_xf` field has anything in it (a meaningless name, so autofill leaves it alone) |
| Form timing | Forms | `wcfab_token` is missing, forged (HMAC with `wp_salt( 'nonce' )`), older than two days, or under 2 seconds old |
| JavaScript | Forms | `wcfab_js` doesn't hold the value the inline script copies in |
| New accounts per IP | Not checkout | 3 accounts already created through the sign-up forms from the IP (IPv6: its /64) in the last hour |
| Blocked domains | All | The email domain, or a domain it's under, is on the admin's list (`.ru` blocks a whole TLD) |
| Disposable email | All | The domain is on the bundled list (`data/disposable-domains.txt`, 9,927 domains) |
| Blocked words | All | A listed word or phrase anywhere in the email, username or names |
| Links in names | All | A link, link markup, shortener (t.me/, bit.ly/) or bare web address in a first, last or billing name; only obvious links in usernames and display names, which are often an email address |
| Mail domain | All | The domain has no MX record (or a null MX) and no A/AAAA record |

"Forms" are WooCommerce's My Account sign-up form (`woocommerce_register_form`, checked on `woocommerce_process_registration_errors`) and WordPress's wp-login.php registration (`register_form`, checked on `registration_errors`). Everything else WooCommerce signs up through `wc_create_new_customer()`, which is checked on `woocommerce_registration_errors`: the classic and block checkouts, the order confirmation page's "Create account", and other plugins' sign-up forms. Those get the email and name checks and the blocked IPs, but not the three form checks (there's no form of ours to look at) or the per-IP limits: behind a proxy that isn't set up, every shopper shares a few addresses, and a paying customer mustn't be refused for it.

Allowed domains skip the domain checks (blocked domains, disposable, mail domain). Shop managers, administrators, WP-CLI and cron are never checked.

### Built to fail open

- Every check runs inside a `try`; an error lets the sign-up through.
- The mail domain check only says "no" when DNS answers that the domain has no mail servers and no address, and a domain that certainly takes mail (gmail.com, outlook.com) still resolves, so a server whose DNS is down refuses nobody. A lookup that fails or times out counts as "can't tell", and a lookup slower than 2 seconds pauses the check for 5 minutes, so a domain whose name servers never answer can't tie up the site. Answers are cached (a week for yes, six hours for no, an hour for can't tell).
- Tokens from up to 5 minutes in the future are accepted, for load-balanced servers whose clocks differ.
- The form checks only run on a form after it has printed the fields at least once on this site (`fake_account_blocker_forms_seen`). A theme whose sign-up form leaves out the hook never prints them, so its sign-ups aren't refused for missing fields. Switching theme resets this.
- The fields are the same for every visitor and the token only gets older in a cached page, so page caching can't break them. The inline script is marked so Cloudflare Rocket Loader, LiteSpeed and similar optimizers run it straight away, and WP Rocket's "Delay JavaScript execution" is told to skip it (`rocket_delay_js_exclusions`).

### What the visitor sees

Reasons a real person can fix get their own message: disposable address, domain that can't take mail, JavaScript off, form expired or sent again too quickly, per-IP limit. Everything else shares one message (editable under Advanced) that tells a bot nothing. At checkout, the message adds that they can check out without an account when guest checkout is on.

## The visitor IP

`REMOTE_ADDR`, unless the connection comes from:

- **Cloudflare**, checked against Cloudflare's published ranges (shipped, refreshed weekly): then `CF-Connecting-IP`;
- **a trusted proxy**, listed under Advanced, or any private or loopback address when "Trust proxies on private networks" is on (the default): then the chosen header, with `X-Forwarded-For` read right to left past your own proxies.

So a bot can't dodge the per-IP limits with a fake header, or send one with someone else's IP to get them locked out.

## The sign-up log

One row per checked sign-up in `{prefix}wcfab_log`: `blocked` (refused), `allowed` (account created through a checked path) or `logged` (would have been refused; test mode). The per-IP limit counts `allowed` and `logged` rows; the lockout counts `blocked` rows. Rows older than the retention (30 days by default) are deleted daily, and the table never holds more than 50,000 rows: past that, the oldest go first, checked every 500 inserts so a flood can't fill the database. While an IP is locked out, only one of its attempts every ten minutes is logged.

The all-time "stopped" count survives pruning (`fake_account_blocker_pruned`). Personal data export and erase cover the log by email address.

## Spam Accounts

The scan (AJAX, `fake_account_blocker_scan`, 8 seconds of work per request) reads every account with the customer role and lists the ones that:

- have the customer role and no other;
- have a disposable email, a domain on the blocked list, a blocked word, or a link in a name (and, optionally, an email domain that can't take mail);
- have no order in either order storage (any type or status, by customer ID), no guest order under the same email in the HPOS table, and no posts of any type.

Results are kept in `fake_account_blocker_scan`. Deleting (`fake_account_blocker_delete`, 50 per request) only accepts accounts in those results and checks every condition again first. On a multisite network the account is removed from the site, not the network. Both need `manage_options`; the scan also needs `list_users` and deleting `delete_users` (`remove_users` on a network).

## Hooks

| Hook | Type | Use |
|---|---|---|
| `fake_account_blocker_verdict` | filter | `( ?array $verdict, array $signup )`. Return null to allow, or `array( 'reason' => 'my_reason', 'detail' => '...' )` to refuse |
| `fake_account_blocker_skip` | filter | `( bool $skip, string $context )`. Skip every check |
| `fake_account_blocker_blocked` | action | `( string $reason, array $signup, string $detail )` after a refusal is logged |
| `fake_account_blocker_message` | filter | `( string $message, string $reason, string $context )` |
| `fake_account_blocker_disposable_domains` | filter | `( string[] $domains )` |
| `fake_account_blocker_client_ip` | filter | `( array $client )` with `ip`, `source`, `remote` |
| `fake_account_blocker_form_lifetime` | filter | `( int $seconds )`, default two days |

`$context` is `account`, `checkout` or `wordpress`. Define `FAKE_ACCOUNT_BLOCKER_DISABLED` as true in `wp-config.php` to switch every check off.

## Updating the disposable domain list

```bash
bin/update-disposable-domains.sh
```

It downloads the latest list, keeps only valid domains, and prints how many were added and removed. Commit `data/disposable-domains.txt` with the next release.

## Automatic updates

Updates come from https://github.com/jmsprsns/woocommerce-fake-account-blocker through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `fake-account-blocker.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `fake-account-blocker.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Tag that version and push the tag:

   ```bash
   git tag -a v1.0.1 -m 1.0.1
   git push origin v1.0.1
   ```

   Or publish a GitHub release with that tag (`gh release create v1.0.1 --title 1.0.1 --generate-notes`); its notes become the changelog in the "View details" popup.

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'FAKE_ACCOUNT_BLOCKER_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'FAKE_ACCOUNT_BLOCKER_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
fake-account-blocker.php                        Plugin header, constants, boot, activation
uninstall.php                                   Drops the log table and options when it's deleted
readme.txt                                      WordPress readme; feeds the "View details" popup
data/disposable-domains.txt                     Bundled disposable email domains (CC0)
bin/update-disposable-domains.sh                Refreshes that list (left out of release zips)
includes/
  class-fake-account-blocker-plugin.php         Main singleton, settings, daily housekeeping
  class-fake-account-blocker-guard.php          Hooks into every sign-up path and runs the checks
  class-fake-account-blocker-form.php           Honeypot, signed timestamp and JavaScript fields
  class-fake-account-blocker-rules.php          Email, domain, word and name checks; DNS lookups
  class-fake-account-blocker-request.php        The visitor IP (Cloudflare and trusted proxies)
  class-fake-account-blocker-log.php            The sign-up log table: writes, counts, pruning
  class-fake-account-blocker-install.php        Creates and upgrades the table
  class-fake-account-blocker-cleanup.php        Spam Accounts scan and delete (AJAX)
  class-fake-account-blocker-privacy.php        Privacy policy text, data export and erase
  class-fake-account-blocker-admin.php          Content Powered > WooCommerce Fake Account Blocker page
  class-fake-account-blocker-menu.php           Shared top-level "Content Powered" menu
  class-fake-account-blocker-feedback.php       Shared Submit Feedback and Report a Bug pages
  class-fake-account-blocker-updater.php        GitHub update checks (PUC wiring)
  class-fake-account-blocker-icons.php          Inline Lucide icon helper
assets/
  admin.css, admin.js                           Loaded only on the settings page
  icon.svg                                      Icon on the update screens
  content-powered.svg                           Sumo logo for the Content Powered menu
icons/                                          The Lucide icons the plugin uses (plus LICENSE)
plugin-update-checker/                          Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-fake-account-blocker-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-fake-account-blocker-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icons it uses in `icons/`. To use another, copy its SVG in from the full set in the barebones skeleton. Print one inline with:

```php
echo WooCommerce_Fake_Account_Blocker_Icons::get( 'package', 20 );           // name, size in px
echo WooCommerce_Fake_Account_Blocker_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains)** (`data/disposable-domains.txt`): CC0 1.0 (public domain).
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
