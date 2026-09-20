package com.ispledger.paymentapp;

import android.Manifest;
import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.PowerManager;
import android.provider.Settings;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.TextView;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

/** The only screen: set up once, then a plain status the owner can glance at. */
public class MainActivity extends Activity {
    private EditText url, key, senders;
    private TextView status, activity;
    private Button save, allowSms, allowBattery;
    private final Handler ui = new Handler(Looper.getMainLooper());
    private final Runnable tick = new Runnable() {
        @Override
        public void run() {
            render();
            ui.postDelayed(this, 3000);
        }
    };

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        setContentView(R.layout.activity_main);
        url = findViewById(R.id.url);
        key = findViewById(R.id.key);
        senders = findViewById(R.id.senders);
        status = findViewById(R.id.status);
        activity = findViewById(R.id.activity);
        save = findViewById(R.id.save);
        allowSms = findViewById(R.id.allow_sms);
        allowBattery = findViewById(R.id.allow_battery);

        url.setText(Prefs.url(this));
        key.setText(Prefs.key(this));
        senders.setText(Prefs.senders(this));

        save.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                saveAndTest();
            }
        });
        allowSms.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                askPermissions();
            }
        });
        allowBattery.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                askBattery();
            }
        });
    }

    @Override
    protected void onResume() {
        super.onResume();
        ListenerService.ensureRunning(this);
        ui.post(tick);
    }

    @Override
    protected void onPause() {
        ui.removeCallbacks(tick);
        super.onPause();
    }

    private void saveAndTest() {
        String u = url.getText().toString().trim();
        String k = key.getText().toString().trim();
        if (!u.startsWith("https://") && !(BuildConfig.DEBUG && u.startsWith("http://"))) {
            status.setText(R.string.err_url);
            return;
        }
        if (!k.matches("[a-f0-9]{40}")) {
            status.setText(R.string.err_key);
            return;
        }
        Prefs.save(this, u, k, senders.getText().toString());
        status.setText(R.string.testing);
        save.setEnabled(false);
        final Context app = getApplicationContext();
        new Thread(new Runnable() {
            @Override
            public void run() {
                final String problem = Uploader.ping(app);
                if (problem.isEmpty()) {
                    Outbox.get(app).note("Connected to your dashboard");
                    Uploader.flush(app);
                }
                ui.post(new Runnable() {
                    @Override
                    public void run() {
                        save.setEnabled(true);
                        ListenerService.ensureRunning(app);
                        render();
                    }
                });
            }
        }).start();
    }

    private boolean hasSms() {
        return Build.VERSION.SDK_INT < 23 || checkSelfPermission(Manifest.permission.RECEIVE_SMS) == PackageManager.PERMISSION_GRANTED;
    }

    private boolean batteryFree() {
        if (Build.VERSION.SDK_INT < 23) {
            return true;
        }
        PowerManager pm = (PowerManager) getSystemService(POWER_SERVICE);
        return pm != null && pm.isIgnoringBatteryOptimizations(getPackageName());
    }

    private void askPermissions() {
        if (Build.VERSION.SDK_INT < 23) {
            return;
        }
        if (Build.VERSION.SDK_INT >= 33) {
            requestPermissions(new String[]{Manifest.permission.RECEIVE_SMS, Manifest.permission.POST_NOTIFICATIONS}, 1);
        } else {
            requestPermissions(new String[]{Manifest.permission.RECEIVE_SMS}, 1);
        }
    }

    private void askBattery() {
        if (Build.VERSION.SDK_INT < 23) {
            return;
        }
        try {
            startActivity(new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:" + getPackageName())));
        } catch (Exception e) {
            startActivity(new Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS));
        }
    }

    @Override
    public void onRequestPermissionsResult(int code, String[] permissions, int[] results) {
        render();
    }

    private void render() {
        allowSms.setVisibility(hasSms() ? View.GONE : View.VISIBLE);
        allowBattery.setVisibility(batteryFree() ? View.GONE : View.VISIBLE);

        SimpleDateFormat f = new SimpleDateFormat("d MMM HH:mm", Locale.getDefault());
        StringBuilder s = new StringBuilder();
        if (!Prefs.configured(this)) {
            s.append(getString(R.string.state_setup));
        } else if (!hasSms()) {
            s.append(getString(R.string.state_no_sms));
        } else if (!Prefs.lastProblem(this).isEmpty()) {
            s.append(Prefs.lastProblem(this));
        } else if (Prefs.lastOkAt(this) > 0) {
            s.append(getString(R.string.state_ok, f.format(new Date(Prefs.lastOkAt(this)))));
        } else {
            s.append(getString(R.string.state_waiting));
        }
        int waiting = Outbox.get(this).waiting();
        if (waiting > 0) {
            s.append("\n").append(getString(R.string.state_queue, waiting));
        }
        status.setText(s.toString());

        StringBuilder a = new StringBuilder();
        for (String[] row : Outbox.get(this).recent()) {
            a.append(f.format(new Date(Long.parseLong(row[0])))).append("  ").append(row[1]).append("\n");
        }
        activity.setText(a.length() == 0 ? getString(R.string.activity_empty) : a.toString().trim());
    }
}
