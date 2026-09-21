/** Checks the tick boxes and the saved sender list stay faithful to each other. */
public class SenderTest {
    static int failures = 0;
    static void is(String what, Object got, Object want) {
        if (!String.valueOf(got).equals(String.valueOf(want))) {
            System.out.println("FAIL " + what + "\n  got:  " + got + "\n  want: " + want);
            failures++;
        }
    }
    static void yes(String what, boolean got) { if (!got) { System.out.println("FAIL " + what); failures++; } }
    static void no(String what, boolean got) { if (got) { System.out.println("FAIL " + what); failures++; } }

    public static void main(String[] a) {
        int n = Senders.NETWORKS.length;
        // The list every existing install already has must tick the networks it covers.
        String shipped = "MobileMoney, MTN MoMo, MTNMobMoney, MoMo, AirtelMoney, Airtel Money, TelecelCash, VodaCash, T-Cash, ATMoney";
        yes("MTN ticked from an existing install", Senders.selected(shipped, 0));
        yes("Airtel ticked from an existing install", Senders.selected(shipped, 1));
        yes("Telecel ticked from an existing install", Senders.selected(shipped, 2));
        yes("Vodafone ticked from an existing install", Senders.selected(shipped, 3));
        yes("AT ticked from an existing install", Senders.selected(shipped, 4));
        no("M-Pesa not ticked from an existing install", Senders.selected(shipped, 5));
        is("nothing shows as typed for an existing install", Senders.extras(shipped), "");

        // A name the owner typed survives a round trip and is never mistaken for a network.
        is("a typed name is reported as typed", Senders.extras(shipped + ", ZamtelKwacha"), "ZamtelKwacha");
        boolean[] five = new boolean[n];
        for (int i = 0; i < 5; i++) five[i] = true;
        String rebuilt = Senders.compose(five, "ZamtelKwacha");
        is("the typed name survives rebuilding", Senders.extras(rebuilt), "ZamtelKwacha");
        for (int i = 0; i < 5; i++) yes("network " + i + " still ticked after rebuilding", Senders.selected(rebuilt, i));

        // Unticking a network must actually drop its sender names.
        boolean[] justMtn = new boolean[n];
        justMtn[0] = true;
        String only = Senders.compose(justMtn, "");
        yes("MTN kept", Senders.selected(only, 0));
        no("Airtel dropped", Senders.selected(only, 1));
        no("Telecel dropped", Senders.selected(only, 2));
        no("Vodafone dropped", Senders.selected(only, 3));
        is("no stray typed names", Senders.extras(only), "");

        // Nothing appears twice, however it was entered.
        String dupes = Senders.compose(justMtn, "MoMo, momo, MOMO, Wave, wave, M O M O");
        java.util.Set<String> seen = new java.util.HashSet<>();
        for (String part : dupes.split(",")) {
            String norm = Senders.normalise(part);
            if (!norm.isEmpty() && !seen.add(norm)) { System.out.println("FAIL duplicate sender kept: " + part.trim()); failures++; }
        }
        yes("a typed name already covered by a network is not added twice", seen.contains("momo"));
        yes("a genuinely new typed name is kept", seen.contains("wave"));

        // Ticking and typing nothing is empty, which the screen refuses before saving.
        is("empty means empty", Senders.compose(new boolean[n], ""), "");
        // Blank and whitespace entries never become senders.
        is("blank entries are ignored", Senders.compose(new boolean[n], " ,  , ,"), "");
        is("blanks in a saved list are ignored", Senders.extras(", ,  ,"), "");

        // A sender name must belong to only one network, or ticking one would tick another.
        java.util.Map<String, Integer> owner = new java.util.HashMap<>();
        for (int i = 0; i < n; i++) {
            yes("network " + i + " has a label", !Senders.NETWORKS[i][0].trim().isEmpty());
            String[] names = Senders.namesFor(i);
            yes(Senders.NETWORKS[i][0] + " lists at least one sender", names.length > 0);
            for (String name : names) {
                String norm = Senders.normalise(name);
                yes(Senders.NETWORKS[i][0] + " has a usable sender name", !norm.isEmpty());
                Integer had = owner.put(norm, i);
                if (had != null && had != i) {
                    System.out.println("FAIL '" + name + "' is claimed by both " + Senders.NETWORKS[had][0] + " and " + Senders.NETWORKS[i][0]);
                    failures++;
                }
            }
        }

        // Every network can be ticked on its own and read back correctly.
        for (int i = 0; i < n; i++) {
            boolean[] one = new boolean[n];
            one[i] = true;
            String saved = Senders.compose(one, "");
            yes(Senders.NETWORKS[i][0] + " reads back as ticked", Senders.selected(saved, i));
            for (int j = 0; j < n; j++) {
                if (j != i) no(Senders.NETWORKS[j][0] + " must not read as ticked when only " + Senders.NETWORKS[i][0] + " is", Senders.selected(saved, j));
            }
        }

        System.out.println(failures == 0
            ? "PASS: sender tick boxes and the saved list agree in both directions."
            : failures + " check(s) failed.");
        if (failures > 0) System.exit(1);
    }
}
