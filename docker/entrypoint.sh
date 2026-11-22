#!/bin/sh
set -eu

DATA_DIR="${REGIS_DATA_DIR:-/var/www}"
mkdir -p "$DATA_DIR/database" "$DATA_DIR/storage/scans"

if [ "$DATA_DIR" != "/var/www" ] && [ ! -f "$DATA_DIR/database/regis.sqlite" ] && [ -f /var/www/database/regis.sqlite ]; then
    cp /var/www/database/regis.sqlite "$DATA_DIR/database/regis.sqlite"
    php -r '
        $file = getenv("REGIS_DATA_DIR") . "/database/regis.sqlite";
        $pdo = new PDO("sqlite:" . $file);
        $pdo->exec("UPDATE users SET session_token = NULL, session_seen_at = NULL, failed_logins = 0, locked_until = NULL");
    ' || true
    echo "Seeded database from image."
fi

chown -R www-data:www-data "$DATA_DIR" /var/www/storage
chmod 775 "$DATA_DIR" "$DATA_DIR/database" "$DATA_DIR/storage" "$DATA_DIR/storage/scans"

PORT="${PORT:-8080}"
PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

exec su -s /bin/sh www-data -c "REGIS_DATA_DIR='$DATA_DIR' PHP_CLI_SERVER_WORKERS='$PHP_CLI_SERVER_WORKERS' php -S 0.0.0.0:${PORT} -t /var/www /var/www/router.php"
