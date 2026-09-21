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
    private Dashboard dashboard;
    private EditText url, key, senders;
    private TextView status, activity;
    private Button save, allowSms, allowBattery, allowNotifications;
    private TextView title, badge, queued, lastContact, smsState, batteryState, notificationState;
    private LinearLayout activityRows, senderBoxes;
    /** Every sender name on screen, built in or the owner's own. */
    private java.util.List<SenderList.Row> senderRows = new java.util.ArrayList<>();
    private TextView pairFields;
    private TextView updateState, updateNotes;
    private Button updateAction;
    private android.widget.ProgressBar updateProgress;
    private Updater.Release pending;
    private boolean updateBusy;
    /** Asked once per time the app is opened, not once per screen returned to. */
    private boolean askedAboutUpdate;
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
        dashboard = new Dashboard(this);
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
        // Only a debug build can be pointed somewhere else; a released one always
        // reports to the service, so the control is not offered at all.
        final View advanced = findViewById(R.id.advanced);
        final View advancedToggle = findViewById(R.id.advanced_toggle);
        if (!BuildConfig.DEBUG) {
            advanced.setVisibility(View.GONE);
            advancedToggle.setVisibility(View.GONE);
        } else {
            advanced.setVisibility(url.getText().length() > 0 ? View.VISIBLE : View.GONE);
        }
        findViewById(R.id.advanced_toggle).setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                advanced.setVisibility(advanced.getVisibility() == View.VISIBLE ? View.GONE : View.VISIBLE);
            }
        });
        key.setText(Prefs.key(this));
        // An account key needs two more details before this phone can register itself.
        pairFields = findViewById(R.id.pair_fields);
        key.addTextChangedListener(new android.text.TextWatcher() {
            @Override public void beforeTextChanged(CharSequence s, int a, int b, int c) {}
            @Override public void onTextChanged(CharSequence s, int a, int b, int c) {}
            @Override public void afterTextChanged(android.text.Editable text) {
                pairFields.setVisibility(Enrol.looksLikeAccountKey(text.toString().trim()) ? View.VISIBLE : View.GONE);
            }
        });
        ((CheckBox)findViewById(R.id.show_key)).setOnCheckedChangeListener((view, checked) -> {
            key.setTransformationMethod(checked ? null : PasswordTransformationMethod.getInstance());
            key.setSelection(key.length());
        });
        // Networks are ticked; anything not listed is typed into the field below them.
        senderBoxes = findViewById(R.id.sender_boxes);
        drawSenders();
        findViewById(R.id.add_sender).setOnClickListener(v -> addTypedSender());
        senders.setOnEditorActionListener((view, action, event) -> { addTypedSender(); return true; });

        // The zone every time on this screen is shown in. The phone's own setting is
        // first, since that is right for most, and the rest are offered by name.
        final android.widget.Spinner timezone = findViewById(R.id.timezone);
        final java.util.List<String> zoneIds = new java.util.ArrayList<>();
        zoneIds.add("");
        zoneIds.addAll(Times.choices());
        java.util.List<String> zoneLabels = new java.util.ArrayList<>();
        zoneLabels.add(getString(R.string.timezone_phone) + " · " + Times.label(java.util.TimeZone.getDefault().getID()));
        for (int i = 1; i < zoneIds.size(); i++) zoneLabels.add(Times.label(zoneIds.get(i)));
        android.widget.ArrayAdapter<String> zoneAdapter =
                new android.widget.ArrayAdapter<>(this, android.R.layout.simple_spinner_item, zoneLabels);
        zoneAdapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
        timezone.setAdapter(zoneAdapter);
        timezone.setSelection(Math.max(0, zoneIds.indexOf(Prefs.timezone(this))));
        timezone.setOnItemSelectedListener(new android.widget.AdapterView.OnItemSelectedListener() {
            @Override public void onItemSelected(android.widget.AdapterView<?> parent, View view, int position, long id) {
                String chosen = zoneIds.get(position);
                if (chosen.equals(Prefs.timezone(MainActivity.this))) return;
                Prefs.timezone(MainActivity.this, chosen);
                lastActivity = "";
                render();
                dashboard.connectionFeedback(getString(R.string.timezone_set, zoneLabels.get(position)));
            }
            @Override public void onNothingSelected(android.widget.AdapterView<?> parent) {}
        });

        updateState = findViewById(R.id.update_state);
        updateNotes = findViewById(R.id.update_notes);
        updateAction = findViewById(R.id.update_action);
        updateProgress = findViewById(R.id.update_progress);
        updateState.setText(getString(R.string.update_current, BuildConfig.VERSION_NAME));
        updateAction.setOnClickListener(v -> onUpdateTapped());
        // Check only when requested; progress stays visible on the Updates page.

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

    /** Takes what is in the field and puts it on the list, saying what happened. */
    private void addTypedSender() {
        String name = senders.getText().toString().trim().replace(",", " ").trim();
        if (name.isEmpty()) {
            dashboard.connectionFeedback(getString(R.string.sender_type_first));
            senders.requestFocus();
            return;
        }
        // Already on the list: switch it on rather than add it twice.
        for (SenderList.Row row : senderRows) {
            if (Senders.normalise(row.label).equals(Senders.normalise(name)) || covers(row, name)) {
                row.on = true;
                senders.setText("");
                drawSenders();
                dashboard.connectionFeedback(getString(R.string.sender_already_on, row.label));
                return;
            }
        }
        SenderList.addCustom(this, name);
        senders.setText("");
        drawSenders();
        for (SenderList.Row row : senderRows) {
            if (Senders.normalise(row.label).equals(Senders.normalise(name))) row.on = true;
        }
        drawSenders();
        dashboard.connectionFeedback(getString(R.string.sender_added, name));
    }

    private boolean covers(SenderList.Row row, String name) {
        for (String one : row.names.split(",")) {
            if (Senders.normalise(one).equals(Senders.normalise(name))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Draws the sender list. Every row looks the same whether it came with the app
     * or the owner typed it: a tick box to use it, and a Remove to drop it.
     */
    private void drawSenders() {
        java.util.List<SenderList.Row> fresh = SenderList.rows(this);
        // Keep whatever the owner has ticked but not yet saved.
        for (SenderList.Row row : fresh) {
            for (SenderList.Row old : senderRows) {
                if (old.label.equals(row.label)) {
                    row.on = old.on;
                }
            }
        }
        senderRows = fresh;
        senderBoxes.removeAllViews();
        for (final SenderList.Row row : senderRows) {
            LinearLayout line = new LinearLayout(this);
            line.setOrientation(LinearLayout.HORIZONTAL);
            line.setGravity(android.view.Gravity.CENTER_VERTICAL);
            final CheckBox box = new CheckBox(this);
            box.setText(row.label);
            box.setTextSize(14);
            box.setMinHeight(dp(46));
            box.setTextColor(getResources().getColor(R.color.ink));
            box.setChecked(row.on);
            box.setLayoutParams(new LinearLayout.LayoutParams(0, -2, 1f));
            box.setOnCheckedChangeListener((view, checked) -> row.on = checked);
            Button drop = new Button(this, null, android.R.attr.borderlessButtonStyle);
            drop.setText(R.string.remove_sender);
            drop.setTextSize(12);
            drop.setMinHeight(dp(44));
            drop.setTextColor(getResources().getColor(R.color.brand));
            drop.setOnClickListener(v -> {
                SenderList.remove(MainActivity.this, row);
                senderRows.remove(row);
                drawSenders();
                dashboard.connectionFeedback(getString(R.string.sender_removed, row.label));
            });
            line.addView(box);
            line.addView(drop);
            senderBoxes.addView(line);
        }
        if (senderRows.isEmpty()) {
            TextView empty = new TextView(this);
            empty.setText(R.string.no_senders);
            empty.setTextSize(12);
            empty.setTextColor(getResources().getColor(R.color.muted));
            empty.setPadding(0, dp(6), 0, dp(6));
            senderBoxes.addView(empty);
        }
        // A way back for an owner who removed a network they actually needed.
        if (SenderList.anyNetworkRemoved(this)) {
            Button restore = new Button(this, null, android.R.attr.borderlessButtonStyle);
            restore.setText(R.string.restore_networks);
            restore.setTextSize(12);
            restore.setTextColor(getResources().getColor(R.color.brand));
            restore.setOnClickListener(v -> {
                SenderList.restoreNetworks(MainActivity.this);
                drawSenders();
            });
            senderBoxes.addView(restore);
        }
    }

    /**
     * Offers an update when the app is opened, because nobody should have to know
     * where the Updates page is. Asked at most once every six hours, and only when
     * there is actually a newer build, so it never becomes noise.
     */
    private void offerUpdateOnOpen() {
        if (askedAboutUpdate || updateBusy) {
            return;
        }
        askedAboutUpdate = true;
        if (System.currentTimeMillis() - Prefs.updateAsked(this) < 6 * 60 * 60 * 1000L) {
            return;
        }
        final Context app = getApplicationContext();
        new Thread(() -> {
            final Updater.Release release = Updater.check(app);
            if (release == null || !release.newerThanInstalled()) {
                return;
            }
            ui.post(() -> {
                if (isFinishing() || isDestroyed()) return;
                Prefs.updateAskedNow(app);
                pending = release;
                Popup.ask(MainActivity.this,
                        getString(R.string.update_ready_title),
                        getString(R.string.update_ready_body, release.versionName),
                        release.notes,
                        getString(R.string.update_now),
                        getString(R.string.popup_later),
                        this::onUpdateTapped);
            });
        }).start();
    }

    @Override
    protected void onResume() {
        super.onResume();
        offerUpdateOnOpen();
        ListenerService.ensureRunning(this);
        ui.post(tick);
    }

    @Override
    protected void onPause() {
        ui.removeCallbacks(tick);
        super.onPause();
    }

    private void saveAndTest() {
        if (testing) return;
        final String u;
        {
            String typedUrl = url.getText().toString().trim();
            u = typedUrl.isEmpty() ? Prefs.DEFAULT_URL : typedUrl;
        }
        final String k = key.getText().toString().trim();
        if (!ConnectionPolicy.validUrl(u, BuildConfig.DEBUG)) {
            url.setError(getString(R.string.err_url));
            url.requestFocus();
            return;
        }
        final boolean pairing = Enrol.looksLikeAccountKey(k);
        if (!pairing && !Enrol.looksLikeDeviceKey(k)) {
            key.setError(getString(R.string.err_key));
            key.requestFocus();
            return;
        }

        // A name typed and not confirmed with Add name still counts, so it is never
        // quietly lost when the owner presses Save instead.
        if (!senders.getText().toString().trim().isEmpty()) {
            addTypedSender();
        }
        String typed = SenderList.enabledNames(senderRows);
        final String folded = "";
        if (typed.isEmpty()) {
            senders.setError(getString(R.string.sender_empty)); senders.requestFocus(); return;
        }
        testing = true;
        dashboard.connectionFeedback(getString(pairing ? R.string.pairing : R.string.testing));
        final long started = android.os.SystemClock.elapsedRealtime();
        final String senderList = typed;
        // Only ever store a key belonging to this phone. An account key is used for
        // the pairing request below and goes no further.
        Prefs.save(this, u, pairing ? Prefs.key(this) : k, senderList);
        status.setText(R.string.testing);
        save.setEnabled(false);
        save.setText(R.string.testing_button);
        render();
        final Context app = getApplicationContext();
        new Thread(new Runnable() {
            @Override
            public void run() {
                String pairProblem = "";
                String pairedAs = "";
                if (pairing) {
                    Enrol.Result paired = Enrol.pair(app, k, senderList, android.os.Build.MODEL);
                    if (paired.ok()) {
                        Prefs.save(app, u, paired.deviceKey, senderList);
                        pairedAs = paired.label;
                    } else {
                        pairProblem = paired.problem;
                    }
                }
                final String label = pairedAs;
                final String problem = pairProblem.isEmpty() ? Uploader.ping(app) : pairProblem;
                final boolean pairedOk = pairing && pairProblem.isEmpty();
                if (problem.isEmpty()) {
                    Outbox.get(app).note("Connected");
                    // Queue delivery runs independently of the test result.
                }
                ui.post(new Runnable() {
                    @Override
                    public void run() {
                        if (isFinishing() || isDestroyed()) return;
                        testing = false;
                        long took = android.os.SystemClock.elapsedRealtime() - started;
                        if (pairedOk) {
                            // The account key has done its job. Take it off the screen and
                            // show the key this phone was given in its place.
                            key.setText(Prefs.key(app));
                            pairFields.setVisibility(View.GONE);
                        }
                        dashboard.connectionFeedback(!problem.isEmpty() ? problem
                            : pairedOk ? getString(R.string.paired)
                            : "Connected successfully · " + took + " ms. Your device key was accepted.");
                        if (!problem.isEmpty()) {
                            Popup.bad(MainActivity.this, getString(pairing ? R.string.pair_failed_title : R.string.not_connected_title), problem);
                        } else if (pairedOk) {
                            Popup.good(MainActivity.this, getString(R.string.paired_title), getString(R.string.paired_body),
                                    label.isEmpty() ? "" : getString(R.string.paired_detail, label));
                        } else {
                            Popup.good(MainActivity.this, getString(R.string.connected_title), getString(R.string.connected_body),
                                    folded.isEmpty() ? getString(R.string.connected_detail, (int) took)
                                            : getString(R.string.sender_folded, folded));
                        }
                        save.setEnabled(true);
                        save.setText(R.string.btn_save);
                        ListenerService.ensureRunning(app);
                        render();
                        if (problem.isEmpty()) new Thread(() -> Uploader.flush(app)).start();
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
        updateState.setText("Checking for updates…");
        updateAction.setText("Checking…");
        updateNotes.setText("Contacting the official release service. Please allow up to 20 seconds.");
        updateNotes.setVisibility(View.VISIBLE);
        updateAction.setEnabled(false);
        final Context app = getApplicationContext();
        new Thread(() -> {
            final Updater.Release release = Updater.check(app);
            ui.post(() -> {
                if (isFinishing() || isDestroyed()) return;
                updateBusy = false;
                updateAction.setEnabled(true);
                if (release == null) {
                    pending = null;
                    updateState.setText(getString(R.string.update_unreachable, BuildConfig.VERSION_NAME));
                    updateNotes.setText("Check internet access and try again, or use the official download page below.");
                    updateNotes.setVisibility(View.VISIBLE);
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
        dashboard.refresh();
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
        lastContact.setText(Prefs.lastOkAt(this) > 0 ? Times.format(this, "HH:mm", Prefs.lastOkAt(this)) : getString(R.string.never_contact));

        SimpleDateFormat f = Times.formatter(this, "d MMM HH:mm");
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
        if (Prefs.paused(this)) { title.setText("Forwarding is paused"); badge.setText("PAUSED"); status.setText("Incoming payment messages stay queued. Resume forwarding when you are ready."); }

        java.util.List<String[]> rows = dashboard.filteredActivity();
        StringBuilder signature = new StringBuilder();
        for (String[] row : rows) signature.append(row[0]).append(row[1]).append(row.length > 2 ? row[2] : "");
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
                // The message exactly as it was forwarded. It came from outside, so it is
                // only ever set as text, and it is selectable for copying into a report.
                if (row.length > 2 && row[2] != null && !row[2].isEmpty()) {
                    TextView message = new TextView(this);
                    message.setText(row[2]);
                    message.setTextSize(12);
                    message.setTypeface(android.graphics.Typeface.MONOSPACE);
                    message.setTextColor(getResources().getColor(R.color.ink));
                    message.setBackgroundResource(R.drawable.soft_background);
                    message.setPadding(dp(12), dp(10), dp(12), dp(10));
                    message.setLineSpacing(dp(3), 1);
                    message.setTextIsSelectable(true);
                    LinearLayout.LayoutParams messageParams = new LinearLayout.LayoutParams(-1, -2);
                    messageParams.topMargin = dp(8);
                    message.setLayoutParams(messageParams);
                    entry.addView(message);
                }
                activityRows.addView(entry);
                View divider = new View(this);
                divider.setBackgroundColor(getResources().getColor(R.color.line));
                activityRows.addView(divider, new LinearLayout.LayoutParams(-1, dp(1)));
            }
        }
        activity.setVisibility(rows.isEmpty() ? View.VISIBLE : View.GONE);
    }

    @Override public void onBackPressed() { if (!dashboard.back()) super.onBackPressed(); }
    void refreshDashboard() { render(); }
    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }
}
