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
     * Is this a sender the owner has asked for?
     *
     * A number used to be refused here whatever the list said, on the reasoning
     * that a number is a person. The list is the decision instead: a sender
     * reaches this phone's outbox only because the owner typed it, and an empty
     * list still forwards nothing at all.
     */
    static boolean allowed(Context c, String sender) {
        String key = normalise(sender);
        if (key.isEmpty()) {
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
