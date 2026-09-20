package com.ispledger.paymentapp;

import android.app.AlarmManager;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.os.Build;
import android.os.IBinder;
import android.os.SystemClock;

import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;

/**
 * Keeps the app alive with a visible notification, so phone makers' battery
 * savers do not silently stop it. Every minute it retries anything still
 * waiting; every five minutes it tells the dashboard the phone is alive, so
 * the owner is warned when it is not.
 */
public class ListenerService extends Service {
    private static final String CHANNEL = "listener";
    private ScheduledExecutorService timer;

    static void ensureRunning(Context c) {
        if (!Prefs.configured(c)) {
            return;
        }
        try {
            Intent i = new Intent(c, ListenerService.class);
            if (Build.VERSION.SDK_INT >= 26) {
                c.startForegroundService(i);
            } else {
                c.startService(i);
            }
        } catch (Exception ignored) {
            // Android may refuse a background start. The backup alarm and the
            // next payment message both try again.
        }
    }

    /** A second way back in, for phones that stop the service while they doze. */
    static void scheduleBackupAlarm(Context c) {
        AlarmManager am = (AlarmManager) c.getSystemService(Context.ALARM_SERVICE);
        if (am == null) {
            return;
        }
        Intent i = new Intent(c, WakeReceiver.class).setAction(WakeReceiver.ACTION_ALARM);
        int flags = PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= 23 ? PendingIntent.FLAG_IMMUTABLE : 0);
        PendingIntent pi = PendingIntent.getBroadcast(c, 1, i, flags);
        long at = SystemClock.elapsedRealtime() + 10 * 60 * 1000;
        if (Build.VERSION.SDK_INT >= 23) {
            am.setAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pi);
        } else {
            am.set(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pi);
        }
    }

    @Override
    public void onCreate() {
        super.onCreate();
        if (Build.VERSION.SDK_INT >= 26) {
            NotificationChannel ch = new NotificationChannel(CHANNEL, getString(R.string.channel_name), NotificationManager.IMPORTANCE_LOW);
            ch.setShowBadge(false);
            NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
            if (nm != null) {
                nm.createNotificationChannel(ch);
            }
        }
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        Notification n = buildNotification();
        if (Build.VERSION.SDK_INT >= 34) {
            startForeground(1, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE);
        } else {
            startForeground(1, n);
        }
        if (timer == null) {
            timer = Executors.newSingleThreadScheduledExecutor();
            timer.scheduleWithFixedDelay(new Runnable() {
                @Override
                public void run() {
                    try {
                        if (Outbox.get(ListenerService.this).waiting() > 0) {
                            Uploader.flush(ListenerService.this);
                        }
                    } catch (Exception ignored) {
                    }
                }
            }, 5, 60, TimeUnit.SECONDS);
            timer.scheduleWithFixedDelay(new Runnable() {
                @Override
                public void run() {
                    try {
                        Uploader.ping(ListenerService.this);
                    } catch (Exception ignored) {
                    }
                }
            }, 10, 300, TimeUnit.SECONDS);
        }
        scheduleBackupAlarm(this);
        return START_STICKY;
    }

    private Notification buildNotification() {
        Intent open = new Intent(this, MainActivity.class);
        int flags = PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= 23 ? PendingIntent.FLAG_IMMUTABLE : 0);
        PendingIntent pi = PendingIntent.getActivity(this, 0, open, flags);
        Notification.Builder b = Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(this, CHANNEL) : new Notification.Builder(this);
        return b.setSmallIcon(R.drawable.ic_stat)
                .setContentTitle(getString(R.string.notif_title))
                .setContentText(getString(R.string.notif_text))
                .setContentIntent(pi)
                .setOngoing(true)
                .build();
    }

    @Override
    public void onDestroy() {
        if (timer != null) {
            timer.shutdownNow();
            timer = null;
        }
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
