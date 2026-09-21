# Julianna Helm chart

This chart deploys the independently built `julianna/app` container. It never
downloads application code or contacts an upstream product service.

Before installing, provide a private values file with at least:

```yaml
app:
  url: "https://projects.example.org"
  sourceUrl: "https://github.com/OWNER/julianna/tree/v1.0.0"
  commit: "FULL_GIT_COMMIT"
  session:
    password: "base64:AT_LEAST_32_RANDOM_BYTES"
    secure: true

mariadb:
  auth:
    rootPassword: "REPLACE_ME"
    password: "REPLACE_ME"
```

`app.sourceUrl` must identify the exact public source tag represented by the
container. Production startup rejects a missing or non-HTTPS source URL, a weak
application key, and insecure session cookies.

The chart pulls `julianna/app` at `appVersion` by default. Set `image.tag` to a
different published release tag when needed; Julianna's displayed version then
follows that tag (without a leading `v`). Set `app.sourceUrl` and `app.commit`
to the same release's public source and commit.

For S3 storage, set `app.s3.enabled: true` and provide `app.s3.endpoint` along
with the bucket and credentials.

Public registration defaults to disabled. To enable it, set
`app.registrationEnabled: true`, enable SMTP, and provide a sender, host, port,
and (when authentication is enabled) username and password. Julianna still
checks SMTP readiness before presenting the signup form.

```sh
helm dependency build ./helm
helm upgrade --install julianna ./helm -f values.private.yaml
```

Keep `values.private.yaml` out of version control. English (`en-US`) and Swiss
French (`fr-CH`) are the supported first-release locales.
