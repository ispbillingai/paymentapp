/**
 * The one sender list: what it shows, what it stores, and what an existing phone
 * keeps when it upgrades into it.
 *
 * Run by android/tests/run.php, which strips Android out of the classes first.
 * The phone-side Context is replaced here by a plain map, so the rules can be
 * checked without a device.
 */
import java.util.*;

public class SenderListCheck {
    // stands in for SharedPreferences
    static final Map<String, String> store = new HashMap<>();
    static int failures = 0;

    static void is(String what, Object got, Object want) {
        if (!String.valueOf(got).equals(String.valueOf(want))) {
            System.out.println("FAIL " + what + "\n  got:  " + got + "\n  want: " + want);
            failures++;
        }
    }
    static void yes(String what, boolean got) { if (!got) { System.out.println("FAIL " + what); failures++; } }
    static void no(String what, boolean got) { if (got) { System.out.println("FAIL " + what); failures++; } }

    /** One row as the screen shows it. */
    static final class Row {
        final String label, names; final boolean builtIn; boolean on;
        Row(String label, String names, boolean builtIn, boolean on) {
            this.label = label; this.names = names; this.builtIn = builtIn; this.on = on;
        }
    }

    static boolean listHas(String list, String name) {
        for (String entry : list.split(",")) if (Senders.normalise(entry).equals(Senders.normalise(name))) return true;
        return false;
    }
    static String get(String key) { return store.getOrDefault(key, ""); }

    static List<String> custom() {
        String stored = get("customSenders");
        if (stored.isEmpty() && !store.containsKey("customSenders")) {
            stored = Senders.extras(get("senders"));
            store.put("customSenders", stored);
        }
        List<String> out = new ArrayList<>();
        for (String n : stored.split(",")) if (!n.trim().isEmpty()) out.add(n.trim());
        return out;
    }

    static List<Row> rows() {
        String enabled = get("senders"), removed = get("removedNetworks");
        List<Row> out = new ArrayList<>();
        for (int i = 0; i < Senders.NETWORKS.length; i++) {
            if (listHas(removed, Senders.NETWORKS[i][0])) continue;
            out.add(new Row(Senders.NETWORKS[i][0], Senders.NETWORKS[i][1], true, Senders.selected(enabled, i)));
        }
        for (String name : custom()) out.add(new Row(name, name, false, listHas(enabled, name)));
        return out;
    }

    static String enabledNames(List<Row> rows) {
        StringBuilder out = new StringBuilder();
        for (Row row : rows) {
            if (!row.on) continue;
            for (String name : row.names.split(",")) {
                String one = name.trim();
                if (!one.isEmpty() && !listHas(out.toString(), one)) {
                    if (out.length() > 0) out.append(", ");
                    out.append(one);
                }
            }
        }
        return out.toString();
    }

    static Row find(List<Row> rows, String label) {
        for (Row r : rows) if (r.label.equalsIgnoreCase(label)) return r;
        return null;
    }

    public static void main(String[] a) {
        // An existing phone: MPESA was typed in by its owner, and M-Pesa is no longer
        // a built-in network. It must survive the upgrade, switched on.
        store.clear();
        store.put("senders", "MPESA");
        List<Row> rows = rows();
        Row mpesa = find(rows, "MPESA");
        yes("a typed name survives the upgrade", mpesa != null);
        yes("and stays switched on", mpesa != null && mpesa.on);
        no("it is not treated as built in", mpesa != null && mpesa.builtIn);
        is("switching nothing else on keeps only it", enabledNames(rows), "MPESA");

        // A phone on the shipped defaults: its networks come back ticked.
        store.clear();
        store.put("senders", "MobileMoney, MTN MoMo, MTNMobMoney, MoMo, AirtelMoney, TelecelCash, VodaCash, ATMoney");
        rows = rows();
        yes("MTN is ticked", find(rows, "MTN MoMo").on);
        yes("Airtel is ticked", find(rows, "Airtel Money").on);
        yes("Wave is present", find(rows, "Wave") != null);
        no("Wave is not ticked", find(rows, "Wave").on);
        is("nothing was invented as typed", get("customSenders"), "");

        // Removing a built-in network keeps it off the list until it is restored.
        store.clear();
        store.put("senders", "MobileMoney");
        store.put("customSenders", "");
        store.put("removedNetworks", "Wave, Moov Money");
        rows = rows();
        yes("a removed network is gone", find(rows, "Wave") == null);
        yes("so is the other one", find(rows, "Moov Money") == null);
        yes("the rest remain", find(rows, "MTN MoMo") != null);
        store.put("removedNetworks", "");
        yes("restoring brings them back", find(rows(), "Wave") != null);

        // Ticking a row switches on every sender name behind it.
        store.clear();
        store.put("senders", "");
        store.put("customSenders", "MyBank");
        rows = rows();
        find(rows, "MTN MoMo").on = true;
        find(rows, "MyBank").on = true;
        String saved = enabledNames(rows);
        for (String name : Senders.namesFor(0)) yes("MTN's " + name + " is stored", listHas(saved, name));
        yes("the typed name is stored", listHas(saved, "MyBank"));
        no("an unticked network is not stored", listHas(saved, "Wave"));

        // What is stored is what the phone matches against, so it must read back the same.
        store.put("senders", saved);
        rows = rows();
        yes("MTN reads back ticked", find(rows, "MTN MoMo").on);
        yes("the typed name reads back ticked", find(rows, "MyBank").on);
        no("Wave reads back unticked", find(rows, "Wave").on);

        // Nothing ticked stores nothing, which the screen refuses before saving.
        store.put("senders", "");
        rows = rows();
        for (Row r : rows) r.on = false;
        is("nothing ticked is empty", enabledNames(rows), "");

        System.out.println(failures == 0
            ? "PASS: the sender list shows, stores and reloads the same names, and an upgrade keeps them."
            : failures + " check(s) failed.");
        if (failures > 0) System.exit(1);
    }
}
