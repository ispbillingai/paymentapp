package com.ispledger.paymentapp;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Brings the listener back after the phone restarts, after an update, and on the backup alarm. */
public class WakeReceiver extends BroadcastReceiver {
    static final String ACTION_ALARM = "com.ispledger.paymentapp.ALARM";

    @Override
    public void onReceive(final Context context, Intent intent) {
        if (!Prefs.configured(context)) {
            return;
        }
        ListenerService.ensureRunning(context);
        ListenerService.scheduleBackupAlarm(context);
        if (ACTION_ALARM.equals(intent.getAction())) {
            final PendingResult result = goAsync();
            new Thread(new Runnable() {
                @Override
                public void run() {
                    try {
                        Uploader.flush(context);
                        Uploader.ping(context);
                    } finally {
                        result.finish();
                    }
                }
            }).start();
        }
    }
}
