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
| `HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS`                                                         | `false`                 | Refuse Horizon addresses on private networks |
| `APP_URL`                                                                                      | `http://localhost:8080` | Public URL, used in links and emails         |
| `APP_LOCALE`                                                                                   | `en`                    | Default language (`en` or `it`)              |
| `DB_PASSWORD`                                                                                  | `horizon_watch`         | PostgreSQL password; change it               |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | log only                | SMTP for invitations and alerts              |
| `APP_KEY`                                                                                      | generated               | Leave empty to generate one on first start   |

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
