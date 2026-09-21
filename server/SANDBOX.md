# Developer sandbox and merchant resources

This release adds a separate simulator at `/sandbox` and 20 resource pages indexed by `/resources`. The sandbox is not a live receipt parser or an outbound webhook test tool. It never calls providers or queues live webhook delivery.

## Deployment

1. Deploy application files without copying development configuration or fixture data.
2. Back up the production database through the deployment's normal process.
3. Run `php server/bin/migrate-sandbox.php` to inspect the additive schema, then `php server/bin/migrate-sandbox.php --apply`. This creates four isolated InnoDB tables. No live payment table is altered. The command is repeatable.
4. Check `/sandbox`, `/resources`, `/login` and the existing live registration API. Run the webhook retry worker as before.

The existing account/session tables are installed through the existing gateway schema initialisation. This release preserves machine-to-machine registration without portal email/password fields. Email/password account creation is optional on that endpoint; portal signup supplies them.

## Sandbox interface

- `POST /v1/sandbox/register`: developer email and password, returns a one-time test key.
- `POST /v1/sandbox/intents`: decimal-string amount, currency, reference; requires an `Idempotency-Key` header.
- `POST /v1/sandbox/intents/{id}/simulate`: scenario `success`, `failure`, or `reversal`.
- `GET /v1/sandbox/intents`, `GET /v1/sandbox/events`: most recent 50 entries for the authenticated developer.
- Authenticate API requests with `Authorization: Bearer sk_test_...`. Live endpoints do not accept test keys.
- Portal equivalents use an HttpOnly session cookie and `X-Portal-Request: 1` for mutations. No cross-origin CORS permission is granted.
- Rotating test keys requires the current password and revokes older keys in the same transaction.

## Account and operational boundaries

The simulator has a 1,000-intent account limit. Test records have no automatic purge schedule. Live activation, refunds, provider settlement and MAC reconnection remain outside the simulator. Email verification, password recovery, MFA, team roles and direct provider API channels are explicitly listed as future work. Do not imply they are available.

Policy pages describe implemented behaviour and proposed commercial boundaries. The operator must confirm the legal entity, applicable commercial agreement, governing law, retention schedule, hosting/subprocessors and jurisdiction-specific requirements before treating these pages as a complete production contract. No provider endorsement, regulatory status or PCI certification is asserted.

Reference research: Stripe's official testing documentation (https://docs.stripe.com/testing), PCI SSC's sensitive-authentication-data guidance (https://www.pcisecuritystandards.org/faqs/1533/), and the African Union convention page (https://www.au.int/en/treaties/african-union-convention-cyber-security-and-personal-data-protection). These references do not establish this product's compliance status.

## Verification

`php server/tests/security_sandbox.php` uses a throwaway MySQL database for key isolation, tenant isolation, repeat requests, conflict rejection, permitted lifecycle transitions and rollback when event persistence fails. The integration checks in this development task ran against a dedicated disposable MySQL database and local HTTP server. They did not use production credentials or payment records.
