package com.ispledger.paymentapp;

import android.content.Context;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Date;
import java.util.List;
import java.util.Locale;
import java.util.TimeZone;

/**
 * What time this phone says a thing happened.
 *
 * A listener phone is often a spare handset that nobody has set up properly, so
 * its clock can sit in the wrong zone and every time on screen is then hours out.
 * That matters here: the times in the activity log are what an owner compares
 * against a customer's "I paid at two o'clock". So the owner can name the zone
 * themselves, rather than being told to fix Android's settings on a phone they
 * may not be holding.
 *
 * Only what is DISPLAYED moves. What is sent to the gateway is the moment the
 * message arrived, in milliseconds since the epoch, which carries no zone at all,
 * so a wrong choice here can never misdate a payment.
 */
final class Times {
    private Times() {}

    /** Empty means whatever the phone itself is set to. */
    static TimeZone zone(Context c) {
        String chosen = Prefs.timezone(c);
        if (chosen.isEmpty()) {
            return TimeZone.getDefault();
        }
        TimeZone zone = TimeZone.getTimeZone(chosen);
        // getTimeZone answers GMT for anything it does not know, so an id that has
        // gone away falls back to the phone rather than silently shifting to GMT.
        if ("GMT".equals(zone.getID()) && !chosen.startsWith("GMT") && !"UTC".equals(chosen)) {
            return TimeZone.getDefault();
        }
        return zone;
    }

    /** A formatter already set to the chosen zone. */
    static SimpleDateFormat formatter(Context c, String pattern) {
        SimpleDateFormat format = new SimpleDateFormat(pattern, Locale.getDefault());
        format.setTimeZone(zone(c));
        return format;
    }

    static String format(Context c, String pattern, long at) {
        return formatter(c, pattern).format(new Date(at));
    }

    /** How the chosen zone reads on screen, e.g. "Nairobi (GMT+03:00)". */
    static String label(String id) {
        TimeZone zone = TimeZone.getTimeZone(id);
        int minutes = zone.getOffset(System.currentTimeMillis()) / 60000;
        String sign = minutes < 0 ? "-" : "+";
        minutes = Math.abs(minutes);
        String city = id.contains("/") ? id.substring(id.lastIndexOf('/') + 1).replace('_', ' ') : id;
        return String.format(Locale.US, "%s (GMT%s%02d:%02d)", city, sign, minutes / 60, minutes % 60);
    }

    /**
     * The zones offered. Africa in full, since that is where these phones are, plus
     * a handful of others and whatever this phone is already set to, so nobody is
     * stuck scrolling six hundred entries to find their own.
     */
    static List<String> choices() {
        List<String> out = new ArrayList<>();
        String[] all = TimeZone.getAvailableIDs();
        Arrays.sort(all);
        for (String id : all) {
            if (id.startsWith("Africa/")) {
                out.add(id);
            }
        }
        for (String id : new String[]{"Europe/London", "Europe/Paris", "Asia/Dubai", "Asia/Karachi", "Asia/Kolkata", "Asia/Dhaka", "UTC"}) {
            if (!out.contains(id)) {
                out.add(id);
            }
        }
        String own = TimeZone.getDefault().getID();
        if (!out.contains(own)) {
            out.add(own);
        }
        return out;
    }
}
