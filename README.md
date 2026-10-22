# Shift Pay

A personal, self-hosted shift and payout tracker. One PHP 8.3 app, one Docker container, SQLite on a volume. Amounts, times and dates use Czech formats (`2 250,00 Kč`, 24-hour time); the interface language is English.

## Start

```bash
docker compose up -d
```

Open <http://localhost:8080>. Settings come from environment variables (set them in your shell or an `.env` file next to `docker-compose.yml`):

| variable | default | purpose |
|---|---|---|
| `TZ` | `Europe/Prague` | server time zone |
| `SETUP_TOKEN` | empty | if set, `/setup` also asks for it |
| `SESSION_DAYS` | `365` | how long a "keep me signed in" device stays signed in |
| `TRUST_PROXY` | `false` | set `true` behind a TLS reverse proxy so cookies get `Secure` |

## First run

While no account exists, every page redirects to `/setup`. Choose a password (at least 10 characters). With `SETUP_TOKEN` set, enter it as well, so a stranger who finds a fresh instance cannot claim it. After that `/setup` returns 404, and the app asks for your hourly wage.

You sign in once per device. A device in regular use stays signed in; sign out single devices under Settings → Account.

### Forgot the password

```bash
docker compose exec shiftpay php bin/reset-password.php
```

This sets a new password and signs out every device. Use `docker compose exec -it` if your terminal doesn't show the prompt.

## Behind a reverse proxy

Set `TRUST_PROXY=true`. Keep the original `Host` header (the proxy default).

Caddy:

```
shifts.example.com {
    reverse_proxy shiftpay:80
}
```

Traefik (labels on the service):

```yaml
labels:
  - traefik.enable=true
  - traefik.http.routers.shiftpay.rule=Host(`shifts.example.com`)
  - traefik.http.routers.shiftpay.tls.certresolver=letsencrypt
  - traefik.http.services.shiftpay.loadbalancer.server.port=80
```

Remove the `ports` entry from the compose file if the proxy reaches the container over a shared Docker network.

## Backup and restore

In the app: Settings → Data → Export JSON backup / Import JSON backup (import replaces all shifts and the wage settings). CSV export is there too.

Volume level:

```bash
# backup
docker compose stop shiftpay
docker run --rm -v shiftpay_shiftpay-data:/data -v "$PWD":/backup alpine tar czf /backup/shiftpay-data.tgz -C /data .
docker compose start shiftpay

# restore (into an empty volume)
docker compose down
docker run --rm -v shiftpay_shiftpay-data:/data -v "$PWD":/backup alpine sh -c "cd /data && tar xzf /backup/shiftpay-data.tgz"
docker compose up -d
```

The volume name is prefixed with the compose project name (usually the folder name); check with `docker volume ls`.

## Tests

The pay calculation tests need only PHP:

```bash
php tests/run.php
```

## Layout

`public/` is the web root. `src/`, `migrations/`, `bin/` and `/data` are not reachable by URL. Migrations in `migrations/*.sql` are applied automatically on startup.
