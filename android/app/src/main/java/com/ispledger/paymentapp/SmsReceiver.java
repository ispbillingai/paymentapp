package com.ispledger.paymentapp;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.provider.Telephony;
import android.telephony.SmsMessage;

import java.util.LinkedHashMap;
import java.util.Map;

/**
 * Called by Android at the moment a text message lands. A long message
 * arrives in parts, so the parts from one sender are joined first. Only a
 * message from an allowed mobile money sender goes any further.
 */
public class SmsReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(final Context context, Intent intent) {
        if (!Telephony.Sms.Intents.SMS_RECEIVED_ACTION.equals(intent.getAction())) {
            return;
        }
        SmsMessage[] parts = Telephony.Sms.Intents.getMessagesFromIntent(intent);
        if (parts == null || parts.length == 0) {
            return;
        }
        Map<String, StringBuilder> bySender = new LinkedHashMap<>();
        long sentAt = System.currentTimeMillis();
        for (SmsMessage p : parts) {
            if (p == null) {
                continue;
            }
            String from = p.getDisplayOriginatingAddress();
            if (from == null) {
                continue;
            }
            StringBuilder sb = bySender.get(from);
            if (sb == null) {
                sb = new StringBuilder();
                bySender.put(from, sb);
            }
            sb.append(p.getDisplayMessageBody());
            if (p.getTimestampMillis() > 0) {
                sentAt = p.getTimestampMillis();
            }
        }
        int sim = intent.getIntExtra("slot", intent.getIntExtra("simSlot", -1));

        boolean queued = false;
        for (Map.Entry<String, StringBuilder> e : bySender.entrySet()) {
            if (Senders.allowed(context, e.getKey())) {
                Outbox.get(context).add(e.getKey(), e.getValue().toString(), sentAt, sim);
                queued = true;
            }
        }
        if (!queued) {
            return; // a private message: nothing is kept, nothing is sent
        }

        ListenerService.ensureRunning(context);
        final PendingResult result = goAsync();
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    Uploader.flush(context);
                } finally {
                    result.finish();
                }
            }
        }).start();
    }
}
