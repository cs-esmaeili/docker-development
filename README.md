# docker-development

Shared Docker infra for local development — one stack for the whole machine.

- **MySQL** — `localhost:${MYSQL_PORT}` (root, empty password)
- **PostgreSQL + pgvector** — `localhost:${POSTGRES_PORT}` (`POSTGRES_USER`/`POSTGRES_PASSWORD`, vector search ready)
- **MongoDB** — `localhost:${MONGO_PORT}` (`MONGO_USER`/`MONGO_PASSWORD`)
- **Redis** — `localhost:${REDIS_PORT}`
- **RedisInsight** — `http://localhost:${REDISINSIGHT_PORT}` (Redis GUI)
- **phpMyAdmin** — `http://localhost:${PMA_PORT}`
- **pgAdmin** — `http://localhost:${PGADMIN_PORT}` (Postgres GUI)
- **Dashboard** — `http://localhost:${DASHBOARD_PORT}` (overview of all services/ports)
- **PHP CLI** — `infra-php-${PHP_VERSION}` (default version), plus any other version via `phprun`

```bash
cd docker/global && docker compose up -d          # everything, incl. default php
docker compose up -d php                          # only the default php container
```

`php -v` also starts/creates the default container if it is not running.

Config lives in `global/.env` (`PHP_VERSION`, ports, credentials).

Web ports are grouped in the 4000 range: dashboard `4000`, phpMyAdmin `4001`,
pgAdmin `4002`, RedisInsight `4003`; nginx site confs take `5000` and up.
Database ports stay on their defaults (MySQL `3306`, Postgres `5432`,
Mongo `27017`, Redis `6370`).

## php on the host

```bash
bin/install.sh          # links `php` (default) + `phprun`, prepends them to PATH
exec "$SHELL" -l

php -v                  # default version = PHP_VERSION in global/.env
php artisan migrate
```

Any other version — installed on demand:

```bash
phprun 8.2                  # build + start 8.2, prints php -v
phprun 8.2 -m               # php -m on 8.2
phprun 8.2 artisan migrate  # php artisan migrate on 8.2
phprun 8.2 -- composer install   # composer on 8.2
phprun                      # list running versions
```

Only two host commands are created: `php` and `phprun`. Composer runs as
`phprun <version> -- composer ...` (or `php -v` → default version).

- Every version gets its own image (`infra-php:8.3`) and container (`infra-php-8.3`), so several can run at the same time.
- The default version is a compose service → `docker compose up -d` / `docker compose up -d php`. Other versions are created by `phprun` (`docker run`).
- PHP containers run with **host networking** (`network_mode: host`), so `127.0.0.1` inside the container is the host's loopback: `php artisan serve --host=127.0.0.1 --port=8002` is reachable from the browser exactly like a local PHP install, and `127.0.0.1:3306` / `:6370` reach MySQL/Redis. `host.docker.internal` still resolves too (keep it if your `.env` already uses it).
- First use of a version builds its image automatically (no separate build step).
- `$HOME` is mounted with the same path, so paths and `artisan` work as usual; outside `$HOME` a one-shot container mounts that dir.
- Containers run as uid/gid `1000:1000` (override with `PHP_UID`/`PHP_GID`), so created files belong to you.
- Host PHP escape hatch: `PHP_HOST=1 php -v`.
- Undo: `bin/install.sh --remove`.

## PHP images

`global/php/Dockerfile` — single stage on `php:<version>-cli` (Debian), same install
pattern as the old project image: redis + mongodb from PECL, then
`bcmath exif gd intl mysqli pcntl pdo_mysql pdo_pgsql posix opcache zip`, plus
composer, git, unzip, zip, curl, wget.

Images are built on demand: `phprun <version>` (or `php -v` for the default)
builds `infra-php:<version>` the first time. After editing the Dockerfile or
`php.ini`, force a rebuild:

```bash
PHP_REBUILD=1 php -v        # default version
PHP_REBUILD=1 phprun 8.3 -v # any other version
```

INIs: edit `global/php/php.ini` (baked into the image at `conf.d/99-global.ini`, so
rebuild after changing it).

## Sites: nginx (manual confs)

One shared `infra-nginx` with **host networking** (`network_mode: host`), so a
`listen <port>` in a conf is published directly and `127.0.0.1:<port>` means the
host. `restart: unless-stopped` → it comes up with the rest and after every boot.

No script: write a conf in `global/nginx/sites/<name>.conf` (mount is
`/etc/nginx/conf.d`), then:

```bash
docker exec infra-nginx nginx -t
docker exec infra-nginx nginx -s reload
```

**Static folder** (must be under `$HOME`, mounted read-only; workers run as root
so a `0700` home is readable):

```nginx
server {
    listen 5055;
    server_name _;
    root /home/javad/dev/landing;
    index index.html;
    location / { try_files $uri $uri/ /index.html; }
}
```

**App already running on the host** (e.g. Next dev on 3000):

```nginx
upstream dudi_front { server 127.0.0.1:3000; keepalive 16; }
server {
    listen 5049;
    server_name _;
    client_max_body_size 100m;
    location / {
        proxy_pass http://dudi_front;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 600s;
    }
}
```

**Laravel (`global/nginx/sites/arta-backend.conf`, port 5000)** — proxy to
`php artisan serve` on `127.0.0.1:8000`, which is kept alive by a systemd user
service (see `sites/arta.sh`):

```nginx
server {
    listen 5000;
    server_name _;
    client_max_body_size 100m;
    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port 5000;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 600s;
    }
}
```

### Keeping a site up (one sh per site)

`sites/arta.sh` installs `~/.config/systemd/user/arta-backend.service`, enables it
and turns on linger, so `php artisan serve` runs after every boot and is restarted
on crash:

```bash
./sites/arta.sh            # install + start + enable at boot
./sites/arta.sh --remove   # stop + delete the service
systemctl --user status arta-backend
journalctl --user -u arta-backend -f
```

For another site: copy `sites/arta.sh`, change the `NAME` / `DIR` / `PORT`
variables (and `ENV_VARS` if it needs different DB/Redis).

Notes:
- Confs are plain nginx; nothing is auto-generated.
- `nginx.conf` uses `user root;` for the read-only `$HOME` mount — switch back to
  `user nginx;` if you only proxy.
- After reboot `infra-nginx` returns automatically; anything running on the host
  (or its containers) is yours to start.

## Laravel `.env` (host side)

```
DB_HOST=127.0.0.1
DB_PORT=3306          # MYSQL_PORT
DB_USERNAME=root
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PORT=6370       # published REDIS_PORT, not 6379
```

PostgreSQL:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432          # POSTGRES_PORT
DB_DATABASE=postgres  # POSTGRES_DB
DB_USERNAME=postgres  # POSTGRES_USER
DB_PASSWORD=postgres  # POSTGRES_PASSWORD
```

MongoDB (`mongodb` extension is in the image):

```
DB_CONNECTION=mongodb
MONGODB_URI=mongodb://root:mongo@127.0.0.1:27017/?authSource=admin
```

Inside a php container use `mysql:3306`, `postgres:5432`, `mongo:27017`,
`redis:6379` instead of `127.0.0.1` (container name is `mongo`, not `mongodb`).

## pgvector (AI / RAG)

Postgres runs `pgvector/pgvector:pg17`, so the `vector` type and ANN indexes are
available without building anything.

```bash
docker exec -it infra-postgres psql -U postgres -c '\dx'   # vector, pg_trgm, ...
```

On a **fresh** `postgres_data` volume `global/postgres/init/00-extensions.sql`
enables `vector`, `pg_trgm`, `unaccent`, `uuid-ossp` and `pgcrypto` automatically.
On an existing volume run it once:

```bash
docker exec -i infra-postgres psql -U postgres < global/postgres/init/00-extensions.sql
```

Embedding table with a cosine HNSW index:

```sql
CREATE TABLE documents (
    id        bigserial PRIMARY KEY,
    content   text NOT NULL,
    metadata  jsonb NOT NULL DEFAULT '{}'::jsonb,
    embedding vector(1536)          -- must match your model's dimension
);

CREATE INDEX documents_embedding_hnsw
    ON documents USING hnsw (embedding vector_cosine_ops);

-- nearest neighbours: 1 - cosine distance = similarity
SELECT id, content, 1 - (embedding <=> $1) AS similarity
FROM documents
ORDER BY embedding <=> $1
LIMIT 5;
```

Operators: `<=>` cosine distance, `<#>` negative inner product, `<->` L2.
HNSW is the good default; IVFFlat (`lists ≈ rows / 1000`) is the lighter
alternative.

From PHP the embedding is just a string literal, so any driver works:

```php
$pdo->prepare('INSERT INTO documents (content, embedding) VALUES (?, ?)')
    ->execute([$text, '[' . implode(',', $vector) . ']']);
```

Reach it as `postgres:5432` from other containers, `127.0.0.1:5432` from the host
(or `host.docker.internal:5432` from a container on another network).

## Dashboard

Web overview of the whole stack at `http://localhost:${DASHBOARD_PORT}` (default
`4000`). It shows every service, its port, live status, latency and container
state, plus the ports of the nginx site confs.

- **Ports come from `global/.env`** and are re-read on every request, so changing
  a value is visible after the next refresh (auto-refresh every 10s). Remember
  the actual Docker mapping only changes after `docker compose up -d <service>`.
- Live state comes from `/var/run/docker.sock` (mounted read-only); if the socket
  is missing it falls back to TCP port checks.
- `global/dashboard/index.php` is the whole app (single file, no build step). It
  is mounted read-only, so editing it only needs a browser refresh.

```bash
cd docker/global && docker compose up -d dashboard
docker logs -f infra-dashboard
```

## RedisInsight

Web GUI for Redis at `http://localhost:${REDISINSIGHT_PORT}` (default `4003`).

`infra-redis` is pre-registered in the UI (host `redis:6379`, alias `infra-redis`),
so it is ready on first launch. Connections and settings persist in the
`redisinsight_data` volume. The license is accepted automatically
(`RI_ACCEPT_TERMS_AND_CONDITIONS=true`).

```bash
cd docker/global && docker compose up -d redisinsight
docker logs -f infra-redisinsight
```

To add another Redis, use host `redis` port `6379` for containers on the `infra`
network, or `host.docker.internal` + `REDIS_PORT` for something running on the
host. Upgrade by bumping `REDISINSIGHT_VERSION` in `.env`.

## pgAdmin

Web GUI for PostgreSQL at `http://localhost:${PGADMIN_PORT}` (default `4002`).

Login with `PGADMIN_EMAIL` / `PGADMIN_PASSWORD` from `.env` (defaults
`admin@local.dev` / `admin`). The shared postgres is pre-registered from
`global/pgadmin/servers.json` as **infra-postgres** (`postgres:5432`).

`servers.json` has the postgres password as plain text (`postgres`). If you change
`POSTGRES_PASSWORD`, update that file too (or re-enter the password once in
pgAdmin under the server's properties). Only the containers on the `infra`
network can be reached by name; for something on the host use
`host.docker.internal` + `POSTGRES_PORT`.

```bash
cd docker/global && docker compose up -d pgadmin
docker logs -f infra-pgadmin
```

Data (servers, queries, settings) lives in the `pgadmin_data` volume.

## MySQL shell

```bash
cd docker/scripts && ./mysql.sh
```

## Stop

```bash
cd docker/global && docker compose down                  # everything compose manages (incl. default php)
docker stop $(docker ps -q --filter name=infra-php-)     # + extra versions from phprun
```
