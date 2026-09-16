# Horizon Watch

A self-hosted control room for several Laravel Horizon installations: one wall for every
application and environment, with alerts when queues stop draining.

> Status: early. Monitoring pages run on generated sample data; real Horizon polling,
> alerts and notifications are being built.

## Install

Requirements: Docker with Compose v2.

```bash
curl -O https://raw.githubusercontent.com/<owner>/horizon-watch/main/compose.prod.yaml
docker compose -f compose.prod.yaml up -d
```

Open `http://localhost:8080`. The first visit asks you to create the administrator and
the first organization. Everyone else joins by invitation.

## Configuration

Everything is optional and set as environment variables (for example in a `.env` file
next to `compose.prod.yaml`):

| Variable                                                                                       | Default                 | Purpose                                    |
| ---------------------------------------------------------------------------------------------- | ----------------------- | ------------------------------------------ |
| `HORIZON_WATCH_PORT`                                                                           | `8080`                  | Port published on the host                 |
| `APP_URL`                                                                                      | `http://localhost:8080` | Public URL, used in links and emails       |
| `APP_LOCALE`                                                                                   | `en`                    | Default language (`en` or `it`)            |
| `DB_PASSWORD`                                                                                  | `horizon_watch`         | PostgreSQL password; change it             |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | log only                | SMTP for invitations and alerts            |
| `APP_KEY`                                                                                      | generated               | Leave empty to generate one on first start |

## Back up the data volume

The `app-data` volume holds the application key, which encrypts the Horizon credentials
you store. Losing it makes those credentials unreadable. Back up both volumes:
`app-data` and `postgres-data`.

## Update

```bash
docker compose -f compose.prod.yaml pull
docker compose -f compose.prod.yaml up -d
```

Migrations run automatically when the `web` container starts.

## Development

```bash
cp .env.example .env
composer install
./vendor/bin/sail up -d
./vendor/bin/sail npm install
./vendor/bin/sail artisan migrate
./vendor/bin/sail composer run dev
```

Checks: `./vendor/bin/sail composer check` (Pint, Larastan, Pest) and
`./vendor/bin/sail npm run types:check`.

## License

MIT
