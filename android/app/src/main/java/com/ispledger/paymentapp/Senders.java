package com.ispledger.paymentapp;

import android.content.Context;

import java.util.Locale;

/**
 * Decides, on the phone, whether a message is a mobile money message at all.
 * Anything that fails this test is dropped here and never stored or sent:
 * those are the owner's private messages.
 */
final class Senders {
    private Senders() {}

    static String normalise(String s) {
        return s == null ? "" : s.toLowerCase(Locale.ROOT).replaceAll("[^a-z0-9]", "");
    }

    static boolean allowed(Context c, String sender) {
        String key = normalise(sender);
        // A sender that is mostly digits is a person, not a network.
        if (key.isEmpty() || key.matches("\\d{5,}")) {
            return false;
        }
        for (String name : Prefs.senders(c).split(",")) {
            if (normalise(name).equals(key)) {
                return true;
            }
        }
        return false;
    }
}
