package com.ispledger.paymentapp;

import android.content.Context;
import android.content.SharedPreferences;

/** The three things the owner sets up, plus what the screen shows about the last contact. */
final class Prefs {
    /** Sender names mobile money networks use in the countries we serve. The owner can edit the list. */
    static final String DEFAULT_SENDERS =
            "MobileMoney, MTN MoMo, MTNMobMoney, MoMo, AirtelMoney, Airtel Money, TelecelCash, VodaCash, T-Cash, ATMoney";

    private Prefs() {}

    private static SharedPreferences sp(Context c) {
        return c.getApplicationContext().getSharedPreferences("paymentapp", Context.MODE_PRIVATE);
    }

    static String url(Context c) { return sp(c).getString("url", ""); }
    static String key(Context c) { return sp(c).getString("key", ""); }
    static String senders(Context c) { return sp(c).getString("senders", DEFAULT_SENDERS); }
    static boolean configured(Context c) { return url(c).length() > 8 && key(c).length() == 40; }

    static void save(Context c, String url, String key, String senders) {
        sp(c).edit().putString("url", url.trim()).putString("key", key.trim())
                .putString("senders", senders.trim().isEmpty() ? DEFAULT_SENDERS : senders.trim()).apply();
    }

    static long lastOkAt(Context c) { return sp(c).getLong("lastOkAt", 0); }
    static String lastProblem(Context c) { return sp(c).getString("lastProblem", ""); }

    static void contactOk(Context c) {
        sp(c).edit().putLong("lastOkAt", System.currentTimeMillis()).putString("lastProblem", "").apply();
    }

    static void contactFailed(Context c, String why) {
        sp(c).edit().putString("lastProblem", why).apply();
    }
}
