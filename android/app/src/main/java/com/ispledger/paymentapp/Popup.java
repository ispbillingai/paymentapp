package com.ispledger.paymentapp;

import android.app.Activity;
import android.app.Dialog;
import android.graphics.drawable.ColorDrawable;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.widget.Button;
import android.widget.TextView;

/**
 * The answer to "did that work?", in the middle of the screen.
 *
 * The result of pairing and of a connection test used to be a line of text below
 * the button that started it, which on a phone sits behind the keyboard: the
 * owner paired successfully and saw nothing at all. This says so where it cannot
 * be missed, and closes the keyboard on the way.
 *
 * Built from the app's own views rather than a dialog library, because this app
 * ships with no dependencies.
 */
final class Popup {
    private Popup() {}

    static void good(Activity a, String title, String body, String detail) {
        show(a, true, title, body, detail);
    }

    static void bad(Activity a, String title, String body) {
        show(a, false, title, body, "");
    }

    private static void show(Activity a, boolean good, String title, String body, String detail) {
        if (a == null || a.isFinishing() || a.isDestroyed()) {
            return;
        }
        hideKeyboard(a);
        Dialog dialog = new Dialog(a);
        dialog.requestWindowFeature(Window.FEATURE_NO_TITLE);
        dialog.setContentView(R.layout.popup);
        dialog.setCanceledOnTouchOutside(true);

        TextView mark = dialog.findViewById(R.id.popup_mark);
        mark.setBackgroundResource(good ? R.drawable.popup_mark_good : R.drawable.popup_mark_bad);
        mark.setText(good ? R.string.popup_tick : R.string.popup_cross);
        mark.setTextColor(a.getResources().getColor(good ? R.color.brand : R.color.ink));

        ((TextView) dialog.findViewById(R.id.popup_title)).setText(title);
        ((TextView) dialog.findViewById(R.id.popup_body)).setText(body);
        TextView extra = dialog.findViewById(R.id.popup_detail);
        if (detail == null || detail.isEmpty()) {
            extra.setVisibility(View.GONE);
        } else {
            extra.setText(detail);
            extra.setVisibility(View.VISIBLE);
        }
        Button ok = dialog.findViewById(R.id.popup_ok);
        ok.setText(good ? R.string.popup_ok : R.string.popup_close);
        ok.setOnClickListener(v -> dialog.dismiss());

        Window window = dialog.getWindow();
        if (window != null) {
            window.setBackgroundDrawable(new ColorDrawable(0x00000000));
            window.setLayout(WindowManager.LayoutParams.MATCH_PARENT, WindowManager.LayoutParams.WRAP_CONTENT);
            window.setDimAmount(0.55f);
            window.getDecorView().setPadding(dp(a, 22), 0, dp(a, 22), 0);
        }
        try {
            dialog.show();
        } catch (Exception ignored) {
            // The screen went away between the check above and here; nothing to show on.
        }
    }

    private static void hideKeyboard(Activity a) {
        try {
            View focused = a.getCurrentFocus();
            if (focused == null) {
                return;
            }
            android.view.inputmethod.InputMethodManager manager =
                    (android.view.inputmethod.InputMethodManager) a.getSystemService(Activity.INPUT_METHOD_SERVICE);
            if (manager != null) {
                manager.hideSoftInputFromWindow(focused.getWindowToken(), 0);
            }
        } catch (Exception ignored) {
        }
    }

    private static int dp(Activity a, int value) {
        return Math.round(value * a.getResources().getDisplayMetrics().density);
    }
}
