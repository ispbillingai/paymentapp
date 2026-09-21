/**
 * One phone number, however either side writes it down.
 *
 * Run by android/tests/run.php, against the real Senders.java.
 *
 * A message arrives from +254714430753 while the owner types 0714430753. Those
 * are the same phone and different text, so matching them as text gives a
 * sender list that looks correct and quietly forwards nothing: the owner sees
 * an app that is plainly working and a dashboard with no payments in it, which
 * is the hardest kind of fault to find from the outside.
 */
public class NumberSenderTest {

    private static int failures = 0;

    private static void check(String what, boolean got, boolean want) {
        if (got != want) {
            failures++;
            System.out.println("FAIL " + what + "\n  got:  " + got + "\n  want: " + want);
        }
    }

    public static void main(String[] args) {
        // The owner types the number the way he says it out loud.
        String list = "MPESA,0714430753";
        check("the number as typed", Senders.matches(list, "0714430753"), true);
        check("the same number with a country code", Senders.matches(list, "254714430753"), true);
        check("and with a plus in front", Senders.matches(list, "+254714430753"), true);
        check("and spaced out", Senders.matches(list, "+254 714 430 753"), true);

        // Or the international way instead. Either is one number.
        String international = "+254714430753";
        check("listed international, arriving local", Senders.matches(international, "0714430753"), true);
        check("listed international, arriving the same", Senders.matches(international, "+254714430753"), true);

        // Someone else's number is still someone else's.
        check("a different number", Senders.matches(list, "+254714430754"), false);
        check("too few digits to be that number", Senders.matches(list, "430753"), false);

        // Names are matched as names, which is what they are.
        check("a network sender name", Senders.matches(list, "MPESA"), true);
        check("a sender name written with a space", Senders.matches("MTN MoMo", "mtn momo"), true);
        check("names that merely end alike", Senders.matches("PAYBILL1", "PAYBILL2"), false);
        check("a sender nobody listed", Senders.matches(list, "AIRTELMONEY"), false);

        // Nothing listed, nothing forwarded: the promise the app makes.
        check("an empty list forwards nothing", Senders.matches("", "MPESA"), false);
        check("an empty list forwards no number either", Senders.matches("", "0714430753"), false);
        check("no list at all forwards nothing", Senders.matches(null, "MPESA"), false);

        System.out.println(failures == 0
            ? "PASS: one number matches however it is written, and nothing unlisted does."
            : failures + " check(s) failed.");
        System.exit(failures == 0 ? 0 : 1);
    }
}
