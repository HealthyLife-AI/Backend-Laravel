#!/bin/sh
# Starts the web process (see the Procfile). Kept as a script so the Procfile stays a plain command
# whatever shell, or none, the platform runs it with.
#
# 1. `php artisan optimize` (config, routes, events, views) runs HERE, when the web container starts,
#    not in the release phase: Dokku runs `release` in a throwaway container, so files written there
#    never reach the container that serves requests. If it fails the app still starts, uncached.
# 2. OPcache is switched on for this process only: the built-in server runs under the CLI SAPI, where
#    it is off by default. The empty first entry keeps PHP's own default scan directory, so the
#    extensions loaded from there stay loaded.
# 3. PHP_CLI_SERVER_WORKERS (environment) sets the worker count; `--no-reload` is what lets
#    `artisan serve` honour it, and it passes the whole environment on to the workers.
cd "$(dirname "$0")/.." || exit 1

php artisan optimize || echo "WARNING: php artisan optimize failed; serving without cached config and routes."

PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-}:$(pwd)/.php"
export PHP_INI_SCAN_DIR

# Say in the deploy log whether OPcache really is on for this process (a build whose PHP ignores
# PHP_INI_SCAN_DIR, or has no opcache extension, would otherwise be slow without any sign of it).
if [ "$(php -r 'echo (int) ini_get("opcache.enable_cli");')" = "1" ]; then
    echo "OPcache is on for the web process."
else
    echo "WARNING: OPcache is NOT on for the web process (opcache.enable_cli is not 1). Check that the opcache extension is installed and that nothing overrides PHP_INI_SCAN_DIR."
fi
echo "PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS:-1 (unset)}"

exec php artisan serve --host=0.0.0.0 --port="${PORT:-5000}" --no-reload
