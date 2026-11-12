#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")"

network=partitions-art
mysql=partitions-art-mysql
password=secret

docker network create "$network" >/dev/null 2>&1 || true
docker rm -f "$mysql" >/dev/null 2>&1 || true
docker run -d --name "$mysql" --network "$network" \
    -e MYSQL_ROOT_PASSWORD="$password" -e MYSQL_DATABASE=app mysql:8.4 >/dev/null

docker build -q -t partitions-vhs . >/dev/null

until docker exec "$mysql" mysqladmin ping -p"$password" --silent >/dev/null 2>&1; do
    sleep 2
done
sleep 5

if date -v-1d +%F >/dev/null 2>&1; then
    first=$(date -v-100d +%F)
    last=$(date -v-3d +%F)
else
    first=$(date -d '100 days ago' +%F)
    last=$(date -d '3 days ago' +%F)
fi

run="docker run --rm --network $network -v $PWD/..:/package -e DB_HOST=$mysql -e DB_PASSWORD=$password"

docker exec -i "$mysql" mysql -p"$password" app \
    -e "SET @first = '$first', @days = 98, @rows = 600000; $(cat seed.sql)"
$run --entrypoint php partitions-vhs artisan partitions:add transactions --create --force --from="$first" --to="$last" >/dev/null
$run partitions-vhs partitions.tape

docker rm -f "$mysql" >/dev/null
docker network rm "$network" >/dev/null
