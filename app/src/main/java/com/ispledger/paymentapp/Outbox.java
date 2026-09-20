package com.ispledger.paymentapp;

import android.content.ContentValues;
import android.content.Context;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.database.sqlite.SQLiteOpenHelper;

import java.util.ArrayList;
import java.util.List;

/**
 * Payment messages waiting to reach the dashboard. A message stays here until
 * the dashboard confirms it has it, so a phone with no data, or one that
 * restarts, loses nothing. Once confirmed the message text is deleted.
 * The short activity list keeps no message text at all.
 */
final class Outbox extends SQLiteOpenHelper {
    static final class Item {
        long id;
        String sender;
        String body;
        long sentAt;
        int sim;
    }

    private static Outbox instance;

    static synchronized Outbox get(Context c) {
        if (instance == null) {
            instance = new Outbox(c.getApplicationContext());
        }
        return instance;
    }

    private Outbox(Context c) {
        super(c, "outbox.db", null, 1);
    }

    @Override
    public void onCreate(SQLiteDatabase db) {
        db.execSQL("CREATE TABLE outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT, body TEXT, sent_at INTEGER, sim INTEGER, attempts INTEGER DEFAULT 0)");
        db.execSQL("CREATE TABLE activity (id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER, line TEXT)");
    }

    @Override
    public void onUpgrade(SQLiteDatabase db, int from, int to) {}

    synchronized void add(String sender, String body, long sentAt, int sim) {
        ContentValues v = new ContentValues();
        v.put("sender", sender);
        v.put("body", body);
        v.put("sent_at", sentAt);
        v.put("sim", sim);
        getWritableDatabase().insert("outbox", null, v);
    }

    synchronized List<Item> pending(int limit) {
        List<Item> out = new ArrayList<>();
        try (Cursor c = getReadableDatabase().rawQuery(
                "SELECT id, sender, body, sent_at, sim FROM outbox ORDER BY id LIMIT " + limit, null)) {
            while (c.moveToNext()) {
                Item i = new Item();
                i.id = c.getLong(0);
                i.sender = c.getString(1);
                i.body = c.getString(2);
                i.sentAt = c.getLong(3);
                i.sim = c.getInt(4);
                out.add(i);
            }
        }
        return out;
    }

    synchronized void delivered(long id) {
        getWritableDatabase().delete("outbox", "id = ?", new String[]{String.valueOf(id)});
    }

    synchronized void failed(long id) {
        getWritableDatabase().execSQL("UPDATE outbox SET attempts = attempts + 1 WHERE id = " + id);
    }

    synchronized int waiting() {
        try (Cursor c = getReadableDatabase().rawQuery("SELECT COUNT(*) FROM outbox", null)) {
            return c.moveToFirst() ? c.getInt(0) : 0;
        }
    }

    synchronized void note(String line) {
        ContentValues v = new ContentValues();
        v.put("at", System.currentTimeMillis());
        v.put("line", line);
        SQLiteDatabase db = getWritableDatabase();
        db.insert("activity", null, v);
        db.execSQL("DELETE FROM activity WHERE id NOT IN (SELECT id FROM activity ORDER BY id DESC LIMIT 40)");
    }

    synchronized List<String[]> recent() {
        List<String[]> out = new ArrayList<>();
        try (Cursor c = getReadableDatabase().rawQuery("SELECT at, line FROM activity ORDER BY id DESC LIMIT 40", null)) {
            while (c.moveToNext()) {
                out.add(new String[]{String.valueOf(c.getLong(0)), c.getString(1)});
            }
        }
        return out;
    }
}
