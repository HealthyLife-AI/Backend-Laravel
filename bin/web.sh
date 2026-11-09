#!/bin/sh
# Starts the web process (see the Procfile). Kept as a script so the Procfile stays a plain command
# whatever shell, or none, the platform runs it with.
#
# 1. `php artisan optimize` (config, routes, events, views) runs HERE, when the web container starts,
#    not in the release phase: Dokku runs `release` in a throwaway container, so files written there
#    never reach the container that serves requests. If it fails the app still starts, uncached.
#
# 2. OPcache is switched on for this process only: the built-in server runs under the CLI SAPI, where
#    it is off by default. It is loaded from .php/opcache.ini through PHP_INI_SCAN_DIR.
#
#    On Nixpacks `php` is the nixpkgs wrapper, which sets PHP_INI_SCAN_DIR itself (as a default, so only
#    when the variable is unset) to the directory holding the ini files that load pdo_mysql, mbstring,
#    openssl, opcache... and nixpkgs PHP has no compiled-in scan directory. Exporting our own value
#    blindly would therefore start the web process WITHOUT its extensions. So the value PHP itself
#    would use is read first, ours is added after it, and the result is checked before it is used: if a
#    required extension would be missing, the server starts as it would without this file (no OPcache).
#
# 3. PHP_CLI_SERVER_WORKERS (environment) sets the worker count; `--no-reload` is what lets
#    `artisan serve` honour it, and it passes the whole environment on to the workers.
cd "$(dirname "$0")/.." || exit 1

php artisan optimize || echo "WARNING: php artisan optimize failed; serving without cached config and routes."

# What PHP would scan on its own: the wrapper's default, or a value already in the environment.
BASE="$(php -r 'echo getenv("PHP_INI_SCAN_DIR") ?: "";')"
SCAN_DIR="${BASE}:$(pwd)/.php"

echo "PHP_INI_SCAN_DIR as PHP has it: '${BASE}'"
echo "PHP_INI_SCAN_DIR with OPcache:  '${SCAN_DIR}'"

# The extensions Laravel needs, and any `ext-*` composer.json asks for, must still load with the new value.
MISSING="$(PHP_INI_SCAN_DIR="$SCAN_DIR" php -r '
$need = ["pdo_mysql", "mbstring", "openssl", "tokenizer", "ctype"];
$composer = json_decode((string) @file_get_contents("composer.json"), true);
foreach (array_keys($composer["require"] ?? []) as $package) {
    $name = str_starts_with($package, "ext-") ? str_replace("-", "_", substr($package, 4)) : null;
    if ($name !== null && !in_array($name, ["json", "zend_opcache", "opcache"], true)) {
        $need[] = $name;
    }
}
echo implode(" ", array_filter(array_unique($need), fn ($ext) => !extension_loaded($ext)));
')"

if [ -n "$MISSING" ]; then
    # Not exported: the process keeps exactly the environment it has today.
    echo "WARNING: with that scan directory these PHP extensions would not load:${MISSING:+ }${MISSING}. Starting WITHOUT OPcache, as before this file existed."
else
    echo "Extension check with that scan directory: all required extensions load."
    PHP_INI_SCAN_DIR="$SCAN_DIR"
    export PHP_INI_SCAN_DIR

    # Say in the deploy log whether OPcache really is on for this process.
    if [ "$(php -r 'echo (int) ini_get("opcache.enable_cli");')" = "1" ]; then
        echo "OPcache is on for the web process."
    else
        echo "WARNING: OPcache is NOT on for the web process (opcache.enable_cli is not 1). Is the opcache extension installed?"
    fi
fi

echo "PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS:-1 (unset)}"

exec php artisan serve --host=0.0.0.0 --port="${PORT:-5000}" --no-reload
