# Regression suites

Each file is a standalone PHP script that exits `0` on success and prints `PASS …`
on the last line. There is no test framework: the suites build the use cases
directly, stub the ports, or use SQLite, so most of them need no server.

```bash
php tests/run-all.php        # everything that can run offline
composer test                # same thing

# Native suites, once a disposable MySQL/MariaDB is available. Each group has its
# own guard variables; see the suite header (e.g. tests/relay-cli.php) for the exact
# database-name prefix it accepts.
LATRA_TEST_DSN='mysql:host=127.0.0.1;port=3306;dbname=latra_test_delivery;charset=utf8mb4' \
LATRA_TEST_USER=root LATRA_TEST_PASSWORD=… LATRA_TEST_ALLOW_RESET=1 \
LATRA_RELAY_TEST_DSN='mysql:host=127.0.0.1;port=3306;dbname=latra_test_relay_app_ci;charset=utf8mb4' \
LATRA_RELAY_TEST_USER=root LATRA_RELAY_TEST_PASSWORD=… LATRA_RELAY_TEST_ALLOW_RESET=1 \
  php tests/run-all.php
```

## What the suites cover

| Group | Suites |
|---|---|
| Architecture guards | `architecture-boundaries.php`, `entrypoint-audit.php`, `schema-parity.php`, `retired-paths.php` |
| Collection / relay | `relay-*.php`, `collection.php`, `wialon-window.php`, `durable-relay.php` |
| Ingestion and mapping | `ingestion-*.php`, `activity-addon.php`, `fuel-report-policy.php`, `relay-mapping.php` |
| Operations / API | `operator-*.php`, `relay-api.php`, `data-cleanup.php`, `monitoring-usecases.php` |
| Security | `auth-*.php`, `vault-usecase.php`, `security-persistence.php`, `operator-provisioning.php` |
| Scheduling / maintenance | `scheduled-poll.php`, `scheduling*.php`, `maintenance-jobs.php`, `retention-usecase.php`, `worker-cadence.php` |

## Helpers, not suites

- `bootstrap.php` — disposable fixture constants shared by the native suites.
- `relay-mock.php` / `mock-latra.php` — loopback receivers started by a parent suite.
- `latra-receiver.php` — loopback stand-in LATRA endpoint for live delivery checks; appends each
  request to a private text log (`LATRA_RECEIVER_LOG`), including exact bytes in `body_base64`.
  Explicit `LATRA_RECEIVER_ALLOW` ranges permit an isolated container network. Duplicate
  detection is serialized across Apache workers; this does not prove LATRA idempotency.
- `collection-fixture.json` — fixture data.

## Suites that need a disposable database

These refuse to run unless `LATRA_TEST_DSN` points at a throwaway `latra_test_*`
database with `LATRA_TEST_ALLOW_RESET=1`; they reset that database. They are the
only place native MySQL/MariaDB behavior is proven:

- `durable-relay.php`, `relay-cli.php`, `relay-integration.php`, `relay-runtime.php`
- `ingestion-native-repositories.php`
- `delivery-retention.php`, `security-persistence.php` (database steps)

`run-all.php` reports them as `SKIP` when no DSN is configured, so an offline run
is honest about what it did not prove.
