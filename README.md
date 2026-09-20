# ISP Billing Pay

A payment gateway for internet providers in countries where mobile money has no API. The customer pays the provider's own mobile money number. A phone the provider owns hears the confirmation arrive, the gateway matches it to the purchase it belongs to, and the provider's billing system is told with a signed webhook. The money never passes through the gateway.

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
* The **amount** must equal the intent's amount, and is never enough on its own. Two people buying the same thing in the same minute cannot be mixed up.
* The **name** confirms. It decides only when a number is already known for a different reference, which is what typing a neighbour's number looks like.
* Every transaction ID is recorded **once**. A repeated message credits nothing.
* Anything that cannot be matched safely waits for a person. Nothing is guessed.

## Running the gateway

1. Point a virtual host at `server/public`. The rest of `server/` must stay outside the web root.
2. Create a MySQL database and copy `server/config.sample.php` to `server/config.php`. The tables create themselves on the first request.
3. Retry undelivered webhooks every minute: `* * * * * php /path/to/server/bin/deliver-webhooks.php`
4. Billing dashboards join on their own from their Direct Number page. To create a merchant by hand: `php server/bin/create-merchant.php "Name" ghana 233 GHS https://example.com/webhook`

Adding a country means adding its mobile money sender names and currency words to `server/src/Parser.php`, with real sample messages in hand.

`server/tests/e2e_local.sh` runs the whole chain (gateway, a billing dashboard as its merchant, and the webhooks between them) on a developer machine.

## The app

See the privacy notes on the app's own screen and in `android/app/src/main/java`. In short: it asks only to be told when a text message arrives and cannot open the inbox, it passes on a message only when the sender name is on the mobile money list, it keeps a payment message until delivery is confirmed and then deletes it, and it talks only to the gateway over https.

Build it with Android Studio, or `cd android && ./gradlew assembleDebug`. Release builds are https only. The release signing key is not in this repository.
