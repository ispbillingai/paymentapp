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
import android.widget.CheckBox;
import android.widget.LinearLayout;
import android.text.method.PasswordTransformationMethod;

import android.widget.EditText;
import android.widget.TextView;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

/** The only screen: set up once, then a plain status the owner can glance at. */
public class MainActivity extends Activity {
    private EditText url, key, senders;
    private TextView status, activity;
    private Button save, allowSms, allowBattery, allowNotifications;
    private TextView title, badge, queued, lastContact, smsState, batteryState, notificationState;
    private LinearLayout activityRows, senderBoxes;
    private CheckBox[] networkBoxes;
    private TextView updateState, updateNotes;
    private Button updateAction;
    private android.widget.ProgressBar updateProgress;
    private Updater.Release pending;
    private boolean updateBusy;
    private boolean testing;
    private String lastActivity = "";
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
        if (Build.VERSION.SDK_INT >= 23) getWindow().getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR);
        else getWindow().setStatusBarColor(getResources().getColor(R.color.brand_dark));
        title = findViewById(R.id.connection_title);
        badge = findViewById(R.id.connection_badge);
        queued = findViewById(R.id.queue_count);
        lastContact = findViewById(R.id.last_contact);
        smsState = findViewById(R.id.sms_state);
        batteryState = findViewById(R.id.battery_state);
        notificationState = findViewById(R.id.notification_state);
        activityRows = findViewById(R.id.activity_rows);
        allowNotifications = findViewById(R.id.allow_notifications);
        allowNotifications.setOnClickListener(v -> {
            if (Build.VERSION.SDK_INT >= 33) requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, 2);
        });
        final View settingsFields = findViewById(R.id.settings_fields);
        final Button settingsToggle = findViewById(R.id.settings_toggle);
        settingsFields.setVisibility(Prefs.configured(this) ? View.GONE : View.VISIBLE);
        settingsToggle.setText(Prefs.configured(this) ? R.string.settings_open : R.string.settings_close);
        settingsToggle.setOnClickListener(v -> {
            boolean open = settingsFields.getVisibility() != View.VISIBLE;
            settingsFields.setVisibility(open ? View.VISIBLE : View.GONE);
            settingsToggle.setText(open ? R.string.settings_close : R.string.settings_open);
        });
        findViewById(R.id.help).setOnClickListener(v -> {
            try { startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse("https://ispbillingpay.com/app"))); }
            catch (android.content.ActivityNotFoundException ignored) { }
        });
        url = findViewById(R.id.url);
        key = findViewById(R.id.key);
        senders = findViewById(R.id.senders);
        if (Build.VERSION.SDK_INT >= 26) {
            key.setImportantForAutofill(View.IMPORTANT_FOR_AUTOFILL_NO);
            url.setImportantForAutofill(View.IMPORTANT_FOR_AUTOFILL_NO);
            senders.setImportantForAutofill(View.IMPORTANT_FOR_AUTOFILL_NO);
        }
        status = findViewById(R.id.status);
        activity = findViewById(R.id.activity);
        save = findViewById(R.id.save);
        allowSms = findViewById(R.id.allow_sms);
        allowBattery = findViewById(R.id.allow_battery);

        url.setText(Prefs.url(this).equals(Prefs.DEFAULT_URL) ? "" : Prefs.url(this));
        final View advanced = findViewById(R.id.advanced);
        advanced.setVisibility(url.getText().length() > 0 ? View.VISIBLE : View.GONE);
        findViewById(R.id.advanced_toggle).setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                advanced.setVisibility(advanced.getVisibility() == View.VISIBLE ? View.GONE : View.VISIBLE);
            }
        });
        key.setText(Prefs.key(this));
        ((CheckBox)findViewById(R.id.show_key)).setOnCheckedChangeListener((view, checked) -> {
            key.setTransformationMethod(checked ? null : PasswordTransformationMethod.getInstance());
            key.setSelection(key.length());
        });
        // Networks are ticked; anything not listed is typed into the field below them.
        senderBoxes = findViewById(R.id.sender_boxes);
        String saved = Prefs.senders(this);
        networkBoxes = new CheckBox[Senders.NETWORKS.length];
        for (int i = 0; i < Senders.NETWORKS.length; i++) {
            CheckBox box = new CheckBox(this);
            box.setText(Senders.NETWORKS[i][0]);
            box.setTextSize(14);
            box.setMinHeight(dp(46));
            box.setTextColor(getResources().getColor(R.color.ink));
            box.setChecked(Senders.selected(saved, i));
            networkBoxes[i] = box;
            senderBoxes.addView(box);
        }
        senders.setText(Senders.extras(saved));

        updateState = findViewById(R.id.update_state);
        updateNotes = findViewById(R.id.update_notes);
        updateAction = findViewById(R.id.update_action);
        updateProgress = findViewById(R.id.update_progress);
        updateState.setText(getString(R.string.update_current, BuildConfig.VERSION_NAME));
        updateAction.setOnClickListener(v -> onUpdateTapped());
        checkForUpdate();

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
        if (u.isEmpty()) {
            u = Prefs.DEFAULT_URL;
        }
        String k = key.getText().toString().trim();
        if (!u.startsWith("https://") && !(BuildConfig.DEBUG && u.startsWith("http://"))) {
            url.setError(getString(R.string.err_url));
            url.requestFocus();
            return;
        }
        if (!k.matches("[a-f0-9]{40}")) {
            key.setError(getString(R.string.err_key));
            key.requestFocus();
            return;
        }
        boolean[] chosen = new boolean[networkBoxes.length];
        boolean any = false;
        for (int i = 0; i < networkBoxes.length; i++) {
            chosen[i] = networkBoxes[i].isChecked();
            any = any || chosen[i];
        }
        String typed = senders.getText().toString().trim();
        if (!any && typed.isEmpty()) {
            senders.setError(getString(R.string.sender_empty)); senders.requestFocus(); return;
        }
        testing = true;
        Prefs.save(this, u, k, Senders.compose(chosen, typed));
        status.setText(R.string.testing);
        save.setEnabled(false);
        save.setText(R.string.testing_button);
        render();
        final Context app = getApplicationContext();
        new Thread(new Runnable() {
            @Override
            public void run() {
                final String problem = Uploader.ping(app);
                if (problem.isEmpty()) {
                    Outbox.get(app).note("Connected");
                    Uploader.flush(app);
                }
                ui.post(new Runnable() {
                    @Override
                    public void run() {
                        if (isFinishing() || isDestroyed()) return;
                        testing = false;
                        save.setEnabled(true);
                        save.setText(R.string.btn_save);
                        ListenerService.ensureRunning(app);
                        render();
                    }
                });
            }
        }).start();
    }

    /**
     * Asks the service what the newest build is. A failure is said plainly and
     * changes nothing else: a phone that cannot check still keeps reporting payments.
     */
    private void checkForUpdate() {
        if (updateBusy) {
            return;
        }
        updateBusy = true;
        updateAction.setEnabled(false);
        new Thread(() -> {
            final Updater.Release release = Updater.check();
            ui.post(() -> {
                if (isFinishing() || isDestroyed()) return;
                updateBusy = false;
                updateAction.setEnabled(true);
                if (release == null) {
                    pending = null;
                    updateState.setText(getString(R.string.update_unreachable, BuildConfig.VERSION_NAME));
                    updateNotes.setVisibility(View.GONE);
                    updateAction.setText(R.string.update_check);
                    return;
                }
                if (!release.newerThanInstalled()) {
                    pending = null;
                    updateState.setText(getString(R.string.update_latest, BuildConfig.VERSION_NAME));
                    updateNotes.setVisibility(View.GONE);
                    updateAction.setText(R.string.update_check);
                    return;
                }
                pending = release;
                updateState.setText(getString(R.string.update_available, release.versionName, BuildConfig.VERSION_NAME));
                if (release.notes.isEmpty()) {
                    updateNotes.setVisibility(View.GONE);
                } else {
                    updateNotes.setText(release.notes);
                    updateNotes.setVisibility(View.VISIBLE);
                }
                updateAction.setText(Updater.canInstall(MainActivity.this)
                        ? getString(R.string.update_install, release.versionName)
                        : getString(R.string.update_allow));
            });
        }).start();
    }

    /** Check, or download and install, depending on what is known so far. */
    private void onUpdateTapped() {
        if (pending == null) {
            checkForUpdate();
            return;
        }
        if (!Updater.canInstall(this)) {
            updateNotes.setText(R.string.update_allow_why);
            updateNotes.setVisibility(View.VISIBLE);
            Intent settings = Updater.allowInstallSettings(this);
            if (settings != null) {
                try { startActivity(settings); } catch (Exception ignored) { }
            }
            return;
        }
        final Updater.Release release = pending;
        updateBusy = true;
        updateAction.setEnabled(false);
        updateAction.setText(R.string.update_downloading);
        updateProgress.setProgress(0);
        updateProgress.setVisibility(View.VISIBLE);
        final Context app = getApplicationContext();
        new Thread(() -> {
            String problem = "";
            java.io.File file = null;
            try {
                file = Updater.download(app, release, percent -> ui.post(() -> updateProgress.setProgress(percent)));
            } catch (Exception e) {
                problem = e.getMessage() == null ? "The update could not be downloaded." : e.getMessage();
            }
            final String why = problem;
            final java.io.File ready = file;
            ui.post(() -> {
                if (isFinishing() || isDestroyed()) return;
                updateBusy = false;
                updateAction.setEnabled(true);
                updateProgress.setVisibility(View.GONE);
                if (ready == null) {
                    updateNotes.setText(why);
                    updateNotes.setVisibility(View.VISIBLE);
                    updateAction.setText(getString(R.string.update_install, release.versionName));
                    return;
                }
                updateState.setText(R.string.update_ready);
                updateAction.setText(getString(R.string.update_install, release.versionName));
                try {
                    Updater.install(MainActivity.this, ready);
                } catch (Exception e) {
                    updateNotes.setText(e.getMessage() == null ? "Android could not open the installer." : e.getMessage());
                    updateNotes.setVisibility(View.VISIBLE);
                }
            });
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
        super.onRequestPermissionsResult(code, permissions, results);
        render();
    }

    private void render() {
        allowSms.setVisibility(hasSms() ? View.GONE : View.VISIBLE);
        allowBattery.setVisibility(batteryFree() ? View.GONE : View.VISIBLE);
        boolean notifications = Build.VERSION.SDK_INT < 33 || checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED;
        allowNotifications.setVisibility(notifications ? View.GONE : View.VISIBLE);
        smsState.setText(hasSms() ? R.string.sms_ready : R.string.sms_needed);
        batteryState.setText(batteryFree() ? R.string.battery_ready : R.string.battery_needed);
        notificationState.setText(notifications ? R.string.notifications_ready : R.string.notifications_needed);
        smsState.setCompoundDrawablesWithIntrinsicBounds(hasSms() ? R.drawable.status_ready : R.drawable.status_pending,0,0,0);
        smsState.setCompoundDrawablePadding(dp(10));
        batteryState.setCompoundDrawablesWithIntrinsicBounds(batteryFree() ? R.drawable.status_ready : R.drawable.status_pending,0,0,0);
        batteryState.setCompoundDrawablePadding(dp(10));
        boolean stale = Prefs.lastOkAt(this) > 0 && System.currentTimeMillis() - Prefs.lastOkAt(this) > 15 * 60 * 1000;
        boolean connected = Prefs.configured(this) && hasSms() && Prefs.lastProblem(this).isEmpty() && Prefs.lastOkAt(this) > 0 && !stale;
        title.setText(testing ? R.string.hero_testing : !Prefs.configured(this) ? R.string.hero_setup : connected ? R.string.hero_ready : R.string.hero_attention);
        badge.setText(testing ? R.string.pill_testing : !Prefs.configured(this) ? R.string.pill_setup : connected ? R.string.pill_ready : R.string.pill_attention);
        queued.setText(String.valueOf(Outbox.get(this).waiting()));
        lastContact.setText(Prefs.lastOkAt(this) > 0 ? new SimpleDateFormat("HH:mm", Locale.getDefault()).format(new Date(Prefs.lastOkAt(this))) : getString(R.string.never_contact));

        SimpleDateFormat f = new SimpleDateFormat("d MMM HH:mm", Locale.getDefault());
        StringBuilder s = new StringBuilder();
        if (testing) {
            s.append(getString(R.string.testing));
        } else if (!Prefs.configured(this)) {
            s.append(getString(R.string.state_setup));
        } else if (!hasSms()) {
            s.append(getString(R.string.state_no_sms));
        } else if (!Prefs.lastProblem(this).isEmpty()) {
            s.append(Prefs.lastProblem(this));
        } else if (stale) {
            s.append(getString(R.string.recent_stale));
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

        java.util.List<String[]> rows = Outbox.get(this).recent();
        StringBuilder signature = new StringBuilder();
        for (String[] row : rows) signature.append(row[0]).append(row[1]);
        if (!signature.toString().equals(lastActivity)) {
            lastActivity = signature.toString();
            activityRows.removeAllViews();
            for (String[] row : rows) {
                LinearLayout entry = new LinearLayout(this);
                entry.setOrientation(LinearLayout.VERTICAL);
                entry.setPadding(0, dp(15), 0, dp(15));
                TextView time = new TextView(this);
                time.setText(f.format(new Date(Long.parseLong(row[0]))));
                time.setTextColor(getResources().getColor(R.color.muted));
                time.setTextSize(11);
                TextView description = new TextView(this);
                description.setText(row[1]);
                description.setTextSize(14);
                description.setTextColor(getResources().getColor(R.color.ink));
                description.setPadding(0, dp(5), 0, 0);
                description.setLineSpacing(dp(3), 1);
                entry.addView(time); entry.addView(description);
                activityRows.addView(entry);
                View divider = new View(this);
                divider.setBackgroundColor(getResources().getColor(R.color.line));
                activityRows.addView(divider, new LinearLayout.LayoutParams(-1, dp(1)));
            }
        }
        activity.setVisibility(rows.isEmpty() ? View.VISIBLE : View.GONE);
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }
}
