# Android 1.4.0 — forwarding workspace

## New and improved features

1. Sidebar navigation with seven dedicated pages and Android Back support.
2. Independent connection test with elapsed time and explicit key acceptance/error feedback.
3. Pairing feedback directly below the key; queue upload no longer delays the result.
4. Daily delivery and failed-attempt metric cards.
5. Accessible delivery chart using actual acknowledged messages.
6. Seven/fourteen-day chart period switching.
7. Queue inspection with receipt time, SIM and failed-attempt count; message contents hidden.
8. Manual queue retry with progress and a completion result.
9. Persistent pause/resume; incoming allowed messages remain queued. An in-flight request may finish.
10. Search across the latest 40 activity records.
11. Five-step device readiness checklist.
12. Network/device diagnostics and an explicit share action excluding keys and payment contents.
13. Visible update-check progress, error feedback and an official download fallback.
14. Direct shortcuts to Android settings and support.

## Data and security

The SQLite version-2 upgrade preserves all pending messages and activity. Daily aggregates use a primary-key index on day and are retained for approximately 90 days. Existing primary-key indexes serve FIFO queue and recent-activity reads. Delivery deletion and counter increment occur in one transaction. No historical delivery counts are invented: chart data starts after this update.

Successful HTTP status alone no longer deletes a message: the service must also return a JSON acknowledgement with `ok: true`. The app does not follow redirects while sending device keys. Connection validation rejects cleartext release URLs, embedded credentials, fragments and invalid ports. Update downloads retain SHA-256 and size validation before invoking the Android installer.

## Validation

Build debug and release APKs and run lint. Run `ConnectionPolicyCheck.java` alongside the actual ConnectionPolicy source using the JDK. Inspect APK version and verify its signing certificate matches the previous release before replacing dist/PaymentBridge.apk. The public update manifest and installed-phone testing must be checked separately from local build success.

## Device acceptance checks

- Upgrade an existing installation with pending messages; confirm settings and queue survive.
- Test a valid and invalid device key, offline mode, timeout and non-JSON server response.
- With messages pending, confirm pairing results appear independently of queue upload.
- Pause, receive a supported sender's SMS, resume and verify exactly one gateway receipt.
- Retry after connectivity returns; inspect chart, queue and daily counts.
- Search an empty log and a populated log; verify no message bodies appear in diagnostics.
- Check an up-to-date release, an available release, unavailable manifest, invalid checksum and Android install permission refusal.
- Review small-screen layout, increased font size, drawer Back/scrim actions and screen-reader labels.
