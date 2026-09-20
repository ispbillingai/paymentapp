# Payment Bridge

An Android app that lets an internet provider get paid on their own mobile money number and have the customer connected automatically, with no payment provider account and no API keys.

The provider keeps one Android phone switched on with the SIM that receives the money. When a mobile money confirmation message arrives, this app passes it to the provider's own billing dashboard. The dashboard matches the payment to the customer who was waiting and connects them.

## Download

[dist/PaymentBridge.apk](dist/PaymentBridge.apk) (about 30 KB, Android 5.0 and newer)

On the phone, open the link, download the file and allow installation when Android asks.

## Set up

1. In your dashboard open Settings, then Payment Gateway, then DirectNumber. Add the phone: choose the network, enter the number that receives your money and the name registered on it.
2. Copy the address and the key the page shows you.
3. Open Payment Bridge on the phone, paste both in and tap Save and test.
4. Tap Allow reading payment messages, then Keep running in the background.
5. Send yourself a small amount. It appears in the dashboard under Transactions, then Direct Payments.

Keep the phone on charge and online. If it goes quiet, the dashboard warns you. Payments that arrive while the phone has no internet are kept and sent as soon as it is back.

## What the app can and cannot see

* It asks only for permission to be told when a text message arrives. It cannot open or browse the inbox.
* It passes on a message only when the sender name is on the mobile money list shown in the app. Messages from people, banks and everyone else are dropped on the phone. They are never stored and never sent anywhere.
* A payment message is deleted from the app as soon as the dashboard confirms it has it.
* It talks only to the address you type in, over https, and identifies itself with the key for that phone. If the phone is lost, revoke the key in the dashboard.

The app has no third party libraries, no analytics and no adverts. Everything it does is in `app/src/main/java`.

## What it sends

One POST per payment message to the address you configured, with the header `X-Directpay-Key` and a JSON body:

```json
{ "from": "MobileMoney", "text": "the message", "sentStamp": 1758400000000, "sim": 0, "version": "1.0.0" }
```

Every five minutes it sends `{ "ping": 1 }` so the dashboard knows the phone is alive. Any reply with status 200 means the message was received. Anything else and the app keeps the message and tries again, which is safe because the dashboard records each transaction once.

## Build it yourself

You need Android Studio or the Android SDK with platform 34, and JDK 17 or newer.

```
./gradlew assembleDebug
```

The debug build may talk to a test server over http. Release builds are https only. The release signing key is not in this repository.
