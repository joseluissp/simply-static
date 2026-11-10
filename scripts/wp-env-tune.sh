#!/bin/sh
# Raises PHP limits in the wp-env containers (web + cli). Run after `wp-env start`.
set -e
INI='memory_limit=1024M
upload_max_filesize=256M
post_max_size=256M
max_execution_time=600
max_input_vars=10000'
for c in $(docker ps --format '{{.Names}}' | grep '^wp-env-simply-static.*-\(wordpress\|cli\)-1$'); do
	docker exec -u root "$c" sh -c "printf '%s\n' '$INI' > /usr/local/etc/php/conf.d/zz-dev.ini"
done
docker exec "$(docker ps --format '{{.Names}}' | grep 'simply-static.*-wordpress-1$' | head -1)" apache2ctl graceful
