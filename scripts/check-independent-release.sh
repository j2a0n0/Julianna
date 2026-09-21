#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${repository_root}"

fail() {
    printf 'Julianna independence check failed: %s\n' "$1" >&2
    exit 1
}

sha256_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}

for required_file in LICENSE NOTICE.md README.md composer.json package.json .docker/Dockerfile .docker/start.sh; do
    test -f "${required_file}" || fail "missing ${required_file}"
done

test ! -e .gitmodules || fail '.gitmodules must not exist'
test ! -e bin/leantime || fail 'the upstream CLI entry point bin/leantime still exists'

for forbidden_public_asset in \
    public/tiptap-test.html \
    public/assets/js/app/core/tiptap/test-utils.js \
    public/assets/js/libs/simple-color-picker-master/spec-runner.html \
    public/assets/images/Screenshots; do
    test ! -e "${forbidden_public_asset}" \
        || fail "development or upstream-branded public asset remains: ${forbidden_public_asset}"
done

while read -r expected_hash brand_asset; do
    test -f "${brand_asset}" || fail "missing Julianna brand asset: ${brand_asset}"
    actual_hash="$(sha256_file "${brand_asset}")"
    test "${actual_hash}" = "${expected_hash}" \
        || fail "unexpected or stale binary brand asset: ${brand_asset}"
done <<'BRAND_ASSETS'
c2afb2b8dde6eaa772176bf64dbd7578764d28df8b0dc6579596b7ad2953e34d public/favicon.ico
92ea3610f66b8f5da596b4ddafe005bd3cd124e15b893d74cb4028b0b0495868 public/assets/images/favicon.png
f46e742e016c5f82726f97aaeb1e3c8042a5793cabe70d85c817dc836514b8fc public/assets/images/apple-touch-icon.png
097ab64d4dccf17c44b2356e6e30735ce7521c1bfeb7229829c7ae1a2cc291dc public/assets/images/icon-192.png
fc2400b3e1220dafc5d8db503a4dc1d5f6a1f1b8497a4cf82e4c2e9c27c328d8 public/assets/images/icon-512.png
106b480e099c77b000bfa970cf46194cfea1cb03dd2e57f5b7e2071ae1df5a9c public/assets/images/logo.png
aaf698ff482ed13c760959582ce5656c44c2b01dddb8e110488684ba52b8a7ee public/assets/images/logo_blue.png
BRAND_ASSETS

if git ls-files -s | grep -q '^160000 '; then
    git ls-files -s | grep '^160000 ' >&2
    fail 'git submodules are not permitted'
fi

grep -q '"name": "julianna/julianna"' composer.json \
    || fail 'Composer package name is not julianna/julianna'
grep -q '"name": "julianna"' package.json \
    || fail 'npm package name is not julianna'
grep -q 'Fork point: Leantime commit `056835f1c29cf1f587415e6c45ddaf8d69f8d24c`' NOTICE.md \
    || fail 'NOTICE.md does not identify the recorded fork point'
grep -q "SUPPORTED_LANGUAGES = \['en-US', 'fr-CH'\]" app/Core/Language.php \
    || fail 'the supported language set must be English and Swiss French only'

scan_roots=(
    app
    public/assets
    config
    .docker
    .dev
    helm
    bin
    tests
    .github
    README.md
    SECURITY.md
    CONTRIBUTING.md
    accessibility-mockup.html
    nginx.example.conf
    nginx-subfolder.example.conf
    phpdoc.xml
    phpdoc-api.xml
    composer.json
    package.json
    makefile
)

if rg -n '\bLEAN_[A-Z0-9_]+\b' "${scan_roots[@]}" \
    --glob '!app/Language/**' \
    --glob '!*.map' \
    --glob '!*.min.js' \
    --glob '!*.min.css'; then
    fail 'legacy LEAN_* deployment variables remain'
fi

if rg -n -i 'leantime' \
    accessibility-mockup.html \
    nginx.example.conf \
    nginx-subfolder.example.conf \
    phpdoc.xml \
    phpdoc-api.xml; then
    fail 'upstream branding remains in source documentation or deployment examples'
fi

if rg -n -i \
    '(https?://[^[:space:]'"'"'"<>)]*leantime|support@leantime|marketplace\.leantime|telemetry\.leantime|ltmp-api\.leantime|FROM[[:space:]]+leantime/|leantime/leantime:[[:alnum:]_.-]+|github\.com/Leantime/leantime/releases/download)' \
    "${scan_roots[@]}" \
    --glob '!app/Language/**' \
    --glob '!app/Core/Shims/McpServiceProvider.php' \
    --glob '!*.map' \
    --glob '!*.min.js' \
    --glob '!*.min.css'; then
    fail 'an executable or test reference to a Leantime-owned service/image remains'
fi

for catalog in app/Language/en-US.ini app/Language/fr-CH.ini; do
    branded_values="$(awk -F= '
        /^[[:space:]]*[#;]/ { next }
        {
            key=$1
            value=$0
            sub(/^[^=]*=/, "", value)
            if (key !~ /^[[:space:]]*about\.(summary|independence)[[:space:]]*$/ && tolower(value) ~ /leantime/) {
                print FNR ":" $0
            }
        }
    ' "${catalog}")"

    if test -n "${branded_values}"; then
        printf '%s\n' "${catalog}:${branded_values}" >&2
        fail "user-visible upstream branding remains in ${catalog}"
    fi
done

if rg -n -i \
    '(Welcome to Leantime|Leantime is built|inside Leantime|news about Leantime|Open in Leantime|Powered by Leantime|Leantime System|Leantime API|Leantime App Version|Leantime Db Version|Leantime DB Version)' \
    app/Domain app/Views public/assets \
    --glob '*.blade.php' \
    --glob '*.php' \
    --glob '*.js' \
    --glob '*.svg' \
    --glob '!*.min.js'; then
    fail 'known user-visible Leantime branding remains'
fi

if rg -n -i \
    '(description[[:space:]]*=[[:space:]]*"Leantime|author:[[:space:]]*"Leantime|['"'"']name['"'"'][[:space:]]*=>[[:space:]]*['"'"']Leantime['"'"']|['"'"']leantime['"'"'])' \
    app/Core/Application/AppServiceProvider.php \
    app/Core/Mailer.php \
    app/Core/UI/Theme.php \
    app/Domain/ContentTemplates/Library \
    public/theme; then
    fail 'user-visible theme, content-template, CLI, or mail branding remains'
fi

if rg -n -i '(telemetry|marketplace|news|support|update)[^\n]{0,80}(leantime\.io|leantime\.ai)' \
    app public/assets config .docker .dev helm \
    --glob '!app/Language/**' \
    --glob '!*.map' \
    --glob '!*.min.js' \
    --glob '!*.min.css'; then
    fail 'an upstream remote-service reference remains'
fi

grep -q 'JULIANNA_SOURCE_URL' app/Core/Configuration/Environment.php \
    || fail 'runtime source URL configuration is missing'
grep -q 'JULIANNA_SOURCE_URL must be the public HTTPS URL' .docker/start.sh \
    || fail 'production source URL startup validation is missing'
grep -q "config->sourceUrl" app/Views/Composers/Footer.php \
    || fail 'the footer does not expose the configured source URL'
grep -q "default-src 'self';" .docker/config/nginx.conf \
    || fail 'the production CSP is missing a self-only default policy'
grep -q '"default-src '\''self'\''"' app/Core/Middleware/InitialHeaders.php \
    || fail 'the application CSP is missing a self-only default policy'
grep -q '"frame-ancestors '\''none'\''"' app/Core/Middleware/InitialHeaders.php \
    || fail 'the application CSP permits third-party framing'
if rg -n 'unpkg\.com' app/Core/Middleware/InitialHeaders.php; then
    fail 'the application CSP still permits an unused remote script or font host'
fi

if awk '/^FROM / && $2 !~ /@sha256:/ { print; found=1 } END { exit found ? 0 : 1 }' .docker/Dockerfile; then
    fail 'a mutable, unpinned Docker base image remains'
fi
grep -Eq '^COPY --from=composer:2@sha256:[a-f0-9]{64} ' .docker/Dockerfile \
    || fail 'the Composer build image is not pinned by digest'

if git remote -v 2>/dev/null | rg -i 'github\.com[:/]Leantime/leantime(?:\.git)?([[:space:]]|$)'; then
    fail 'the upstream Leantime repository is still configured as a git remote'
fi

printf 'Julianna independence check passed.\n'
