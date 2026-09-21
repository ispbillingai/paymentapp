package com.ispledger.paymentapp;

import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;

import org.json.JSONObject;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.security.MessageDigest;

/**
 * Updating the app from inside the app, so a listener phone never has to be
 * collected or talked through a download.
 *
 * What is downloaded is checked against the checksum the service published for
 * the file it is serving, and anything that does not match is deleted without
 * being opened. Android then refuses to install a build that is not signed with
 * the same key as the app already on the phone, so a replacement can only come
 * from whoever holds that key.
 *
 * Nothing installs on its own: the owner is asked, and Android asks again.
 * Every call here blocks, so callers use a background thread.
 */
final class Updater {
    private Updater() {}

    /**
     * Where the newest build is described, under whichever gateway this phone is
     * pointed at, so a test deployment offers its own build rather than the live
     * one. Kept off /v1 so it answers even if the gateway database is down.
     */
    private static String manifest(Context c) {
        return Enrol.baseUrl(c) + "/app/version.json";
    }
    private static final long MAX_APK = 60L * 1024 * 1024;
    private static final String FILE = "update.apk";

    /** What the service says the newest build is. */
    static final class Release {
        int versionCode;
        String versionName = "";
        String notes = "";
        String url = "";
        String sha256 = "";
        long size;

        boolean newerThanInstalled() {
            return versionCode > BuildConfig.VERSION_CODE;
        }
    }

    /** The newest build, or null if it could not be asked for. */
    static Release check(Context c) {
        HttpURLConnection con = null;
        try {
            con = (HttpURLConnection) new URL(manifest(c)).openConnection();
            con.setConnectTimeout(8000);
            con.setReadTimeout(10000);
            con.setUseCaches(false);
            con.setRequestProperty("Cache-Control", "no-cache");
            con.setRequestProperty("Accept", "application/json");
            if (con.getResponseCode() != 200) {
                return null;
            }
            JSONObject json = new JSONObject(readText(con.getInputStream(), 8192));
            Release r = new Release();
            r.versionCode = json.optInt("version_code", 0);
            r.versionName = json.optString("version_name", "");
            r.notes = json.optString("notes", "");
            r.url = json.optString("url", "");
            r.sha256 = json.optString("sha256", "").toLowerCase();
            r.size = json.optLong("size", 0);
            // Without a checksum there is nothing to verify against, so it is not offered.
            if (r.versionCode <= 0 || !r.sha256.matches("[a-f0-9]{64}") || !r.url.startsWith("https://")) {
                return null;
            }
            return r;
        } catch (Exception e) {
            return null;
        } finally {
            if (con != null) con.disconnect();
        }
    }

    /** Reports how far a download has got, so the screen can say something true. */
    interface Progress {
        void at(int percent);
    }

    /**
     * Downloads the build and checks it. Returns the file only when its checksum is
     * the one that was published; otherwise the partial file is deleted and the
     * reason is thrown, so a bad download can never be handed to the installer.
     */
    static File download(Context c, Release release, Progress progress) throws Exception {
        File file = new File(c.getCacheDir(), FILE);
        if (file.exists() && !file.delete()) {
            throw new Exception("Could not clear space for the update. Free some storage and try again.");
        }
        HttpURLConnection con = (HttpURLConnection) new URL(release.url).openConnection();
        try {
            con.setConnectTimeout(20000);
            con.setReadTimeout(60000);
            con.setInstanceFollowRedirects(true);
            if (con.getResponseCode() != 200) {
                throw new Exception("The update could not be downloaded (" + con.getResponseCode() + "). Try again shortly.");
            }
            long expected = release.size > 0 ? release.size : con.getContentLength();
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            long total = 0;
            try (InputStream in = con.getInputStream(); OutputStream out = new FileOutputStream(file)) {
                byte[] buffer = new byte[16384];
                int n;
                while ((n = in.read(buffer)) > 0) {
                    total += n;
                    if (total > MAX_APK) {
                        throw new Exception("The update is larger than expected and was not kept.");
                    }
                    digest.update(buffer, 0, n);
                    out.write(buffer, 0, n);
                    if (progress != null && expected > 0) {
                        progress.at((int) Math.min(99, total * 100 / expected));
                    }
                }
            }
            String got = hex(digest.digest());
            if (!got.equals(release.sha256)) {
                file.delete();
                throw new Exception("The downloaded update did not match its checksum and was discarded. Try again.");
            }
            if (release.size > 0 && total != release.size) {
                file.delete();
                throw new Exception("The download was incomplete and was discarded. Try again.");
            }
            if (progress != null) progress.at(100);
            return file;
        } catch (Exception e) {
            file.delete();
            throw e;
        } finally {
            con.disconnect();
        }
    }

    /**
     * Hands the verified file to Android's installer. The owner confirms there, and
     * Android checks the signature against the installed app before replacing it.
     */
    static void install(Context c, File file) throws Exception {
        Uri uri = new Uri.Builder().scheme("content")
                .authority(c.getPackageName() + ".updates")
                .appendPath(file.getName())
                .build();
        Intent intent = new Intent(Intent.ACTION_VIEW);
        intent.setDataAndType(uri, "application/vnd.android.package-archive");
        intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
        c.startActivity(intent);
    }

    /**
     * Whether this phone will let the app install an update at all. On Android 8 and
     * later the owner grants this once, in settings; until then there is no point
     * offering to install and the screen says so instead.
     */
    static boolean canInstall(Context c) {
        if (Build.VERSION.SDK_INT < 26) {
            return true;
        }
        try {
            return c.getPackageManager().canRequestPackageInstalls();
        } catch (Exception e) {
            return false;
        }
    }

    /** Takes the owner to the setting that allows this app to install an update. */
    static Intent allowInstallSettings(Context c) {
        if (Build.VERSION.SDK_INT < 26) {
            return null;
        }
        return new Intent(android.provider.Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                Uri.parse("package:" + c.getPackageName()));
    }

    private static String readText(InputStream in, int limit) throws Exception {
        try (InputStream s = in; java.io.ByteArrayOutputStream sink = new java.io.ByteArrayOutputStream()) {
            byte[] buffer = new byte[1024];
            int n;
            while ((n = s.read(buffer)) > 0 && sink.size() < limit) {
                sink.write(buffer, 0, n);
            }
            return sink.toString("UTF-8");
        }
    }

    private static String hex(byte[] bytes) {
        StringBuilder out = new StringBuilder(bytes.length * 2);
        for (byte b : bytes) {
            out.append(Character.forDigit((b >> 4) & 0xf, 16)).append(Character.forDigit(b & 0xf, 16));
        }
        return out.toString();
    }
}
