#!/usr/bin/env bash
set -euo pipefail

# Open a bash shell inside the shared mysql container, with import instructions.
#
# Override the mysql container via MYSQL_CONTAINER (default: infra-mysql).

MYSQL_CONTAINER="${MYSQL_CONTAINER:-infra-mysql}"

if ! docker ps --format '{{.Names}}' | grep -q "^${MYSQL_CONTAINER}$"; then
  echo "ERROR: mysql container '$MYSQL_CONTAINER' not running." >&2
  echo "       Start it first: cd docker/global && docker compose up -d" >&2
  exit 1
fi

cat <<EOF

==================================================
 MySQL container shell — ${MYSQL_CONTAINER}
==================================================

To import a .sql dump:

  1. Copy your dump into the container (run in a SEPARATE host terminal):
       docker cp your_dump.sql ${MYSQL_CONTAINER}:/tmp/dump.sql

  2. Import it here (inside this shell):
       mysql -uroot your_db_name < /tmp/dump.sql

     Skip foreign-key errors and keep going:
       mysql -uroot --force your_db_name < /tmp/dump.sql

  3. Verify:
       mysql -uroot -e "SHOW TABLES;" your_db_name

  (Empty root password — just -uroot. If a password is set, use -uroot -pPASS.)

==================================================

EOF

exec docker exec -it "$MYSQL_CONTAINER" bash
