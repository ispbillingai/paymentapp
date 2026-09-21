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

    /**
     * The networks an owner can simply tick, and the sender names each one's
     * messages actually arrive from. A network is offered as one choice because
     * that is what the owner knows; the sender names behind it are the detail.
     *
     * Anything not listed here is still allowed: the owner types it, which is how
     * a network we have not met yet gets used without waiting for a new build.
     */
    static final String[][] NETWORKS = {
            {"MTN MoMo", "MobileMoney, MTN MoMo, MTNMobMoney, MoMo"},
            {"Airtel Money", "AirtelMoney, Airtel Money"},
            {"Telecel Cash", "TelecelCash, T-Cash"},
            {"Vodafone Cash", "VodaCash"},
            {"AT Money", "ATMoney"},
            {"Orange Money", "OrangeMoney, Orange Money"},
            {"Moov Money", "MoovMoney, Moov Money"},
            {"Wave", "Wave"},
    };

    /** The sender names one network covers. */
    static String[] namesFor(int network) {
        String[] names = NETWORKS[network][1].split(",");
        for (int i = 0; i < names.length; i++) {
            names[i] = names[i].trim();
        }
        return names;
    }

    /** Is this network currently switched on in the saved list? */
    static boolean selected(String saved, int network) {
        for (String name : namesFor(network)) {
            if (listContains(saved, name)) {
                return true;
            }
        }
        return false;
    }

    /** Names the owner typed in themselves: everything no listed network accounts for. */
    static String extras(String saved) {
        StringBuilder out = new StringBuilder();
        for (String entry : saved.split(",")) {
            String name = entry.trim();
            if (name.isEmpty() || knownToAnyNetwork(name)) {
                continue;
            }
            if (out.length() > 0) {
                out.append(", ");
            }
            out.append(name);
        }
        return out.toString();
    }

    /** Builds the saved list back up from the ticked networks plus anything typed. */
    static String compose(boolean[] chosen, String typed) {
        StringBuilder out = new StringBuilder();
        for (int i = 0; i < NETWORKS.length; i++) {
            if (!chosen[i]) {
                continue;
            }
            for (String name : namesFor(i)) {
                if (!listContains(out.toString(), name)) {
                    if (out.length() > 0) {
                        out.append(", ");
                    }
                    out.append(name);
                }
            }
        }
        for (String entry : typed.split(",")) {
            String name = entry.trim();
            if (!name.isEmpty() && !listContains(out.toString(), name)) {
                if (out.length() > 0) {
                    out.append(", ");
                }
                out.append(name);
            }
        }
        return out.toString();
    }

    /**
     * Which listed network claims this sender name, or -1 for none. A name the
     * owner types that a network already covers ticks that network instead of
     * being thrown away.
     */
    static int networkFor(String name) {
        for (int i = 0; i < NETWORKS.length; i++) {
            for (String known : namesFor(i)) {
                if (normalise(known).equals(normalise(name))) {
                    return i;
                }
            }
        }
        return -1;
    }

    private static boolean knownToAnyNetwork(String name) {
        for (int i = 0; i < NETWORKS.length; i++) {
            for (String known : namesFor(i)) {
                if (normalise(known).equals(normalise(name))) {
                    return true;
                }
            }
        }
        return false;
    }

    private static boolean listContains(String list, String name) {
        for (String entry : list.split(",")) {
            if (normalise(entry).equals(normalise(name))) {
                return true;
            }
        }
        return false;
    }

    static String normalise(String s) {
        return s == null ? "" : s.toLowerCase(Locale.ROOT).replaceAll("[^a-z0-9]", "");
    }

    /**
     * Two spellings of one phone number.
     *
     * A message arrives from +254796381603 while the owner types 0796381603,
     * and compared as text those are simply different, which is a sender list
     * that looks right and never matches. The last nine digits are the number
     * itself: the country code and the leading zero are how it was written
     * down, not which phone it is. Both sides must be numbers, so MTN and MTN2
     * are still two different names.
     */
    private static boolean sameNumber(String a, String b) {
        if (!a.matches("\\d{9,}") || !b.matches("\\d{9,}")) {
            return false;
        }
        return a.substring(a.length() - 9).equals(b.substring(b.length() - 9));
    }

    /**
     * Is this a sender the owner has asked for?
     *
     * A number used to be refused here whatever the list said, on the reasoning
     * that a number is a person. The list is the decision instead: a sender
     * reaches this phone's outbox only because the owner typed it, and an empty
     * list still forwards nothing at all.
     */
    static boolean allowed(Context c, String sender) {
        return matches(Prefs.senders(c), sender);
    }

    /**
     * The decision itself, with the saved list handed in rather than read.
     *
     * Separated from allowed() only so it can be tested: this is what decides
     * whether a message leaves the phone, and away from a phone there is
     * nothing to read the saved list from.
     */
    static boolean matches(String list, String sender) {
        String key = normalise(sender);
        if (key.isEmpty() || list == null) {
            return false;
        }
        for (String name : list.split(",")) {
            String listed = normalise(name);
            if (listed.isEmpty()) {
                continue;
            }
            if (listed.equals(key) || sameNumber(listed, key)) {
                return true;
            }
        }
        return false;
    }
}
