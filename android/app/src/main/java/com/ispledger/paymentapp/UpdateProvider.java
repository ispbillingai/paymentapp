package com.ispledger.paymentapp;

import android.content.ContentProvider;
import android.content.ContentValues;
import android.database.Cursor;
import android.database.MatrixCursor;
import android.net.Uri;
import android.os.ParcelFileDescriptor;
import android.provider.OpenableColumns;

import java.io.File;

/**
 * Hands the downloaded update to Android's package installer, which runs as a
 * separate app and so cannot be given a plain file path.
 *
 * This exists instead of pulling in a support library for one screen's worth of
 * work: the app ships with no dependencies at all, and is a few tens of kilobytes
 * because of it, which matters on the phones it runs on.
 *
 * It serves exactly one file, the verified update in this app's own cache. It is
 * not exported, so only a component this app hands a URI to can read it, and that
 * is the installer, for one install.
 */
public final class UpdateProvider extends ContentProvider {
    private static final String FILE = "update.apk";

    @Override
    public boolean onCreate() {
        return true;
    }

    /** The one file this provider will ever serve, whatever is asked for. */
    private File file() {
        return new File(getContext().getCacheDir(), FILE);
    }

    private boolean isOurs(Uri uri) {
        String path = uri.getPath();
        return path != null && path.endsWith(FILE);
    }

    @Override
    public ParcelFileDescriptor openFile(Uri uri, String mode) throws java.io.FileNotFoundException {
        if (!isOurs(uri) || !"r".equals(mode)) {
            throw new java.io.FileNotFoundException("Only the pending update can be read.");
        }
        return ParcelFileDescriptor.open(file(), ParcelFileDescriptor.MODE_READ_ONLY);
    }

    /**
     * The installer asks for the name and size before reading. Anything else it may
     * ask for is answered as absent rather than guessed at.
     */
    @Override
    public Cursor query(Uri uri, String[] projection, String selection, String[] args, String sort) {
        if (!isOurs(uri)) {
            return null;
        }
        File file = file();
        String[] columns = projection != null ? projection : new String[]{OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE};
        Object[] values = new Object[columns.length];
        for (int i = 0; i < columns.length; i++) {
            if (OpenableColumns.DISPLAY_NAME.equals(columns[i])) {
                values[i] = FILE;
            } else if (OpenableColumns.SIZE.equals(columns[i])) {
                values[i] = file.length();
            } else {
                values[i] = null;
            }
        }
        MatrixCursor cursor = new MatrixCursor(columns, 1);
        cursor.addRow(values);
        return cursor;
    }

    @Override
    public String getType(Uri uri) {
        return isOurs(uri) ? "application/vnd.android.package-archive" : null;
    }

    // Nothing may be written, inserted or removed through here.
    @Override
    public Uri insert(Uri uri, ContentValues values) {
        throw new UnsupportedOperationException();
    }

    @Override
    public int delete(Uri uri, String selection, String[] args) {
        throw new UnsupportedOperationException();
    }

    @Override
    public int update(Uri uri, ContentValues values, String selection, String[] args) {
        throw new UnsupportedOperationException();
    }
}
