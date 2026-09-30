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

## Agent and Whiteboard

The authenticated `/agent` command center is Julianna's primary workspace.
The assistant drawer is available throughout the application. The agent uses
the same first-party permission-checked tool catalog as `/mcp`. A project must
be selected before the assistant can write to it; enabling autopilot permits
background upkeep, while pausing it stops autonomous work. Direct, permitted
requests still work with autopilot off or paused. Clear actions execute without a
per-action approval. Communications remain drafts until a PM reviews them.
Run history and recoverable Whiteboard changes are visible on the command
center. Existing Idea Room rooms and canvases remain readable under
`/agent/archive`; their old mutation endpoints and MCP tools are retired.

After signing in as the installation owner with MFA, open `/agent/settings` to
choose OpenAI, Anthropic, DeepSeek, or Kimi, select a suggested API model (or
enter a custom model ID), and paste its provider API key. The suggested list is
not exhaustive; model availability depends on the provider account. The key is
submitted over HTTPS (or loopback HTTP for
local development), encrypted in the database with `JULIANNA_APP_KEY`, and
never displayed again. A blank key field preserves the saved key only when the
provider is unchanged. The connection check sends a fixed test message with no
workspace data or tools; it can incur a small provider charge. Choose a model
available to your provider account. The connector is installation-wide, while
project agent activation and pause controls remain separate.

Alternatively, set `JULIANNA_AI_PROVIDER`, `JULIANNA_AI_MODEL`, and
`JULIANNA_AI_API_KEY` on the server. For container secrets, mount a readable
file and set `JULIANNA_AI_API_KEY_FILE` to its path. Website settings take
precedence over those environment values; disabling the website connector
also masks environment fallback. Preserve `JULIANNA_APP_KEY` during upgrades
and backup restoration or saved credentials cannot be decrypted. Use a
provider API key, not a consumer-chat password. The old Idea Room temporary
browser key does not power the agent.
`JULIANNA_AI_REQUESTS_PER_HOUR` caps new in-app requests per user (default 20);
idempotent HTTP replays do not consume another request.

For optional live internet research, the installation owner can paste a Brave
Search API key under `/agent/settings`. It is encrypted with `JULIANNA_APP_KEY`
and never displayed again. Alternatively, set `JULIANNA_WEB_SEARCH_API_KEY` on
the server and recreate the app container. Website settings take precedence,
and disabling them masks the environment fallback. This is a separate key from
the AI model provider key. When configured, the agent and
human Bearer-token MCP clients receive read-only `searchWeb` and `researchWeb`
tools. The former returns up to five public search-result snippets; the latter
returns short source-linked extracts from up to three pages. Neither tool
fetches model-supplied URLs, logs in, posts, or browses private networks. They
reject email addresses and credential-like queries, limit each user to 20
searches per hour, and are absent from the catalog when the key is missing. Search
queries are sent to Brave; avoid including confidential project information.

Project Whiteboards are saved locally through Julianna's own service and
revision-checked APIs. Excalidraw editor assets are bundled in the image;
the first release does not provide live co-editing or cloud collaboration.
Personal Bearer tokens can use Whiteboard MCP tools. Legacy `x-api-key`
principals do not gain Whiteboard access.

## Secret files

The entrypoint accepts Docker/Kubernetes secret files for sensitive settings.
Set a variable such as `JULIANNA_DB_PASSWORD_FILE=/run/secrets/db_password`;
the file value takes precedence over `JULIANNA_DB_PASSWORD`. Supported file
variables cover the app key, database, SMTP, Redis, and S3 credentials.
