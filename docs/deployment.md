# Production deployment

This repository ships a production-oriented Docker Compose stack for the Laravel API, queue worker, scheduler, MySQL, Redis, Nginx, and the Nuxt SSR console. Put a TLS reverse proxy such as Caddy, Traefik, or a managed load balancer in front of the exposed API and console ports.

The example binds published ports to `127.0.0.1` for a reverse proxy on the same host. Set `IDENTITY_BIND_ADDRESS=0.0.0.0` only when a remote proxy or load balancer must reach the ports, and restrict access with the host firewall.

## Prerequisites

- Docker Engine with Docker Compose v2
- Two HTTPS hostnames: one for the API and one for the console
- A secret manager for the production environment file
- SMTP or another configured Laravel mail transport

## First deployment

1. Copy `.env.production.example` to `.env.production` outside source control. Generate unique database passwords, a Laravel `APP_KEY`, a 32+ character Nuxt session password, and a 64-character hosted-applications internal token.
2. Build and start the stateful services:

   ```bash
   docker compose --env-file .env.production up -d mysql redis
   ```

3. Run the database migration:

   ```bash
   docker compose --env-file .env.production --profile tools run --rm migrate
   ```

4. Create the installation owner and Identity Console confidential client. Store the printed client secret only in your secret manager, then set `IDENTITY_CLIENT_ID` and `IDENTITY_CLIENT_SECRET` in `.env.production`:

   ```bash
   docker compose --env-file .env.production run --rm api \
     php artisan identity:bootstrap owner@example.com --name="Installation owner"
   ```

5. Start the application services:

   ```bash
   docker compose --env-file .env.production up -d --build
   ```

6. Put HTTPS in front of the API and console ports. Configure the proxy to preserve `Host`, `X-Forwarded-For`, and `X-Forwarded-Proto` headers.

## Operations

- Run migrations with the `migrate` tools profile before every application deployment.
- Restart `queue` after each deployment so queued jobs use the new code.
- Back up `identity-mysql` and `identity-storage`; the storage volume includes hosted application logos.
- Monitor the API `/up` endpoint, failed queue jobs, mail delivery, webhook delivery failures, and authentication/audit events.
- Rotate client secrets, the internal hosted-applications token, and the Nuxt session secret after suspected exposure.

## Release checks

Run these before building an image or tagging a release:

```bash
./scripts/check-tracked-secrets.sh
pnpm check
pnpm build
pnpm security:audit
IDENTITY_ENV_FILE=.env.production.example docker compose --env-file .env.production.example config --quiet
```

The production template intentionally contains placeholders and must never be used without replacing them.
