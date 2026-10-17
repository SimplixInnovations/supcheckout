# Staging from a Production Store

**Purpose:** build a safe staging copy of a real WooCommerce store, then use it for the SUPCheckout upgrade rehearsal, local final verification (`LOCAL-FINAL-VERIFICATION.md` §6–7), a sandbox rehearsal and the live one-time acceptance (`LIVE-PAYMENT-ACCEPTANCE-PLAN.md`).

**First target:** `myorganicworld.net` (WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.5, HPOS, KWD plus WCML multicurrency, WPML, FunnelKit checkout, Elessi child theme, LiteSpeed server and object cache, official UPayments 3.1.1). The clone is made with ServerAvatar.

Tools, both in this repository and never in the plugin package:

- `tests/staging/supcheckout-staging-guard.php`: a must-use plugin that blocks email, non-allowlisted HTTP (live UPayments included, until explicitly allowed), webhooks, indexing and side-effect plugins. It is inert unless the site is a staging environment that opted in.
- `tests/staging/sanitize-staging.php` (+ `lib/`): a WP-CLI script that removes customer personal data, tokens and copied secrets. Certified on real WordPress/WooCommerce in legacy and HPOS storage by `tests/integration/StagingSanitizerTest.php`.

## Never

- Never install the guard or run the sanitizer on production. Both refuse, but do not rely on that.
- Never use ServerAvatar or WPvivid "push to live", sync or restore from the staging copy back to production. It would overwrite the store with anonymized data.
- Never open the staging site in a browser, or let anything request it, before Step 2 is complete.
- Never enter the live merchant key on staging outside the live-acceptance run in Step 7.

## Step 0: backup

Take a fresh WPvivid backup of production and confirm it completed. Note the time.

## Step 1: clone with ServerAvatar

1. Clone the application to a new domain, for example `staging.myorganicworld.net`, with a **new database**. The clone must not share production's database or `wp-content` directory.
2. Turn on password protection (HTTP basic auth) for the staging application if ServerAvatar offers it.
3. Do not open the site yet.

## Step 2: neutralize the copy before its first request

Use ServerAvatar's file manager or SSH on the **staging** application. Commands below run as the staging application's own user, for example `sudo -u <app-user> -- wp --path=<staging public_html> …`; find the folder with `grep -l "staging.myorganicworld.net" /home/*/*/public_html/wp-config.php`.

1. Delete `wp-content/object-cache.php`. The LiteSpeed object cache would otherwise connect to the same cache server as production, and staging could write into production's cache.
2. In the staging `wp-config.php`:
   - confirm `DB_NAME` and `DB_USER` are the **new** staging database, not production's;
   - set or replace these lines:

   ```php
   define( 'WP_ENVIRONMENT_TYPE', 'staging' );
   define( 'SUPCHECKOUT_STAGING_GUARD', true );
   define( 'DISABLE_WP_CRON', true );
   define( 'WP_CACHE_KEY_SALT', 'staging-myorganicworld' );
   ```

   - if `WP_HOME` or `WP_SITEURL` are defined, they must point at the staging domain.
3. Upload `tests/staging/supcheckout-staging-guard.php` to `wp-content/mu-plugins/supcheckout-staging-guard.php`.
4. Upload `tests/staging/sanitize-staging.php` and the `tests/staging/lib/` folder to a directory **outside** the web root, for example `~/supcheckout-staging/`.
5. Remove any ServerAvatar cron job that the clone copied for the staging application. Cron is run by hand in later steps.

## Step 2b: HTTPS for the staging domain

Try the panel's certificate install first. If it fails although DNS is correct (A record on this server, no stray AAAA, no CAA), check the challenge path:

```bash
curl -s -o /dev/null -w "%{http_code}
" http://staging.myorganicworld.net/.well-known/acme-challenge/x
```

If files in the site root are served but every `/.well-known/` path returns the web server's own 404 (seen on the first OpenLiteSpeed staging clone, 2026-10-15), do not edit the shared web-server configuration. Use DNS validation instead:

```bash
certbot certonly --manual --preferred-challenges dns -d staging.myorganicworld.net --cert-name <app-name>
```

Add the TXT record it shows (`_acme-challenge.staging`), wait until `dig +short TXT _acme-challenge.staging.myorganicworld.net @8.8.8.8` returns it, then continue. Install the certificate through the panel, or re-enable the app's HTTPS listener from the panel's own saved config. Manual certificates do not auto-renew: repeat the command within 90 days if staging is still needed.

## Step 3: verify with WP-CLI (SSH, staging application directory)

Each of these must print the shown result before you continue.

```bash
wp option get home
```
→ the staging URL, not `https://myorganicworld.net`.

```bash
wp eval 'echo wp_get_environment_type(), " ", defined("SUPCHECKOUT_STAGING_GUARD") ? "guard" : "NO-GUARD", " ", wp_using_ext_object_cache() ? "OBJECT-CACHE-STILL-ON" : "no-object-cache", PHP_EOL;'
```
→ `staging guard no-object-cache`

```bash
wp eval 'var_dump( wp_remote_get( "https://apiv2api.upayments.com/" ) instanceof WP_Error );'
```
→ `bool(true)`: live UPayments is blocked.

```bash
wp plugin list --status=active --field=name
```
→ ZeptoMail (plugin folder `transmail`), FunnelKit Automations, Google for WooCommerce, Modular Connector and LiteSpeed Cache are absent.

If any check fails, stop and fix it before Step 4.

## Step 4: sanitize

Dry run first. It changes nothing and prints counts:

```bash
SUPCHECKOUT_PRODUCTION_HOST=myorganicworld.net wp eval-file ~/supcheckout-staging/sanitize-staging.php
```

Then apply. Type the staging host exactly:

```bash
SUPCHECKOUT_PRODUCTION_HOST=myorganicworld.net SUPCHECKOUT_SANITIZE=apply SUPCHECKOUT_SANITIZE_CONFIRM=staging.myorganicworld.net wp eval-file ~/supcheckout-staging/sanitize-staging.php
```

Run the dry run again. Every count must be `0`.

What it does:
- **Removes:** customer names, emails, phones, addresses, IPs and notes from every order (including checkout drafts and trashed orders), users and the customer lookup; customer login names and passwords (production passwords stop working on staging); the text of order notes and reviews, plus action/webhook log comments; FunnelKit contacts, abandoned carts and engagement data; email logs, form entries, sessions and stored payment tokens; UPayments customer/card tokens; the copied UPayments API key, IBAN and multi-merchant accounts; the token identity secret. It also forces test mode.
- **Stops:** per-order UPayments jobs (all arguments), all pending scheduled actions and WooCommerce webhooks, and deletes WooCommerce REST API keys. It leaves the hourly `upay_process_subscriptions` and `upay_hourly_cron_job` events alone: UPayments plugins re-create them on every page load, and they cannot charge anyone once the key and tokens are gone and WP-Cron is disabled.
- **Keeps:** administrator and shop-manager accounts, order numbers, totals, currencies, statuses, payment methods, UPayments order metadata, products, settings, WPML and the FunnelKit funnels.

Third-party service credentials held in other plugins' settings stay in the copy. The guard stops those plugins or blocks their traffic. Delete them too if your policy requires it.

## Step 5: baseline on the official plugin (before any change)

Record in an evidence file, `docs/project/evidence/STAGING-REHEARSAL-<YYYY-MM-DD>.md`:

1. The UPayments plugin version (3.1.1) and its gateway settings screen: titles, descriptions, design toggles. Screenshot the screen, with the key field empty.
2. Three historical UPayments orders, paid, failed and pending if available: order number, status, and the UPayments rows shown in the admin order panel and on the customer's order view.
3. The checkout in English and in Arabic, in KWD and in one other currency: which UPayments methods appear, with their labels.

## Step 6: upgrade rehearsal and local verification (sandbox only)

1. Deactivate the official **UPayments** plugin. Never run both at once: they share the `upayments` gateway ID.
2. Install the exact package from `scripts/build-release.sh` (verify its SHA-256 against the candidate) and activate **SUPCheckout for UPayments**.
   - If the copy already runs an older SUPCheckout build activated as `supcheckout/UPayments.php`, update it in place by zip upload ("Replace current with uploaded") or `wp plugin install <zip> --force`, without deactivating. Do not use the classic non-JavaScript "Update now" link (`update.php?action=upgrade-plugin`): it leaves the plugin deactivated.
   - After the upload, check that the Plugins screen shows **SUPCheckout for UPayments** active, and that `wp plugin list --status=active --field=file` lists `supcheckout/supcheckout.php` and no `supcheckout/UPayments.php`. Record both in the evidence file.
3. Check the upgrade against the Step 5 baseline:
   - gateway settings carried over (key still empty, test mode on);
   - the same three historical orders show the same status and UPayments details;
   - Arabic labels: method titles translated through WPML String Translation may need re-translating under the `supcheckout` text domain. Record what changed.
4. In the gateway settings, enter the public sandbox key from the UPayments Test Mode documentation (`jtest123`), with test mode on.
5. On the FunnelKit checkout, check each item and record the result:
   - **Payment methods:** they render and select correctly in KWD, and in USD/SAR through the WCML switcher, desktop and 390 px mobile, English and Arabic.
   - **Amount format:** the decimal separator `,` shows correctly, and the amount sent matches the order total exactly.
   - **Theme template:** the Elessi `checkout/payment.php` override renders the methods; the `thankyou.php` override (WooCommerce 8.1.0 era) still shows SUPCheckout's payment details.
   - **Scripts:** with Perfmatters JavaScript delay on, payment selection and submission still work on first interaction.
   - **Upsells:** what a FunnelKit one-click upsell does after a UPayments payment. Record it; SUPCheckout does not support charging upsells.
6. Run `LOCAL-FINAL-VERIFICATION.md` §6–7 on this site.
7. Rehearse `LIVE-PAYMENT-ACCEPTANCE-PLAN.md` scenarios 1–16 with sandbox test cards. Cron is off, so trigger reconciliation by hand, and only the reconciliation jobs:

   ```bash
   wp cron event run simplixpay_upayments_reconcile_order simplixpay_upayments_reconcile_secondary
   ```

   Do not use `wp cron event run --due-now`: it would also run every other due job on the copy.

   Sandbox results are rehearsal only. They prove nothing about production authentication and do not count as live acceptance.

## Step 7: live one-time acceptance (only with authorization)

Only after `OWNER_LIVE_PAYMENT_TEST_AUTHORIZATION=YES`, a resolved production-auth gate and fresh owner acceptance of the package:

1. Add `define( 'SUPCHECKOUT_STAGING_ALLOW_LIVE_PROVIDER', true );` to the staging `wp-config.php`.
2. Enter the live key in the gateway settings screen only. Turn test mode off.
3. Run `LIVE-PAYMENT-ACCEPTANCE-PLAN.md`: all 16 scenarios, minimum amount, a synthetic customer, KWD plus each other currency the store sells in that the UPayments account accepts.
4. Afterwards, remove the live key and the `SUPCHECKOUT_STAGING_ALLOW_LIVE_PROVIDER` line. Refund through the UPayments dashboard.

## Step 8: tear down

Delete the staging application and its database when testing ends, or keep it password-protected with the guard in place. Never sync it back to production.
