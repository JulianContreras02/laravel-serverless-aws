#!/usr/bin/env bash
#
# Builds build/lambda.zip, the code of both Lambda functions (web and artisan):
# production dependencies only, Laravel's route and event caches, and the RDS
# CA bundle used to verify the database's TLS certificate.
#
# Also writes build/bref-layer-version: the Bref layer that matches the
# bref/bref version locked in composer.lock, so PHP code and runtime never drift.
#
# Usage: AWS_REGION=us-east-1 LAMBDA_ARCHITECTURE=arm64 scripts/package-lambda.sh

set -euo pipefail

cd "$(dirname "$0")/.."

region="${AWS_REGION:-us-east-1}"
architecture="${LAMBDA_ARCHITECTURE:-arm64}"
build="build"
package="$build/package"
# Lambda's limit for a zip uploaded directly (without S3).
max_zip_bytes=$((50 * 1024 * 1024))

rm -rf "$build"
mkdir -p "$package"

# Only what runs in Lambda: no tests, docs, infrastructure, legacy code or .env files.
cp -R app bootstrap config database public routes php artisan composer.json composer.lock "$package/"
rm -f "$package"/bootstrap/cache/*.php
mkdir -p "$package"/storage/{app,framework/cache,framework/views,logs}

(
    cd "$package"
    composer install --no-dev --classmap-authoritative --no-interaction --no-progress --prefer-dist

    # Routes and events do not depend on environment variables, so they are
    # cached at build time: a fresh cache on every deploy can never be stale.
    # The config is NOT cached here: it would freeze this machine's environment.
    # Bref caches it on each cold start, with the Lambda's variables.
    php artisan route:cache --no-interaction
    php artisan event:cache --no-interaction
)

curl -fsSL --retry 3 https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem \
    -o "$package/rds-ca-bundle.pem"

layer="php-84"
if [ "$architecture" = "arm64" ]; then
    layer="arm-php-84"
fi

LAYER="$layer" REGION="$region" php -r '
    $layers = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    $version = $layers[getenv("LAYER")][getenv("REGION")] ?? null;
    if ($version === null) {
        fwrite(STDERR, "No Bref layer ".getenv("LAYER")." in ".getenv("REGION")."\n");
        exit(1);
    }
    echo $version;
' "$package/vendor/bref/bref/layers.json" > "$build/bref-layer-version"

if command -v zip > /dev/null; then
    (cd "$package" && zip -qr9 ../lambda.zip .)
else
    # Fallback for images without the zip binary (e.g. bref/php-84-dev).
    php -r '
        $zip = new ZipArchive();
        $zip->open($argv[2], ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $root = realpath($argv[1]);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($root) + 1));
        }
        $zip->close();
    ' "$package" "$build/lambda.zip"
fi

zip_bytes=$(wc -c < "$build/lambda.zip")
if [ "$zip_bytes" -gt "$max_zip_bytes" ]; then
    echo "lambda.zip is $zip_bytes bytes, above the 50 MB direct upload limit." >&2
    exit 1
fi

unzipped_kb=$(du -sk "$package" | cut -f1)
echo "Built $build/lambda.zip: $((zip_bytes / 1024 / 1024)) MB zipped, $((unzipped_kb / 1024)) MB unzipped (limit 250 MB with layers)."
echo "Bref layer: $layer:$(cat "$build/bref-layer-version") in $region"
