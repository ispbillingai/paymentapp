package com.ispledger.paymentapp;

import android.content.Context;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.List;

/** Talks to the dashboard. Every call here blocks, so callers use a background thread. */
final class Uploader {
    private Uploader() {}

    private static final Object LOCK = new Object();

    /** "" when the dashboard answered and accepted the key, otherwise a sentence for the owner. */
    static String ping(Context c) {
        try {
            JSONObject body = new JSONObject();
            body.put("ping", 1);
            body.put("version", BuildConfig.VERSION_NAME);
            int code = post(c, body);
            return explain(c, code);
        } catch (Exception e) {
            if (e instanceof java.net.SocketTimeoutException) return problem(c, "Connection timed out. Check internet access and try again.");
            if (e instanceof javax.net.ssl.SSLException) return problem(c, "The gateway secure connection could not be verified. Check the phone date and gateway address.");
            return problem(c, "Could not reach the payment service. Check this phone's internet.");
        }
    }

    /** Sends everything that is waiting, oldest first. Stops at the first failure and keeps the rest. */
    static void flush(Context c) {
        if (!Prefs.configured(c) || Prefs.paused(c)) {
            return;
        }
        synchronized (LOCK) {
            Outbox box = Outbox.get(c);
            List<Outbox.Item> items = box.pending(25);
            for (Outbox.Item i : items) {
                if (Prefs.paused(c)) return;
                try {
                    JSONObject body = new JSONObject();
                    body.put("from", i.sender);
                    body.put("text", i.body);
                    body.put("sentStamp", i.sentAt);
                    body.put("sim", i.sim);
                    body.put("version", BuildConfig.VERSION_NAME);
                    int code = post(c, body);
                    if (code == 200) {
                        box.delivered(i.id);
                        box.note("Payment message from " + i.sender + " delivered");
                        Prefs.contactOk(c);
                    } else {
                        box.failed(i.id);
                        box.note(explain(c, code));
                        return;
                    }
                } catch (Exception e) {
                    box.failed(i.id);
                    problem(c, "No connection. The message is kept and will be sent when the internet is back.");
                    return;
                }
            }
        }
    }

    private static String explain(Context c, int code) {
        if (code == 200) {
            Prefs.contactOk(c);
            return "";
        }
        if (code >= 300 && code < 400) return problem(c, "The gateway redirected this request. Use the direct HTTPS device endpoint.");
        if (code == 502) return problem(c, "The gateway did not return a valid acknowledgement. Check the endpoint address.");
        if (code == 422) return problem(c, "The gateway did not accept the message. Pending messages are kept on this phone.");
        if (code == 401) {
            return problem(c, "This key was not accepted. Create a new key on the Direct Number page of your dashboard and paste it here.");
        }
        if (code == 403) {
            return problem(c, "Direct Number is not available for this dashboard.");
        }
        return problem(c, "The payment service answered with an error (" + code + "). It will be tried again.");
    }

    private static String problem(Context c, String why) {
        Prefs.contactFailed(c, why);
        return why;
    }

    private static int post(Context c, JSONObject body) throws Exception {
        HttpURLConnection con = (HttpURLConnection) new URL(Prefs.url(c)).openConnection();
        try {
            con.setConnectTimeout(8000);
            con.setReadTimeout(10000);
            con.setInstanceFollowRedirects(false);
            con.setRequestMethod("POST");
            con.setDoOutput(true);
            con.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            con.setRequestProperty("X-Device-Key", Prefs.key(c));
            try (OutputStream os = con.getOutputStream()) {
                os.write(body.toString().getBytes(StandardCharsets.UTF_8));
            }
            int code = con.getResponseCode();
            String response = drain(code >= 400 ? con.getErrorStream() : con.getInputStream());
            if (code == 200) {
                try { if (!new JSONObject(response).optBoolean("ok", false)) return 422; }
                catch (Exception invalid) { return 502; }
            }
            return code;
        } finally {
            con.disconnect();
        }
    }

    private static String drain(InputStream in) {
        if (in == null) {
            return "";
        }
        try (InputStream s = in; ByteArrayOutputStream sink = new ByteArrayOutputStream()) {
            byte[] buf = new byte[1024];
            int n;
            while ((n = s.read(buf)) > 0 && sink.size() < 8192) {
                sink.write(buf, 0, n);
            }
            return sink.toString("UTF-8");
        } catch (Exception ignored) { return ""; }
    }
}
