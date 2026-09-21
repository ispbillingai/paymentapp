package com.ispledger.paymentapp;

import android.content.Context;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

/**
 * Pairing this phone with an account key.
 *
 * The owner has one key, the account key from their dashboard, and pastes it here
 * rather than going to create a device key first. This phone registers itself with
 * it, receives a key of its own, and keeps only that. The account key is never
 * written to this phone's storage.
 *
 * That difference is not cosmetic. The account key can read every payment the
 * business has ever taken, revoke its listeners, and change where payment events
 * are delivered. This phone's own key can do exactly one thing: report a payment
 * message. So a phone that is lost, sold or stolen costs the owner one listener,
 * which they revoke on its own, instead of the account.
 *
 * Every call here blocks, so callers use a background thread.
 */
final class Enrol {
    private Enrol() {}

    /** An account key, as the dashboard issues it. */
    static boolean looksLikeAccountKey(String value) {
        return value != null && value.startsWith("sk_live_");
    }

    /** A key belonging to one phone: what this app actually runs on. */
    static boolean looksLikeDeviceKey(String value) {
        return value != null && value.matches("[a-f0-9]{40}");
    }

    /** What the owner was given, told apart by its shape. */
    static String describe(String value) {
        if (looksLikeDeviceKey(value)) return "device";
        if (looksLikeAccountKey(value)) return "account";
        return "";
    }

    /**
     * The gateway's address, taken from wherever payment messages are sent, so a
     * test deployment pairs against that same test deployment.
     */
    static String baseUrl(Context c) {
        String url = Prefs.url(c);
        int at = url.indexOf("/v1/");
        return at > 0 ? url.substring(0, at) : url;
    }

    /** What the gateway said when this phone registered. */
    static final class Result {
        String deviceKey = "";
        String label = "";
        String problem = "";

        boolean ok() {
            return Enrol.looksLikeDeviceKey(deviceKey);
        }
    }

    /**
     * Registers this phone and returns its own key. The account key is used for this
     * one request and then goes out of scope; nothing stores it.
     */
    static Result pair(Context c, String accountKey, String receivingNumber, String receivingName, String senders, String label) {
        Result out = new Result();
        HttpURLConnection con = null;
        try {
            JSONObject body = new JSONObject();
            // The phone does not know the merchant's country, so it never guesses a
            // network code. It names the senders it was told to listen for, which is
            // the same list it filters messages against.
            body.put("provider", "other");
            body.put("extra_senders", senders);
            body.put("receiving_number", receivingNumber);
            body.put("receiving_name", receivingName);
            body.put("label", label);

            con = (HttpURLConnection) new URL(baseUrl(c) + "/v1/devices").openConnection();
            con.setConnectTimeout(10000);
            con.setReadTimeout(20000);
            con.setInstanceFollowRedirects(false);
            con.setRequestMethod("POST");
            con.setDoOutput(true);
            con.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            con.setRequestProperty("Accept", "application/json");
            con.setRequestProperty("Authorization", "Bearer " + accountKey);
            try (OutputStream os = con.getOutputStream()) {
                os.write(body.toString().getBytes(StandardCharsets.UTF_8));
            }
            int code = con.getResponseCode();
            String response = read(code >= 400 ? con.getErrorStream() : con.getInputStream());
            if (code == 401) {
                out.problem = "That account key was not accepted. Copy it again from your dashboard, or create a new one.";
                return out;
            }
            JSONObject parsed;
            try {
                parsed = new JSONObject(response);
            } catch (Exception notJson) {
                out.problem = "The payment service gave an unexpected answer (" + code + "). Try again shortly.";
                return out;
            }
            if (code != 200 && code != 201) {
                JSONObject error = parsed.optJSONObject("error");
                out.problem = error != null && error.optString("message", "").length() > 0
                        ? error.optString("message")
                        : "This phone could not be paired (" + code + ").";
                return out;
            }
            out.deviceKey = parsed.optString("device_key", "");
            JSONObject device = parsed.optJSONObject("device");
            out.label = device != null ? device.optString("label", "") : "";
            if (!out.ok()) {
                out.problem = "The payment service did not return a key for this phone. Try again shortly.";
            }
            return out;
        } catch (Exception e) {
            out.problem = "Could not reach the payment service. Check this phone's internet and try again.";
            return out;
        } finally {
            if (con != null) con.disconnect();
        }
    }

    private static String read(InputStream in) {
        if (in == null) {
            return "";
        }
        try (InputStream s = in; ByteArrayOutputStream sink = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[1024];
            int n;
            while ((n = s.read(buffer)) > 0 && sink.size() < 16384) {
                sink.write(buffer, 0, n);
            }
            return sink.toString("UTF-8");
        } catch (Exception e) {
            return "";
        }
    }
}
