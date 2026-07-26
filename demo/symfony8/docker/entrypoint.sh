#!/bin/sh
set -e

# Wait until Composer has installed the app (make up runs install after start).
i=0
while [ ! -f /app/vendor/autoload_runtime.php ]; do
	i=$((i + 1))
	if [ "$i" -gt 120 ]; then
		echo "Timed out waiting for /app/vendor/autoload_runtime.php — run composer install." >&2
		exit 1
	fi
	echo "Waiting for Composer vendor tree... ($i)"
	sleep 1
done

# FRANKENPHP_MODE: classic | worker. Default: worker (REQ-DEMO-010).
MODE="${FRANKENPHP_MODE:-worker}"
case "$MODE" in
	classic)
		cp /etc/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile
		;;
	worker)
		;;
	*)
		echo "Unknown FRANKENPHP_MODE=$MODE (expected classic|worker)" >&2
		exit 1
		;;
esac
echo "FrankenPHP mode: $MODE"

mkdir -p /app/var/cache /app/var/log
chmod -R 777 /app/var
exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
