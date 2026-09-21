package com.ispledger.paymentapp;

import android.content.Context;
import android.content.SharedPreferences;

/** The three things the owner sets up, plus what the screen shows about the last contact. */
final class Prefs {
    /** Sender names mobile money networks use in the countries we serve. The owner can edit the list. */
    static final String DEFAULT_SENDERS =
            "MobileMoney, MTN MoMo, MTNMobMoney, MoMo, AirtelMoney, Airtel Money, TelecelCash, VodaCash, T-Cash, ATMoney";

    /** The payment gateway. The owner only changes this when support asks them to. */
    static final String DEFAULT_URL = "https://ispbillingpay.com/v1/device/messages";

    private Prefs() {}

    private static SharedPreferences sp(Context c) {
        return c.getApplicationContext().getSharedPreferences("paymentapp", Context.MODE_PRIVATE);
    }

    static String url(Context c) {
        String u = sp(c).getString("url", "");
        return u.isEmpty() ? DEFAULT_URL : u;
    }
    static String key(Context c) { return sp(c).getString("key", ""); }
    static String senders(Context c) { return sp(c).getString("senders", DEFAULT_SENDERS); }
    static boolean configured(Context c) { return key(c).length() == 40; }

    static void save(Context c, String url, String key, String senders) {
        if (!url.trim().equals(url(c)) || !key.trim().equals(key(c))) sp(c).edit().remove("lastOkAt").remove("lastProblem").apply();
        sp(c).edit().putString("url", url.trim().equals(DEFAULT_URL) ? "" : url.trim()).putString("key", key.trim())
                .putString("senders", senders.trim().isEmpty() ? DEFAULT_SENDERS : senders.trim()).apply();
    }

    /** Built-in networks the owner took off the list, by label. */
    static String removedNetworks(Context c) { return sp(c).getString("removedNetworks", ""); }
    static void removedNetworks(Context c, String value) { sp(c).edit().putString("removedNetworks", value.trim()).apply(); }

    /** Sender names the owner added themselves, whether switched on or not. */
    static String customSenders(Context c) { return sp(c).getString("customSenders", ""); }
    static void customSenders(Context c, String value) { sp(c).edit().putString("customSenders", value.trim()).apply(); }
    /** Has this phone ever written that list? Tells a fresh install from an empty one. */
    static boolean customSendersSet(Context c) { return sp(c).contains("customSenders"); }

    /** The zone times are shown in. Empty means whatever this phone is set to. */
    static String timezone(Context c) { return sp(c).getString("timezone", ""); }
    static void timezone(Context c, String value) { sp(c).edit().putString("timezone", value == null ? "" : value.trim()).apply(); }

    static boolean paused(Context c) { return sp(c).getBoolean("paused", false); }
    static void paused(Context c, boolean value) { sp(c).edit().putBoolean("paused", value).apply(); }
    static long lastOkAt(Context c) { return sp(c).getLong("lastOkAt", 0); }
    static String lastProblem(Context c) { return sp(c).getString("lastProblem", ""); }

    static void contactOk(Context c) {
        sp(c).edit().putLong("lastOkAt", System.currentTimeMillis()).putString("lastProblem", "").apply();
    }

    static void contactFailed(Context c, String why) {
        sp(c).edit().putString("lastProblem", why).apply();
    }
}
