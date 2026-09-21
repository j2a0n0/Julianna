# Contributing to Julianna

Julianna accepts focused changes through feature branches and pull requests in its public source repository.

1. Branch from the default branch using `feature/…`, `fix/…`, or `docs/…`.
2. Preserve the AGPL license and attribution notices.
3. Add or update tests for behavior changes.
4. Run the relevant unit and acceptance tests, `make test-code-style`, `make phpstan`, and `npm run release:scan`.
5. Include screenshots for visible interface changes and document deployment-facing changes.

English and Swiss French are the supported interface languages for the initial Julianna release. Update both catalogs when adding user-visible strings.

Report ordinary bugs through the issue tracker associated with `JULIANNA_SOURCE_URL`. Follow [SECURITY.md](SECURITY.md) for vulnerabilities.

Internal compatibility names such as the `Leantime\` PHP namespace, the `leantime` JavaScript global, and `zp_*` tables remain intentionally unchanged for the first Julianna release.
