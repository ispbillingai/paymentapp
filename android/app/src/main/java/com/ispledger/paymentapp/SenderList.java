package com.ispledger.paymentapp;

import android.content.Context;

import java.util.ArrayList;
import java.util.List;

/**
 * The one list of sender names shown on the connection screen.
 *
 * A built-in network and a name the owner typed are the same kind of thing here:
 * a row with a tick box and a Remove. The owner should not have to care which is
 * which, and they can drop a built-in network they do not use as easily as one of
 * their own.
 *
 * Three things are remembered:
 *   Prefs.senders          the sender names currently switched ON. This is what
 *                          decides whether a message is read, and it is unchanged,
 *                          so the listener keeps working across this upgrade.
 *   Prefs.removedNetworks  built-in networks the owner took off the list.
 *   Prefs.customSenders    names the owner added, on or off.
 */
final class SenderList {
    private SenderList() {}

    /** One line on the screen. */
    static final class Row {
        final String label;
        /** The sender names this row switches on. For a typed name, itself. */
        final String names;
        final boolean builtIn;
        boolean on;

        Row(String label, String names, boolean builtIn, boolean on) {
            this.label = label;
            this.names = names;
            this.builtIn = builtIn;
            this.on = on;
        }
    }

    /** The rows to show: the built-in networks still wanted, then the owner's own. */
    static List<Row> rows(Context c) {
        String enabled = Prefs.senders(c);
        String removed = Prefs.removedNetworks(c);
        List<Row> out = new ArrayList<>();
        for (int i = 0; i < Senders.NETWORKS.length; i++) {
            String label = Senders.NETWORKS[i][0];
            if (listHas(removed, label)) {
                continue;
            }
            out.add(new Row(label, Senders.NETWORKS[i][1], true, Senders.selected(enabled, i)));
        }
        for (String name : custom(c)) {
            out.add(new Row(name, name, false, listHas(enabled, name)));
        }
        return out;
    }

    /**
     * Names the owner added. On the first run after upgrading, anything in the
     * saved list that no built-in network accounts for was one of theirs, so it is
     * carried over rather than lost.
     */
    static List<String> custom(Context c) {
        String stored = Prefs.customSenders(c);
        if (stored.isEmpty() && !Prefs.customSendersSet(c)) {
            stored = Senders.extras(Prefs.senders(c));
            Prefs.customSenders(c, stored);
        }
        List<String> out = new ArrayList<>();
        for (String name : stored.split(",")) {
            if (!name.trim().isEmpty()) {
                out.add(name.trim());
            }
        }
        return out;
    }

    /** Adds one of the owner's own names. Returns false if it is already there. */
    static boolean addCustom(Context c, String name) {
        List<String> names = custom(c);
        for (String existing : names) {
            if (Senders.normalise(existing).equals(Senders.normalise(name))) {
                return false;
            }
        }
        names.add(name.trim());
        Prefs.customSenders(c, join(names));
        return true;
    }

    /** Takes a row off the list: a built-in network is remembered as unwanted. */
    static void remove(Context c, Row row) {
        if (row.builtIn) {
            String removed = Prefs.removedNetworks(c);
            if (!listHas(removed, row.label)) {
                Prefs.removedNetworks(c, removed.isEmpty() ? row.label : removed + ", " + row.label);
            }
            return;
        }
        List<String> kept = new ArrayList<>();
        for (String name : custom(c)) {
            if (!Senders.normalise(name).equals(Senders.normalise(row.label))) {
                kept.add(name);
            }
        }
        Prefs.customSenders(c, join(kept));
    }

    /** Brings back every built-in network, for an owner who removed one by mistake. */
    static void restoreNetworks(Context c) {
        Prefs.removedNetworks(c, "");
    }

    static boolean anyNetworkRemoved(Context c) {
        return !Prefs.removedNetworks(c).trim().isEmpty();
    }

    /** The sender names to store: every name belonging to a row that is switched on. */
    static String enabledNames(List<Row> rows) {
        StringBuilder out = new StringBuilder();
        for (Row row : rows) {
            if (!row.on) {
                continue;
            }
            for (String name : row.names.split(",")) {
                String one = name.trim();
                if (!one.isEmpty() && !listHas(out.toString(), one)) {
                    if (out.length() > 0) {
                        out.append(", ");
                    }
                    out.append(one);
                }
            }
        }
        return out.toString();
    }

    private static boolean listHas(String list, String name) {
        for (String entry : list.split(",")) {
            if (Senders.normalise(entry).equals(Senders.normalise(name))) {
                return true;
            }
        }
        return false;
    }

    private static String join(List<String> names) {
        StringBuilder out = new StringBuilder();
        for (String name : names) {
            if (out.length() > 0) {
                out.append(", ");
            }
            out.append(name);
        }
        return out.toString();
    }
}
