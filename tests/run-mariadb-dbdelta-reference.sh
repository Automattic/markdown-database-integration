#!/usr/bin/env bash
# Run the shared dbDelta probe against a disposable MariaDB WordPress fixture.

set -euo pipefail

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
network=${MDI_DBDELTA_NETWORK:-mdi375-dbdelta}
database=${MDI_DBDELTA_DATABASE:-mdi375_dbdelta_reference}
mariadb_container=${MDI_DBDELTA_MARIADB_CONTAINER:-mdi375-dbdelta-mariadb}
wordpress_container=${MDI_DBDELTA_WORDPRESS_CONTAINER:-mdi375-dbdelta-wordpress}

cleanup() {
	docker rm -f "$wordpress_container" >/dev/null 2>&1 || true
	docker exec "$mariadb_container" mariadb -uroot -e "DROP DATABASE IF EXISTS \`$database\`" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker exec "$mariadb_container" mariadb -uroot -e "DROP DATABASE IF EXISTS \`$database\`; CREATE DATABASE \`$database\`"
docker run --name "$wordpress_container" --network "$network" --memory=512m \
	--mount "type=bind,src=$repo/tests,dst=/tests,readonly" \
	wordpress:cli-php8.3 sh -ec '
		wp() { php -d memory_limit=512M /usr/local/bin/wp "$@"; }
		wp core download --allow-root
		wp config create --dbname="'"$database"'" --dbuser=root --dbpass= --dbhost="'"$mariadb_container"'" --allow-root
		ready=0
		for attempt in $(seq 1 30); do
			if wp db check --skip-ssl --allow-root >/dev/null 2>&1; then ready=1; break; fi
			sleep 1
		done
		test "$ready" -eq 1
		wp core install --url=http://example.test --title=MDI --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email --allow-root
		wp eval-file /tests/probe-native-dbdelta.php --allow-root
	'
