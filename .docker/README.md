# Julianna container deployment

This image is built from the checked-out Julianna source. The build runs
Composer and npm in dedicated build stages and copies their outputs into a
non-root PHP 8.3 runtime. It does not download an application release archive
or inherit from another application image.

## Local rollout

From the repository root:

```sh
cp .docker/.env.example .docker/.env
```

Generate unique values for `JULIANNA_APP_KEY`, `JULIANNA_DB_PASSWORD`, and
`JULIANNA_DB_ROOT_PASSWORD`, then start the stack:

```sh
docker compose --env-file .docker/.env -f .docker/docker-compose.yml build --pull
docker compose --env-file .docker/.env -f .docker/docker-compose.yml up -d
```

The resulting application image is named
`julianna/app:${JULIANNA_VERSION}`. MySQL data, uploaded files, local plugins,
and server-side sessions/logs are kept in named volumes.
The app port binds to `127.0.0.1` by default. Keep that setting for a local
install or a reverse proxy on this host; explicitly set
`JULIANNA_BIND_ADDRESS` only when another network interface is intended.

## Production release requirements

Before building a production tag:

1. Tag the exact public source revision being built.
2. Set `JULIANNA_ENV=production`.
3. Set `JULIANNA_APP_URL` to the public HTTPS application URL.
4. Set `JULIANNA_SOURCE_URL` to the public HTTPS URL for that exact source tag.
5. Set `JULIANNA_VERSION` and `JULIANNA_COMMIT` to the release identity.
6. Set `JULIANNA_SESSION_SECURE=true` and deploy behind an HTTPS reverse proxy.
7. Configure and validate SMTP before enabling public registration.

Production startup rejects a missing/non-HTTPS source URL, a weak application
key, and insecure session cookies. Keep the prior application image and a
verified database/volume backup as rollback artifacts.

The compose file exposes port 8080 for the reverse proxy and does not terminate
TLS itself. Optional services such as SMTP, Redis, S3, Slack, Telegram, and
Sentry remain disabled unless corresponding `JULIANNA_*` values are supplied.

## Idea Room AI

Idea Room is available to authenticated users at `/idea-room`. It shows a setup
message until `JULIANNA_AI_PROVIDER` (`openai` or `anthropic`),
`JULIANNA_AI_MODEL`, and `JULIANNA_AI_API_KEY` are configured. Configure these
only on the server; the browser never receives credentials. For container
secrets, mount a readable file and set `JULIANNA_AI_API_KEY_FILE` to its path.
Provider requests are made only when a user sends a message; approving a plan
does not call the provider.

## Secret files

The entrypoint accepts Docker/Kubernetes secret files for sensitive settings.
Set a variable such as `JULIANNA_DB_PASSWORD_FILE=/run/secrets/db_password`;
the file value takes precedence over `JULIANNA_DB_PASSWORD`. Supported file
variables cover the app key, database, SMTP, Redis, and S3 credentials.
