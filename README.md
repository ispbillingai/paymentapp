# ISP Billing Pay

A payment gateway for internet providers connecting mobile money without a provider API integration. The customer pays the provider's own mobile money number. A dedicated Android phone forwards supported confirmations, the gateway matches them to payment intents, and the provider's billing system receives signed webhooks. Money goes directly to the provider's receiving account.

Live at https://ispbillingpay.com. API reference at https://ispbillingpay.com/docs.

```
customer pays the provider's number
        |
listener phone (android/)  ---- reports the message ---->  gateway (server/)
                                                              |
                                        matches it to a payment intent
                                                              |
provider's billing system  <---- signed webhook --------------+
        ^                                                     ^
        +---- creates payment intents with its API key -------+
```

## What is in this repository

| Folder | What it is |
| --- | --- |
| `server/` | The gateway. Plain PHP 8 and MySQL, no framework. Merchants, API keys, listener phones, payment intents, matching, claims and webhooks. It also serves the front page, the API reference and the app download. |
| `android/` | Payment Bridge, the listener app. Plain Java, no third party libraries. |
| `dist/` | The signed app, ready to install. Served at https://ispbillingpay.com/download. |

## The matching rules

They are deliberately strict, and the same for every merchant.

* The payer **number** must equal the number on a waiting intent. Only its last digits are compared, so `0772123456`, `+256 772 123456` and `256772123456` are one number.
* The **amount and currency** must equal the intent. An amount or timestamp alone never identifies the payer. Competing references for the same payer and price stay unmatched for review.
* The **name** confirms. It decides only when a number is already known for a different reference, which is what typing a neighbour's number looks like.
* Each transaction identity (merchant, provider, receiving account and transaction ID) is recorded **once**. Separate IDs preserve genuine equal-amount payments. The billing integration must also make event processing and package activation idempotent.
* Anything that cannot be matched safely waits for a person. Nothing is guessed.
* Recognized reversals arriving before the original credit reserve that transaction identity. A later receipt fills its details but remains reversed and cannot activate a package.

## Running the gateway

1. Point a virtual host at `server/public`. The rest of `server/` must stay outside the web root.
2. Create a MySQL database and copy `server/config.sample.php` to `server/config.php`. The tables create themselves on the first request.
3. Retry undelivered webhooks every minute: `* * * * * php /path/to/server/bin/deliver-webhooks.php`
4. Billing dashboards join on their own from their Direct Number page. To create a merchant by hand: `php server/bin/create-merchant.php "Name" ghana 233 GHS https://example.com/webhook`

Existing merchant credential reissue requires the current merchant bearer key as well as a successful webhook challenge. An unauthenticated public challenge cannot reset an existing merchant. Coordinate rotation with the billing platform because the previous API key and signing secret stop working. Lost credentials require an operator-managed recovery process.

Use production PHP settings with `display_errors=Off`, `display_startup_errors=Off` and `log_errors=On`, a valid writable temporary directory, HTTPS, protected logs and backups. Do not enable `allow_insecure_webhooks` in production. Outbound webhook connections validate public addresses at delivery time and pin DNS resolution. Keep provider account terms and receipt reconciliation in your operating process: a listener submission is not a provider-signed settlement confirmation.

### Database growth

New installations include composite indexes for matching, payment feeds, claims, remembered payers and due webhook deliveries. Existing installations need the explicit index upgrade described in [database guide](server/DATABASE.md); adding indexes is never hidden inside an ordinary request. Review the dry run, then apply during an appropriate maintenance window. The upgrader preserves payment records and can be rerun safely.

### Public website

The gateway serves `/`, `/docs`, `/partners`, `/security`, `/support`, `/app`, `/privacy` and `/terms`, plus the existing `/download`. The pages work without database access. Shared navigation, footer, CSS and JavaScript live in `server/site`; routes are an explicit allowlist with a real 404 page. Contact defaults to `contact@ispbillingpay.com` and can be changed using the `GATEWAY_CONTACT_EMAIL` environment variable. No external fonts, analytics or UI libraries are loaded. Homepage examples detect the visitor’s country using the GeoJS country endpoint and use a bundled currency mapping. A country selector allows manual correction; unavailable detection falls back to illustrative USD amounts. Only example figures change, never merchant or payment settings. See [localisation details](server/site/CURRENCY-DATA.md) and the website privacy page for caching and IP-data handling.

The homepage inbox is explicitly labelled sample data. It illustrates the API workflow; merchant operations and customer activation remain in the connected billing system. Built-in provider choices cover Uganda and Ghana. Other markets can configure an explicit sender with `provider: "other"` and `extra_senders`, using the merchant's currency. Validate receipt samples before enabling activation; generic parsing does not guarantee every provider's message format or language. Kenya remains excluded from Direct Number.

For a local page preview, run `php -S 127.0.0.1:8787 -t server/public server/public/index.php` from the project root.

Adding a built-in provider means adding its mobile money sender names and message formats to `server/src/Parser.php`, with real sample messages in hand. Custom senders let other countries begin integration without waiting for a built-in entry.

Run `php server/tests/security_regression.php` for matching and webhook-target checks. Every database in this project is MySQL, tests included: the suite creates its own throwaway `gateway_test_*` database, uses it, and drops it. It never touches a live database, and it refuses to run against a database whose name does not begin `gateway_test_`. Point it somewhere other than a local MySQL with `GATEWAY_TEST_DSN`, `GATEWAY_TEST_USER` and `GATEWAY_TEST_PASS`. See [database guide](server/DATABASE.md) for index validation.

The legacy `server/tests/e2e_local.sh` changes and drops tables in its configured gateway and billing databases, including `radius`. Run it only against disposable copies of both applications; it is not a safe check for an existing installation.

## The app

See the privacy notes on the app's own screen and in `android/app/src/main/java`. In short: it asks only to be told when a text message arrives and cannot open the inbox, it passes on a message only when the sender name is on the mobile money list, it keeps a payment message until delivery is confirmed and then deletes it, and it talks only to the gateway over https.

Build it with Android Studio, or `cd android && ./gradlew assembleDebug`. Release builds are https only. The release signing key is not in this repository.
