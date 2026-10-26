=== WooCommerce Fake Account Blocker ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: woocommerce, registration, spam, fake accounts, bots
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
WC requires at least: 6.0
WC tested up to: 11.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stops bot registrations and spam customer accounts on WooCommerce, and finds and removes the fake accounts you already have.

== Description ==

Bots sign up to WooCommerce shops by the thousand: to post spam, to test stolen logins, or to use your shop's "Welcome" email to deliver their ads. WooCommerce Fake Account Blocker stops them at the sign-up form, without a CAPTCHA, an API key or anything your customers have to do.

= Bot checks =

* **Honeypot field**: a hidden field people never see. Bots fill in every field they find.
* **Form timing**: the form carries a signed timestamp. Sign-ups sent without loading the form, or faster than anyone could type, are refused.
* **JavaScript check**: a tiny inline script fills in a hidden field that most bots leave empty.

All three are invisible to customers and work with page caching.

= Email and name checks =

* **Disposable email addresses**: about 10,000 throwaway services like Mailinator, YOPmail and 10 Minute Mail, from the open disposable-email-domains list. Privacy relays that forward to a real inbox, like Hide My Email and Firefox Relay, are allowed.
* **Domains that can't receive mail**: a DNS lookup catches made-up domains and typos, and tells the customer to check their address.
* **Links in names**: spam bots put web addresses in the name fields so they show up in your emails. Real names never contain them.
* **Your own lists**: blocked email domains (or whole TLDs like .ru), always-allowed domains, blocked words and blocked IPs.

= Limits per IP =

* **New accounts per IP**: three an hour by default. IPv6 counts per /64, so a bot can't rotate through its block.
* **Lockout**: an IP that keeps getting refused is locked out for an hour, without even running the checks.

Both apply to the sign-up forms, never to checkout, so a paying customer is never refused because of their network.

= Clean up the fake accounts you already have =

The Spam Accounts tab scans every customer account against the same rules and lists the ones that look fake, with the reasons. Delete the ones you pick, or all of them, in one click. Customers who have ever placed an order (in either order storage, including guest orders under the same email) or published anything are never listed, and each account is checked again right before it's deleted.

= Where it works =

* WooCommerce's My Account sign-up form: every check.
* Accounts created at checkout, on the classic and block checkouts and the order confirmation page: the email and name checks and your blocked IPs.
* WordPress's own registration form (wp-login.php), which bots find even on shops that don't link to it: every check.

= Built to be safe =

* No new public endpoints, nothing loaded on pages without a sign-up form, and no outside services.
* Visitor IPs can't be spoofed: X-Forwarded-For and CF-Connecting-IP are only believed from Cloudflare (checked against its published ranges) or from proxies you list, so a bot can't dodge the limits or get someone else locked out.
* Test mode logs what would have been refused without refusing anything, so you can check for false alarms first.
* Fails open: if anything goes wrong, the sign-up goes through. The bot checks only start on a form once it has shown it carries them, so a theme whose sign-up form leaves out WooCommerce's hook can't lock customers out.
* Shop managers, administrators, WP-CLI and scheduled imports are never checked.
* A log of every refused sign-up with the reason, kept 30 days by default and capped in size, plus personal data export and erase support and suggested privacy-policy text.
* Works with both WooCommerce order storage modes (HPOS and posts).

Find it under Content Powered > WooCommerce Fake Account Blocker in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Disposable domains from the [disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains) project, CC0 1.0.
* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Content Powered > WooCommerce Fake Account Blocker. Everything works out of the box. If your site is behind a load balancer or proxy, check the Advanced tab shows your real IP.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Will it block real customers? =

It's built not to. The bot checks are invisible, and the email checks only refuse addresses that can't be a customer's real inbox. When a real person could fix the problem, like a typo in their email address, they're told what to fix. If you'd rather see for yourself first, switch on test mode: nothing is refused, and the Overview tab shows what would have been.

= Does it add a CAPTCHA? =

No. There's nothing for customers to solve or click.

= Does it work with page caching? =

Yes. The fields it adds are the same for every visitor, so a cached copy of the page works. WooCommerce's My Account page isn't cached anyway.

= A customer says they couldn't sign up. How do I find out why? =

Open the Overview tab. Every refused sign-up is listed with the email address, IP, form and the exact check that stopped it.

= My site is behind Cloudflare or a load balancer. Will it see the right IP? =

Cloudflare is recognised automatically. For a load balancer or a firewall service such as Sucuri, add its IP or range under Advanced > Trusted proxies. That box shows what the server sees for your own visit, so you can confirm it.

== Changelog ==

See https://github.com/jmsprsns/woocommerce-fake-account-blocker/releases for the full release history.
