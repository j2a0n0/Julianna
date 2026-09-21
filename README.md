<p align="center">
  <img src="public/assets/images/logo_blue.svg" alt="Julianna" width="320">
</p>

# Julianna

Julianna is a self-hosted project and work management platform for focused teams. It combines projects, goals, milestones, tasks, calendars, documentation, time tracking, reports, and local extensions in one workspace.

This repository is a permanent, independently maintained fork of Leantime. Julianna does not use Leantime-hosted telemetry, news, marketplace, licensing, support, or update services. Releases are built directly from this source tree.

## Requirements

- PHP 8.2 or newer with the extensions declared in `composer.json`
- MySQL 8.4
- Node.js 18 or newer for frontend builds
- Composer 2

## Development

1. Copy `config/sample.env` to `config/.env` and configure the database.
2. Install and build dependencies with `make build-dev`.
3. Start the local stack with `make run-dev`.
4. Open the configured `JULIANNA_APP_URL`.

Before producing a release, run:

```bash
npm run release:scan
composer validate --no-check-publish
```

Production deployments must set an HTTPS `JULIANNA_SOURCE_URL` pointing to the exact public source tag for the deployed build. See `config/sample.env` for all supported `JULIANNA_*` settings.

## Extensions

Julianna retains the local plugin architecture. There is no remote marketplace and no support for proprietary Leantime marketplace packages. Add reviewed, source-available plugins under `app/Plugins` and manage them from the local Plugins screen.

## License and attribution

Julianna is licensed under the GNU Affero General Public License v3.0 only. Network users must be offered the complete corresponding source for the version they are using.

Julianna is derived from Leantime at commit `056835f1c29cf1f587415e6c45ddaf8d69f8d24c`. Original copyright and license history are retained. See [LICENSE](LICENSE) and [NOTICE.md](NOTICE.md).

Julianna is an independent project and is not affiliated with or endorsed by the Leantime project or its owners.
