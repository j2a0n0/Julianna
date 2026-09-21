#!/bin/sh
set -eu

load_secret_file() {
    variable_name="$1"
    file_variable="${variable_name}_FILE"
    file_path="$(printenv "${file_variable}" 2>/dev/null || true)"

    if [ -z "${file_path}" ]; then
        return
    fi

    if [ ! -r "${file_path}" ]; then
        echo "Julianna startup: ${file_variable} is not readable" >&2
        exit 1
    fi

    export "${variable_name}=$(cat "${file_path}")"
    unset "${file_variable}"
}

# Docker/Kubernetes secret-file support. Plain JULIANNA_* values remain valid,
# but a matching *_FILE value takes precedence when supplied.
load_secret_file JULIANNA_APP_KEY
load_secret_file JULIANNA_DB_HOST
load_secret_file JULIANNA_DB_DATABASE
load_secret_file JULIANNA_DB_USER
load_secret_file JULIANNA_DB_PASSWORD
load_secret_file JULIANNA_EMAIL_SMTP_USERNAME
load_secret_file JULIANNA_EMAIL_SMTP_PASSWORD
load_secret_file JULIANNA_REDIS_PASSWORD
load_secret_file JULIANNA_S3_KEY
load_secret_file JULIANNA_S3_SECRET

if [ "${JULIANNA_ENV:-production}" = "production" ]; then
    if ! php -r '
        $url = (string) getenv("JULIANNA_SOURCE_URL");
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        exit(filter_var($url, FILTER_VALIDATE_URL) !== false && $scheme === "https" ? 0 : 1);
    '; then
        echo "Julianna startup: JULIANNA_SOURCE_URL must be the public HTTPS URL for this exact release" >&2
        exit 1
    fi

    if ! php -r '
        $key = (string) getenv("JULIANNA_APP_KEY");
        if (str_starts_with($key, "base64:")) {
            $key = base64_decode(substr($key, 7), true);
        }
        exit(is_string($key) && strlen($key) >= 32 ? 0 : 1);
    '; then
        echo "Julianna startup: JULIANNA_APP_KEY must contain at least 32 random bytes" >&2
        exit 1
    fi

    if [ "${JULIANNA_SESSION_SECURE:-true}" != "true" ]; then
        echo "Julianna startup: JULIANNA_SESSION_SECURE must be true in production" >&2
        exit 1
    fi
fi

mkdir -p \
    /run \
    /var/www/html/bootstrap/cache \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
