<?php
declare(strict_types=1);

/**
 * Infra dashboard.
 *
 * The service list is generated on every request from global/.env, so editing a
 * port there is reflected on the next refresh (no rebuild needed). Live state
 * comes from the Docker socket when available, with a TCP probe fallback.
 */

const ENV_PATH  = '/infra/.env';
const SITES_DIR = '/infra/nginx/sites';
const DOCKER_SOCKET = 'unix:///var/run/docker.sock';

function load_env(string $path): array
{
    $env = [];
    if (!is_readable($path)) {
        return $env;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        $len   = strlen($value);
        if ($len >= 2 && (
            ($value[0] === '"' && $value[$len - 1] === '"') ||
            ($value[0] === "'" && $value[$len - 1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }
        $env[$key] = $value;
    }
    return $env;
}

/**
 * @return array{0:int,1:bool} [port, overridden-in-env]
 */
function resolve_port(array $env, string $key, int $default): array
{
    $raw = $env[$key] ?? '';
    $port = ctype_digit($raw) ? (int) $raw : $default;
    if ($port < 1 || $port > 65535) {
        $port = $default;
    }
    return [$port, array_key_exists($key, $env) && $port !== $default];
}

function dechunk(string $body): string
{
    $out = '';
    while (true) {
        $pos = strpos($body, "\r\n");
        if ($pos === false) {
            break;
        }
        $len = (int) hexdec(trim(substr($body, 0, $pos)));
        if ($len <= 0) {
            break;
        }
        $out .= substr($body, $pos + 2, $len);
        $body = substr($body, $pos + 2 + $len + 2);
    }
    return $out;
}

/**
 * Read container state straight from the Docker daemon over its unix socket.
 *
 * @return array{ok:bool, items:array}
 */
function docker_containers(): array
{
    $sock = @stream_socket_client(DOCKER_SOCKET, $errno, $errstr, 1.0);
    if (!$sock) {
        return ['ok' => false, 'items' => []];
    }
    stream_set_timeout($sock, 1);
    fwrite(
        $sock,
        "GET /containers/json?all=1 HTTP/1.1\r\n" .
        "Host: docker\r\nAccept: application/json\r\nConnection: close\r\n\r\n"
    );
    $raw = stream_get_contents($sock) ?: '';
    fclose($sock);

    $sep = strpos($raw, "\r\n\r\n");
    if ($sep === false) {
        return ['ok' => false, 'items' => []];
    }
    $head = substr($raw, 0, $sep);
    $body = substr($raw, $sep + 4);
    if (stripos($head, 'transfer-encoding: chunked') !== false) {
        $body = dechunk($body);
    }
    $items = json_decode($body, true);
    if (!is_array($items)) {
        return ['ok' => false, 'items' => []];
    }
    return ['ok' => true, 'items' => $items];
}

/** TCP connect latency in ms, or null when the port is closed. */
function probe(int $port): ?float
{
    $start = microtime(true);
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.35);
    if (!$fp) {
        return null;
    }
    fclose($fp);
    return round((microtime(true) - $start) * 1000, 1);
}

/** Ports from the nginx site confs (listen directives). */
function nginx_sites(): array
{
    $sites = [];
    $files = glob(SITES_DIR . '/*.conf') ?: [];
    sort($files);
    foreach ($files as $file) {
        $conf = basename($file);
        $text = file_get_contents($file) ?: '';
        preg_match_all('/^\s*listen\s+(?:[^\s;]*:)?(\d{1,5})\b/m', $text, $m);
        if (!$m[1]) {
            continue;
        }
        preg_match('/server_name\s+([^;]+);/', $text, $sn);
        $name = trim($sn[1] ?? '');
        if ($name === '' || $name === '_') {
            $name = preg_replace('/\.conf$/', '', $conf);
        }
        foreach (array_unique($m[1]) as $port) {
            $sites[] = [
                'name' => $name,
                'conf' => $conf,
                'port' => (int) $port,
                'status' => probe((int) $port) === null ? 'down' : 'up',
            ];
        }
    }
    return $sites;
}

$env     = load_env(ENV_PATH);
$project = $env['COMPOSE_PROJECT_NAME'] ?? 'infra';
$docker  = docker_containers();
$byService = [];
foreach ($docker['items'] as $container) {
    $labels = $container['Labels'] ?? [];
    if (($labels['com.docker.compose.project'] ?? null) !== $project) {
        continue;
    }
    $service = $labels['com.docker.compose.service'] ?? null;
    if ($service) {
        $byService[$service] = $container;
    }
}

$mysqlUser = $env['MYSQL_ROOT_USERNAME'] ?? 'root';
$mysqlPass = ($env['MYSQL_ALLOW_EMPTY_PASSWORD'] ?? '') === 'yes'
    ? ''
    : ($env['MYSQL_ROOT_PASSWORD'] ?? '');

$defs = [
    ['key' => 'mysql', 'service' => 'mysql', 'name' => 'MySQL', 'desc' => 'MySQL 8 · relational database', 'env' => 'MYSQL_PORT', 'default' => 3306, 'web' => false, 'color' => '#3E9BFF', 'icon' => 'database',
        'auth' => ['label' => 'MySQL', 'user' => $mysqlUser, 'pass' => $mysqlPass]],
    ['key' => 'postgres', 'service' => 'postgres', 'name' => 'PostgreSQL', 'desc' => 'PostgreSQL 17 + pgvector · relational + vector DB', 'env' => 'POSTGRES_PORT', 'default' => 5432, 'web' => false, 'color' => '#7C9CFF', 'icon' => 'database',
        'auth' => ['label' => 'PostgreSQL', 'user' => $env['POSTGRES_USER'] ?? 'postgres', 'pass' => $env['POSTGRES_PASSWORD'] ?? 'postgres']],
    ['key' => 'mongo', 'service' => 'mongo', 'name' => 'MongoDB', 'desc' => 'MongoDB 7 · document store', 'env' => 'MONGO_PORT', 'default' => 27017, 'web' => false, 'color' => '#4ADE80', 'icon' => 'layers',
        'auth' => ['label' => 'MongoDB', 'user' => $env['MONGO_USER'] ?? 'root', 'pass' => $env['MONGO_PASSWORD'] ?? 'mongo']],
    ['key' => 'redis', 'service' => 'redis', 'name' => 'Redis', 'desc' => 'Redis 7 · cache / queues', 'env' => 'REDIS_PORT', 'default' => 6379, 'web' => false, 'color' => '#FF6B6B', 'icon' => 'bolt',
        'auth' => ['label' => 'Redis', 'user' => null, 'pass' => null, 'note' => 'no password']],
    ['key' => 'phpmyadmin', 'service' => 'phpmyadmin', 'name' => 'phpMyAdmin', 'desc' => 'Web UI for MySQL', 'env' => 'PMA_PORT', 'default' => 4001, 'web' => true, 'color' => '#A78BFA', 'icon' => 'layout',
        'auth' => ['label' => 'MySQL root', 'user' => $mysqlUser, 'pass' => $mysqlPass]],
    ['key' => 'pgadmin', 'service' => 'pgadmin', 'name' => 'pgAdmin', 'desc' => 'Web UI for PostgreSQL', 'env' => 'PGADMIN_PORT', 'default' => 4002, 'web' => true, 'color' => '#38BDF8', 'icon' => 'layout',
        'auth' => ['label' => 'pgAdmin', 'user' => $env['PGADMIN_EMAIL'] ?? 'admin@local.dev', 'pass' => $env['PGADMIN_PASSWORD'] ?? 'admin']],
    ['key' => 'redisinsight', 'service' => 'redisinsight', 'name' => 'RedisInsight', 'desc' => 'Web UI for Redis', 'env' => 'REDISINSIGHT_PORT', 'default' => 4003, 'web' => true, 'color' => '#FB7185', 'icon' => 'gauge',
        'auth' => ['label' => 'RedisInsight', 'user' => null, 'pass' => null, 'note' => 'no login · infra-redis pre-registered']],
];

$services = [];
foreach ($defs as $def) {
    [$port, $custom] = resolve_port($env, $def['env'], $def['default']);
    $container = $byService[$def['service']] ?? null;
    $running   = $container && ($container['State'] ?? '') === 'running';
    $latency   = probe($port);
    $status    = $latency !== null ? 'up' : ($running ? 'starting' : 'down');

    $containerName = null;
    if ($container) {
        $names = $container['Names'] ?? [];
        $containerName = $names ? ltrim((string) $names[0], '/') : null;
    }

    $services[] = [
        'key'            => $def['key'],
        'name'           => $def['name'],
        'desc'           => $def['desc'],
        'port'           => $port,
        'web'            => $def['web'],
        'auth'           => $def['auth'] ?? null,
        'color'          => $def['color'],
        'icon'           => $def['icon'],
        'envKey'         => $def['env'],
        'custom'         => $custom,
        'status'         => $status,
        'latency'        => $latency,
        'container'      => $containerName,
        'containerStatus'=> $container['Status'] ?? null,
        'image'          => $container['Image'] ?? null,
    ];
}

$payload = [
    'now'      => gmdate('c'),
    'host'     => gethostname() ?: 'localhost',
    'php'      => PHP_VERSION,
    'project'  => $project,
    'envPath'  => 'global/.env',
    'docker'   => $docker['ok'],
    'services' => $services,
    'sites'    => nginx_sites(),
];

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Infra · Dashboard</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%230b0d13'/%3E%3Cpath d='M9 21V11h5a5 5 0 0 1 0 10H9' fill='none' stroke='%2322d3ee' stroke-width='2.4' stroke-linecap='round'/%3E%3Ccircle cx='22' cy='11' r='2.4' fill='%2322d3ee'/%3E%3C/svg%3E">
<style>
  :root{
    --bg:#07080c;
    --panel:rgba(255,255,255,.045);
    --stroke:rgba(255,255,255,.09);
    --text:#eef1f7;
    --muted:#98a0b3;
    --faint:#6b7386;
    --green:#34d399;
    --amber:#fbbf24;
    --red:#f87171;
    --radius:18px;
    --font:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Inter,sans-serif;
    --mono:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;
  }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{
    margin:0;background:var(--bg);color:var(--text);font-family:var(--font);
    -webkit-font-smoothing:antialiased;overflow-x:hidden;
  }
  .bg{position:fixed;inset:0;z-index:-1;overflow:hidden;pointer-events:none}
  .bg .blob{position:absolute;border-radius:50%;filter:blur(110px);opacity:.5}
  .b1{width:560px;height:560px;background:#1d4ed8;top:-260px;left:-140px}
  .b2{width:520px;height:520px;background:#0891b2;top:-180px;right:-180px;opacity:.35}
  .b3{width:600px;height:600px;background:#7c3aed;bottom:-340px;left:32%;opacity:.22}
  .bg::after{
    content:"";position:absolute;inset:0;
    background-image:linear-gradient(rgba(255,255,255,.028) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(255,255,255,.028) 1px,transparent 1px);
    background-size:56px 56px;
    mask-image:radial-gradient(ellipse at 50% 0%,#000 30%,transparent 78%);
  }
  .wrap{max-width:1180px;margin:0 auto;padding:28px 22px 56px}

  /* header */
  header{display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:space-between}
  .brand{display:flex;align-items:center;gap:14px}
  .logo{
    width:46px;height:46px;border-radius:14px;display:grid;place-items:center;
    background:linear-gradient(145deg,rgba(34,211,238,.22),rgba(124,58,237,.18));
    border:1px solid rgba(34,211,238,.32);
    box-shadow:0 0 0 1px rgba(255,255,255,.03) inset,0 12px 30px -12px rgba(34,211,238,.55);
    color:#67e8f9;
  }
  .brand h1{margin:0;font-size:20px;letter-spacing:-.02em;font-weight:650}
  .brand p{margin:2px 0 0;color:var(--muted);font-size:12.5px}
  .tools{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .search{position:relative}
  .search svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--faint)}
  .search input{
    width:210px;padding:10px 12px 10px 34px;border-radius:12px;color:var(--text);
    background:rgba(255,255,255,.045);border:1px solid var(--stroke);
    font:inherit;font-size:13.5px;outline:none;transition:border-color .2s,background .2s;
  }
  .search input::placeholder{color:var(--faint)}
  .search input:focus{border-color:rgba(34,211,238,.45);background:rgba(34,211,238,.06)}
  .btn{
    display:inline-flex;align-items:center;justify-content:center;gap:7px;
    padding:9px 13px;border-radius:11px;border:1px solid var(--stroke);
    background:rgba(255,255,255,.05);color:var(--text);font:inherit;font-size:13px;
    cursor:pointer;text-decoration:none;transition:background .18s,border-color .18s,transform .18s;
  }
  .btn:hover{background:rgba(255,255,255,.09);border-color:rgba(255,255,255,.18)}
  .btn:active{transform:translateY(1px)}
  .btn.icon{padding:9px;width:38px}
  .btn.primary{
    background:linear-gradient(180deg,rgba(34,211,238,.2),rgba(34,211,238,.1));
    border-color:rgba(34,211,238,.35);color:#cffafe
  }
  .btn.primary:hover{background:linear-gradient(180deg,rgba(34,211,238,.28),rgba(34,211,238,.16))}
  .auto{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;color:var(--muted);cursor:pointer;user-select:none}
  .auto input{appearance:none;width:34px;height:19px;border-radius:99px;background:rgba(255,255,255,.1);border:1px solid var(--stroke);position:relative;cursor:pointer;transition:background .2s}
  .auto input::after{content:"";position:absolute;top:2px;left:2px;width:13px;height:13px;border-radius:50%;background:#cbd5e1;transition:transform .2s,background .2s}
  .auto input:checked{background:rgba(34,211,238,.24);border-color:rgba(34,211,238,.4)}
  .auto input:checked::after{transform:translateX(15px);background:#67e8f9}

  .meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;color:var(--faint);font-size:12.5px;margin:18px 2px 22px}
  .meta .sep{opacity:.35}
  .live{display:inline-flex;align-items:center;gap:7px;color:var(--green)}
  .live i{width:7px;height:7px;border-radius:50%;background:currentColor;box-shadow:0 0 0 0 currentColor;animation:pulse 2.2s infinite}
  .live[data-ok="0"]{color:var(--red)}
  .live[data-ok="0"] i{animation:none}
  .checking{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:12px}
  .checking[hidden]{display:none}
  .checking i{width:11px;height:11px;border-radius:50%;border:1.6px solid rgba(255,255,255,.16);border-top-color:#67e8f9;animation:spin .7s linear infinite}
  @keyframes pulse{0%{box-shadow:0 0 0 0 rgba(52,211,153,.5)}70%{box-shadow:0 0 0 6px rgba(52,211,153,0)}100%{box-shadow:0 0 0 0 rgba(52,211,153,0)}}

  /* grid + cards */
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px}
  .card{
    position:relative;display:flex;flex-direction:column;gap:15px;padding:20px;
    border:1px solid var(--stroke);border-radius:var(--radius);
    background:linear-gradient(180deg,rgba(255,255,255,.055),rgba(255,255,255,.018));
    box-shadow:0 14px 34px -28px rgba(0,0,0,.95);
    overflow:hidden;isolation:isolate;
    animation:rise .45s both;animation-delay:calc(var(--i,0)*40ms);
    transition:transform .25s,border-color .25s,box-shadow .25s;
  }
  @keyframes rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
  .card::before{
    content:"";position:absolute;top:0;left:12%;right:12%;height:1px;
    background:linear-gradient(90deg,transparent,var(--accent),transparent);opacity:.55
  }
  .card::after{
    content:"";position:absolute;z-index:-1;width:170px;height:170px;right:-70px;top:-90px;
    border-radius:50%;background:var(--accent);filter:blur(62px);opacity:.14;transition:opacity .3s
  }
  .card:hover{transform:translateY(-3px);border-color:color-mix(in srgb,var(--accent) 42%,var(--stroke));box-shadow:0 24px 44px -28px rgba(0,0,0,.95)}
  .card:hover::after{opacity:.3}
  .card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
  .icon{
    width:40px;height:40px;border-radius:12px;display:grid;place-items:center;
    color:var(--accent);background:color-mix(in srgb,var(--accent) 12%,transparent);
    border:1px solid color-mix(in srgb,var(--accent) 26%,transparent);
  }
  .pill{
    display:inline-flex;align-items:center;gap:6px;padding:4px 9px;border-radius:99px;
    font-size:11px;font-weight:600;letter-spacing:.02em;border:1px solid transparent;white-space:nowrap
  }
  .pill i{width:6px;height:6px;border-radius:50%;background:currentColor}
  .pill[data-status="up"]{color:var(--green);background:rgba(52,211,153,.09);border-color:rgba(52,211,153,.22)}
  .pill[data-status="starting"]{color:var(--amber);background:rgba(251,191,36,.09);border-color:rgba(251,191,36,.22)}
  .pill[data-status="starting"] i{animation:blink 1s infinite}
  .pill[data-status="down"]{color:var(--red);background:rgba(248,113,113,.09);border-color:rgba(248,113,113,.22)}
  @keyframes blink{50%{opacity:.25}}
  .name-row{display:flex;align-items:baseline;justify-content:space-between;gap:8px}
  .name{font-size:16.5px;font-weight:620;letter-spacing:-.01em}
  .lat{font-family:var(--mono);font-size:11px;color:var(--faint)}
  .desc{color:var(--muted);font-size:12.5px;line-height:1.5;min-height:19px}
  .port-row{display:flex;align-items:flex-end;gap:8px;margin-top:auto}
  .port{font-family:var(--mono);font-size:26px;line-height:1;letter-spacing:-.03em}
  .port small{font-size:11px;color:var(--faint);letter-spacing:0;margin-left:2px}
  .env-key{font-family:var(--mono);font-size:10.5px;color:var(--faint);margin-left:auto;display:flex;align-items:center;gap:5px}
  .env-key b{font-weight:500;color:#93c5fd;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);padding:1px 5px;border-radius:5px}
  .actions{display:flex;gap:8px}
  .actions .btn{flex:1}
  .copy-mini{flex:0 0 auto;width:36px;padding:9px}
  .creds{
    display:flex;flex-direction:column;gap:9px;
    padding:12px 13px 13px;border-radius:13px;
    background:rgba(0,0,0,.26);
    border:1px solid color-mix(in srgb,var(--accent) 22%,var(--stroke));
    box-shadow:inset 0 1px 0 rgba(255,255,255,.03)
  }
  .creds-head{display:flex;align-items:center;gap:8px;min-width:0}
  .creds .tag{
    flex:0 0 auto;font-size:9.5px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;
    color:var(--accent);background:color-mix(in srgb,var(--accent) 14%,transparent);
    border:1px solid color-mix(in srgb,var(--accent) 30%,transparent);
    padding:2px 6px;border-radius:5px
  }
  .creds .who{color:var(--muted);font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .cred-row{
    display:grid;grid-template-columns:70px minmax(0,1fr) 28px;align-items:center;gap:8px;min-height:26px
  }
  .cred-row .ck{font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--faint)}
  .cred-row .cv{
    font-family:var(--mono);font-size:13.5px;color:var(--text);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap
  }
  .cred-row .cv.empty{font-size:12px;font-style:italic;color:var(--faint)}
  .cpy{
    width:28px;height:28px;padding:0;display:grid;place-items:center;border-radius:8px;
    border:1px solid var(--stroke);background:rgba(255,255,255,.05);color:var(--muted);
    cursor:pointer;transition:background .18s,color .18s,border-color .18s
  }
  .cpy:hover{background:rgba(255,255,255,.1);color:var(--text);border-color:rgba(255,255,255,.2)}
  .creds .note{font-size:12px;color:var(--muted);line-height:1.45}
  .card-foot{
    display:flex;align-items:center;gap:6px;padding-top:11px;border-top:1px solid rgba(255,255,255,.06);
    color:var(--faint);font-size:11px;font-family:var(--mono);
    overflow:hidden;white-space:nowrap;text-overflow:ellipsis
  }
  .card-foot .cstate{overflow:hidden;text-overflow:ellipsis}

  /* nginx sites */
  .section-title{display:flex;align-items:center;gap:10px;margin:34px 2px 14px}
  .section-title h2{font-size:14px;font-weight:600;letter-spacing:.01em;margin:0}
  .section-title .count{font-family:var(--mono);font-size:11px;color:var(--faint);background:rgba(255,255,255,.05);border:1px solid var(--stroke);padding:1px 7px;border-radius:99px}
  .sites{display:flex;flex-direction:column;gap:8px}
  .site{
    display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:13px;
    border:1px solid var(--stroke);background:rgba(255,255,255,.03);
    transition:background .2s,border-color .2s
  }
  .site:hover{background:rgba(255,255,255,.055);border-color:rgba(255,255,255,.16)}
  .site .dot{width:8px;height:8px;border-radius:50%;background:var(--red);flex:0 0 auto}
  .site .dot.up{background:var(--green);box-shadow:0 0 10px rgba(52,211,153,.6)}
  .site .sname{font-size:13.5px;font-weight:560}
  .site .sconf{font-family:var(--mono);font-size:11px;color:var(--faint)}
  .site .sport{margin-left:auto;font-family:var(--mono);font-size:12.5px;color:#cbd5e1}
  .site .btn{padding:6px 11px;font-size:12px}

  footer{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:36px;padding-top:18px;border-top:1px solid rgba(255,255,255,.06);color:var(--faint);font-size:11.5px}
  footer code{font-family:var(--mono);font-size:11px;color:var(--muted);background:rgba(255,255,255,.05);padding:1px 6px;border-radius:5px}
  .warn{color:var(--amber)}

  .empty{grid-column:1/-1;text-align:center;padding:48px 20px;color:var(--faint);font-size:13px}
  .toast{
    position:fixed;left:50%;bottom:28px;transform:translate(-50%,16px);
    padding:10px 16px;border-radius:12px;border:1px solid rgba(255,255,255,.14);
    background:rgba(20,22,30,.92);backdrop-filter:blur(12px);color:var(--text);
    font-size:12.5px;opacity:0;pointer-events:none;transition:opacity .22s,transform .22s;z-index:20;
    box-shadow:0 18px 40px -16px rgba(0,0,0,.8)
  }
  .toast.show{opacity:1;transform:translate(-50%,0)}
  .toast.err{border-color:rgba(248,113,113,.4);color:#fecaca}
  .spin{animation:spin .8s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}

  @media (max-width:640px){
    .wrap{padding:20px 15px 44px}
    .search input{width:150px}
    .brand h1{font-size:18px}
    .grid{grid-template-columns:1fr}
  }
  @media (prefers-reduced-motion:reduce){
    *,*::before,*::after{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important}
  }
</style>
</head>
<body>
<div class="bg"><div class="blob b1"></div><div class="blob b2"></div><div class="blob b3"></div></div>

<div class="wrap">
  <header>
    <div class="brand">
      <div class="logo">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17V7h6a5 5 0 0 1 0 10H4"/><circle cx="19" cy="7" r="2"/></svg>
      </div>
      <div>
        <h1>Infra</h1>
        <p>Shared stack dashboard</p>
      </div>
    </div>
    <div class="tools">
      <div class="search">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
        <input id="filter" type="search" placeholder="Filter services…" autocomplete="off" spellcheck="false">
      </div>
      <button class="btn icon" id="refresh" title="Refresh now" aria-label="Refresh now">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/></svg>
      </button>
      <label class="auto" title="Check every 10s — repaint only when something changes">
        <input type="checkbox" id="auto" checked>
        Auto
      </label>
    </div>
  </header>

  <div class="meta">
    <span class="live" id="live" data-ok="1"><i></i><span id="liveText">Live</span></span>
    <span class="checking" id="checking" hidden><i></i>checking</span>
    <span class="sep">·</span>
    <span>updated <span id="updated">—</span></span>
    <span class="sep">·</span>
    <span id="host">—</span>
    <span class="sep">·</span>
    <span>PHP <span id="php">—</span></span>
    <span class="sep">·</span>
    <span>project <code id="project">—</code></span>
  </div>

  <main>
    <section class="grid" id="services" aria-live="polite"></section>

    <section id="sites-wrap" hidden>
      <div class="section-title">
        <h2>nginx sites</h2>
        <span class="count" id="sites-count">0</span>
      </div>
      <div class="sites" id="sites"></div>
    </section>
  </main>

  <footer>
    <span>Source: <code id="envPath">—</code></span>
    <span class="sep">·</span>
    <span>Ports follow <code>.env</code> — refresh after editing (docker compose up -d for the affected service).</span>
    <span class="sep">·</span>
    <span id="docker-note"></span>
  </footer>
</div>

<div class="toast" id="toast"></div>

<script>
(() => {
  const ICONS = {
    database: '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
    layers: '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 13 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
    bolt: '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
    layout: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 20V9"/>',
    gauge: '<path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/><path d="m13.4 10.6 5.1-5.1"/><path d="M20.5 15a9 9 0 1 0-17 0"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
    open: '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/>',
  };
  const LABELS = {up: 'Running', starting: 'Starting', down: 'Stopped'};
  const state = {timer: null, busy: false, data: null, query: '', fp: ''};

  const $ = (sel, root = document) => root.querySelector(sel);
  const icon = (name, size = 20) =>
    `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${ICONS[name] || ICONS.database}</svg>`;
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const base = () => location.protocol + '//' + location.hostname;
  const address = (s) => location.hostname + ':' + s.port;

  function toast(msg, isError = false) {
    const el = $('#toast');
    el.textContent = msg;
    el.className = 'toast show' + (isError ? ' err' : '');
    clearTimeout(toast.t);
    toast.t = setTimeout(() => { el.className = 'toast'; }, 2200);
  }

  async function copy(text) {
    try {
      await navigator.clipboard.writeText(text);
      toast('Copied ' + text);
    } catch {
      const ta = document.createElement('textarea');
      ta.value = text; document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); ta.remove();
      toast('Copied ' + text);
    }
  }

  function card(s, i) {
    const el = document.createElement('article');
    el.className = 'card';
    el.style.setProperty('--accent', s.color);
    el.style.setProperty('--i', i);
    el.dataset.search = [s.name, s.desc, s.port, s.envKey, s.container].join(' ').toLowerCase();

    const webAction = s.web
      ? `<a class="btn primary" href="${esc(base() + ':' + s.port)}" target="_blank" rel="noopener">${icon('open', 14)} Open</a>`
      : `<button class="btn primary" data-copy="${esc(address(s))}">${icon('copy', 14)} Copy</button>`;

    const authHtml = (() => {
      if (!s.auth) return '';
      const u = s.auth.user, p = s.auth.pass;
      const hasCreds = u !== null && u !== undefined;
      const row = (kind, value) => {
        const empty = value === null || value === undefined || value === '';
        return `<div class="cred-row">
          <span class="ck">${kind}</span>
          <span class="cv${empty ? ' empty' : ''}">${empty ? '(empty)' : esc(value)}</span>
          ${empty
            ? ''
            : `<button class="cpy" data-copy="${esc(value)}" title="Copy ${kind}" aria-label="Copy ${kind}">${icon('copy', 13)}</button>`}
        </div>`;
      };
      return `<div class="creds">
        <div class="creds-head">
          <span class="tag">login</span>
          <span class="who">${esc(s.auth.label || 'login')}</span>
        </div>
        ${hasCreds
          ? row('user', u) + row('password', p)
          : `<div class="note">${esc(s.auth.note || 'no login required')}</div>`}
      </div>`;
    })();

    el.innerHTML = `
      <div class="card-top">
        <div class="icon">${icon(s.icon)}</div>
        <span class="pill" data-status="${esc(s.status)}"><i></i><span class="pill-text">${LABELS[s.status] || esc(s.status)}</span></span>
      </div>
      <div class="name-row">
        <span class="name">${esc(s.name)}</span>
        <span class="lat" data-lat="${esc(s.key)}">${s.latency != null ? esc(s.latency) + ' ms' : ''}</span>
      </div>
      <div class="desc">${esc(s.desc)}</div>
      ${authHtml}
      <div class="port-row">
        <span class="port">${esc(s.port)}<small>${s.web ? 'http' : 'tcp'}</small></span>
        <span class="env-key" title="${esc(s.custom ? 'overridden in .env' : 'default value')}"><b>${esc(s.envKey)}</b></span>
      </div>
      <div class="actions">
        ${webAction}
        <button class="btn copy-mini" data-copy="${esc(address(s))}" title="Copy address" aria-label="Copy address">${icon('copy', 14)}</button>
      </div>
      <div class="card-foot" title="${esc((s.container || 'no container') + (s.containerStatus ? ' · ' + s.containerStatus : '') + (s.image ? ' · ' + s.image : ''))}">
        <span class="cstate">${esc(s.container || 'not in compose')}${s.containerStatus ? ' · ' + esc(s.containerStatus) : ''}</span>
      </div>`;

    el.querySelectorAll('[data-copy]').forEach((btn) =>
      btn.addEventListener('click', () => copy(btn.dataset.copy))
    );
    return el;
  }

  const cards = new Map();
  let sitesSig = '';

  const cardSig = (s) => JSON.stringify([s.name, s.desc, s.port, s.web, s.envKey, s.auth]);

  /* Patch the grid in place: existing cards keep their DOM (no flashing, no
     animation restart) and only their live bits are updated. */
  function syncCards(services) {
    const grid = $('#services');
    const seen = new Set();
    services.forEach((s, i) => {
      seen.add(s.key);
      const sig = cardSig(s);
      const el = cards.get(s.key);
      if (el && el._sig === sig) {
        const pill = el.querySelector('.pill');
        pill.dataset.status = s.status;
        const label = el.querySelector('.pill-text');
        if (label) label.textContent = LABELS[s.status] || s.status;
        const lat = el.querySelector('[data-lat]');
        if (lat) lat.textContent = s.latency != null ? s.latency + ' ms' : '';
        const foot = el.querySelector('.cstate');
        if (foot) foot.textContent = (s.container || 'not in compose') + (s.containerStatus ? ' · ' + s.containerStatus : '');
        return;
      }
      // new service, or something structural changed (port, credentials, ...)
      const fresh = card(s, i);
      fresh._sig = sig;
      if (el) el.replaceWith(fresh); else grid.appendChild(fresh);
      cards.set(s.key, fresh);
    });
    cards.forEach((el, key) => {
      if (!seen.has(key)) {
        el.remove();
        cards.delete(key);
      }
    });
    applyFilter();
  }

  function renderSites(sites) {
    const wrap = $('#sites-wrap');
    const list = $('#sites');
    const sig = JSON.stringify(sites.map((s) => [s.name, s.conf, s.port, s.status]));
    $('#sites-count').textContent = sites.length;
    wrap.hidden = sites.length === 0;
    if (sig === sitesSig) return;
    sitesSig = sig;
    list.innerHTML = '';
    sites.forEach((site) => {
      const row = document.createElement('div');
      row.className = 'site';
      row.dataset.search = [site.name, site.conf, site.port].join(' ').toLowerCase();
      row.innerHTML = `
        <span class="dot ${site.status === 'up' ? 'up' : ''}"></span>
        <span>
          <div class="sname">${esc(site.name)}</div>
          <div class="sconf">${esc(site.conf)}</div>
        </span>
        <span class="sport">:${esc(site.port)}</span>
        <a class="btn" href="${esc(base() + ':' + site.port)}" target="_blank" rel="noopener">Open</a>`;
      list.appendChild(row);
    });
    applyFilter();
  }

  function applyFilter() {
    const q = state.query.trim().toLowerCase();
    document.querySelectorAll('[data-search]').forEach((el) => {
      el.style.display = !q || el.dataset.search.includes(q) ? '' : 'none';
    });
    const grid = $('#services');
    const visible = [...grid.children].filter((c) => c.style.display !== 'none').length;
    const existing = grid.querySelector('.empty');
    if (!visible && !existing) {
      const div = document.createElement('div');
      div.className = 'empty';
      div.textContent = q ? 'No services match “' + q + '”' : 'No services configured';
      grid.appendChild(div);
    } else if (visible && existing) {
      existing.remove();
    }
  }

  function render(data) {
    state.data = data;
    $('#updated').textContent = new Date(data.now).toLocaleTimeString();
    $('#host').textContent = data.host;
    $('#php').textContent = data.php;
    $('#project').textContent = data.project;
    $('#envPath').textContent = data.envPath;
    $('#docker-note').innerHTML = data.docker
      ? 'Docker daemon connected'
      : '<span class="warn">Docker socket unavailable — showing port checks only</span>';

    syncCards(data.services || []);
    renderSites(data.sites || []);
  }

  function setLive(ok, text) {
    const el = $('#live');
    el.dataset.ok = ok ? '1' : '0';
    $('#liveText').textContent = text || (ok ? 'Live' : 'Offline');
  }

  /* Coarse "did anything user-visible change?" check — used only for the toast.
     Latency and container uptime strings change on every poll and are ignored. */
  function snapshot(d) {
    return JSON.stringify({
      docker: d.docker,
      services: (d.services || []).map((s) => [s.key, s.status, s.port, s.container, s.image, s.web, s.auth]),
      sites: (d.sites || []).map((s) => [s.name, s.conf, s.port, s.status]),
    });
  }

  async function load(manual = false) {
    if (state.busy) return;
    state.busy = true;
    $('#checking').hidden = false;
    $('#refresh').classList.add('spin');
    try {
      const res = await fetch('?json=1', {cache: 'no-store'});
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      const snap = snapshot(data);
      const changed = snap !== state.fp;
      state.fp = snap;
      render(data);                 // in-place update, nothing gets wiped
      setLive(true);
      if (manual) toast(changed ? 'Updated' : 'No changes');
    } catch (err) {
      setLive(false, 'Unreachable');
      if (manual) toast('Refresh failed: ' + err.message, true);
    } finally {
      state.busy = false;
      $('#checking').hidden = true;
      $('#refresh').classList.remove('spin');
    }
  }

  function schedule() {
    clearInterval(state.timer);
    if ($('#auto').checked) state.timer = setInterval(() => {
      if (!document.hidden) load();
    }, 10000);
  }

  $('#auto').addEventListener('change', (e) => {
    localStorage.setItem('infra.auto', e.target.checked ? '1' : '0');
    schedule();
  });
  $('#refresh').addEventListener('click', () => load(true));
  $('#filter').addEventListener('input', (e) => { state.query = e.target.value; applyFilter(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });

  if (localStorage.getItem('infra.auto') === '0') $('#auto').checked = false;
  schedule();
  load();
})();
</script>
</body>
</html>
