# Horizon Watch

A self-hosted control room for several Laravel Horizon installations: one wall for every
application and environment, with alerts when queues stop draining.

> Status: early. Monitoring pages run on generated sample data; real Horizon polling,
> alerts and notifications are being built.

## Install

Requirements: Docker with Compose v2.

Once the image is published on GHCR, installing will be a matter of fetching
`compose.prod.yaml` and running `docker compose -f compose.prod.yaml up -d` — no clone
needed. Until then, `compose.prod.yaml` builds the image from a local clone:

```bash
git clone https://github.com/<owner>/horizon-watch.git
cd horizon-watch
docker compose -f compose.prod.yaml up -d --build
```

Open `http://localhost:8080`. The first visit asks you to create the administrator and
the first organization. Everyone else joins by invitation.

## Configuration

Everything is optional and set as environment variables (for example in a `.env` file
next to `compose.prod.yaml`):

| Variable                                                                                       | Default                 | Purpose                                      |
| ---------------------------------------------------------------------------------------------- | ----------------------- | -------------------------------------------- |
| `HORIZON_WATCH_PORT`                                                                           | `8080`                  | Port published on the host                   |
| `HORIZON_WATCH_WORKERS`                                                                        | `2`                     | Parallel queue workers (readings, email)     |
| `HORIZON_WATCH_RETENTION_DAYS`                                                                 | `30`                    | Days of readings kept before pruning         |
| `HORIZON_WATCH_ALERT_RETENTION_DAYS`                                                           | `90`                    | Days resolved alerts are kept before pruning |
| `HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS`                                                         | `false`                 | Refuse Horizon addresses on private networks |
| `APP_URL`                                                                                      | `http://localhost:8080` | Public URL, used in links and emails         |
| `APP_LOCALE`                                                                                   | `en`                    | Default language (`en` or `it`)              |
| `DB_PASSWORD`                                                                                  | `horizon_watch`         | PostgreSQL password; change it               |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | log only                | SMTP for invitations and alerts              |
| `APP_KEY`                                                                                      | generated               | Leave empty to generate one on first start   |

## Webhook

An organization can send its alerts to one webhook URL (Alert rules → Notifications). The
panel `POST`s JSON with these headers:

- `Content-Type: application/json`, `User-Agent: HorizonWatch`
- `X-Horizon-Watch-Timestamp`: the Unix time of the send
- `X-Horizon-Watch-Signature`: `sha256=` followed by the hex HMAC-SHA256 of
  `<timestamp>.<raw body>`, keyed with the organization's webhook secret

The secret is shown once, when the first URL is saved or when it is regenerated. Put any token
in the URL path: query strings and credentials in the URL are refused. The receiver has 5
seconds to answer with a 2xx; redirects are not followed, the response body is ignored, and a
failed delivery is tried three times (after 10 and 60 seconds) before it is logged as failed.

```json
{
    "event": "alert.opened",
    "alert": {
        "id": "01992f3c-5a4e-7b1d-9c2a-6d3e4f5a6b7c",
        "rule": "horizon.master_inactive",
        "severity": "critical",
        "value": 14,
        "threshold": 5,
        "unit": "min",
        "application": "Shop",
        "environment": "production",
        "opened_at": "2026-09-17T12:02:00Z",
        "resolved_at": null,
        "url": "https://horizon-watch.example.com/acme/environments/production"
    },
    "organization": { "name": "Acme", "slug": "acme" },
    "sent_at": "2026-09-17T12:02:04Z"
}
```

`event` is `alert.opened`, `alert.repeated` or `alert.resolved` (critical alerts),
`alert.digest` (warnings, every 15 minutes, with an `alerts` list instead of `alert`) or
`test` (with `"alert": null`). `url` is `null` once the environment is deleted.

Verify the signature before trusting the body, and reject old timestamps:

```php
$body = file_get_contents('php://input');
$timestamp = (int) ($_SERVER['HTTP_X_HORIZON_WATCH_TIMESTAMP'] ?? 0);
$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, getenv('HORIZON_WATCH_WEBHOOK_SECRET'));

if (abs(time() - $timestamp) > 300
    || ! hash_equals($expected, $_SERVER['HTTP_X_HORIZON_WATCH_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
```

This is what `App\Externals\Webhook\Signature::sign()` computes.

## Back up the data volume

The `app-data` volume holds the application key, which encrypts the Horizon credentials
you store. Losing it makes those credentials unreadable. Back up both volumes:
`app-data` and `postgres-data`.

## Update

Once the image is published on GHCR:

```bash
docker compose -f compose.prod.yaml pull
docker compose -f compose.prod.yaml up -d
```

Until then, pull the latest clone and rebuild instead: `git pull && docker compose -f
compose.prod.yaml up -d --build`.

Migrations run automatically when the `web` container starts.

## Development

```bash
composer install
./vendor/bin/sail up -d
./vendor/bin/sail composer run setup
./vendor/bin/sail composer run dev
```

`composer run setup` copies `.env.example` to `.env`, generates `APP_KEY`, migrates the
database and builds the front-end assets — running the commands separately and skipping
`key:generate` is the most common reason the first request fails.

Sail also starts Mailpit, a dev-only mail catcher: with the default `.env.example` values
(`MAIL_MAILER=smtp` to `mailpit:1025`) every email sent by the app — invitations, password
resets — lands in its UI at `http://localhost:8025` instead of a real inbox.

Checks: `./vendor/bin/sail composer check` (Pint, Larastan, Pest) and
`./vendor/bin/sail npm run types:check`.

## License

MIT
