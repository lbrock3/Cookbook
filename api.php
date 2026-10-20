<?php
// Family Cookbook comments and discussion API (PHP + SQLite, no setup needed).
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(int $code, array $data): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

require __DIR__ . '/config.php';
$configured = isset($ADMIN_PASSWORD) && $ADMIN_PASSWORD !== '' && $ADMIN_PASSWORD !== 'CHANGE-THIS-PASSWORD';

if (!extension_loaded('pdo_sqlite')) out(500, ['error' => 'sqlite_unavailable']);

$dir = __DIR__ . '/data';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
try {
    // The database file gets a random, unguessable name (kept in a PHP file, which prints nothing if visited).
    $nameFile = $dir . '/dbname.php';
    if (!is_file($nameFile)) {
        file_put_contents($nameFile, "<?php return 'cookbook-" . bin2hex(random_bytes(12)) . ".sqlite';\n");
        if (is_file($dir . '/cookbook.sqlite')) @rename($dir . '/cookbook.sqlite', $dir . '/' . (include $nameFile));
    }
    $db = new PDO('sqlite:' . $dir . '/' . (include $nameFile));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS posts (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, target TEXT NOT NULL DEFAULT "", title TEXT NOT NULL DEFAULT "", text TEXT NOT NULL, name TEXT NOT NULL, token_hash TEXT NOT NULL, ts INTEGER NOT NULL, edited INTEGER)');
    $db->exec('CREATE INDEX IF NOT EXISTS posts_target ON posts(kind, target)');
    $db->exec('CREATE TABLE IF NOT EXISTS rate (ip TEXT NOT NULL, what TEXT NOT NULL, ts INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS recipes (cat TEXT NOT NULL, slug TEXT NOT NULL, deleted INTEGER NOT NULL DEFAULT 0, data TEXT, ts INTEGER NOT NULL, PRIMARY KEY(cat, slug))');
    $st = $db->query("SELECT v FROM meta WHERE k='salt'");
    $salt = $st->fetchColumn();
    if (!$salt) { $salt = bin2hex(random_bytes(16)); $db->prepare("INSERT INTO meta(k,v) VALUES('salt',?)")->execute([$salt]); }
} catch (Throwable $e) { out(500, ['error' => 'db_error']); }

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('cookbook_keeper');
session_start();
$admin = !empty($_SESSION['keeper']);

$addr = $_SERVER['REMOTE_ADDR'] ?? '';
// Behind a local tunnel (Cloudflare Tunnel), every request arrives from the loopback address;
// only then do we trust the visitor address Cloudflare passes along.
if (($addr === '127.0.0.1' || $addr === '::1') && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    $addr = substr((string)$_SERVER['HTTP_CF_CONNECTING_IP'], 0, 64);
}
$ip = hash('sha256', $addr . $salt);
function limited(PDO $db, string $ip, string $what, int $max, int $window): bool {
    $now = time();
    $db->prepare('DELETE FROM rate WHERE ts < ?')->execute([$now - 86400]);
    $st = $db->prepare('SELECT COUNT(*) FROM rate WHERE ip=? AND what=? AND ts>?');
    $st->execute([$ip, $what, $now - $window]);
    return (int)$st->fetchColumn() >= $max;
}
function mark(PDO $db, string $ip, string $what): void { $db->prepare('INSERT INTO rate(ip,what,ts) VALUES(?,?,?)')->execute([$ip, $what, time()]); }
function clean(string $s, int $max, bool $multiline): string {
    $s = preg_replace($multiline ? '/[^\P{C}\n]+/u' : '/\p{C}+/u', '', $s) ?? '';
    $s = trim($s);
    return mb_substr($s, 0, $max);
}
function cleanRecipe($r): ?array {
    if (!is_array($r)) return null;
    $out = ['title' => clean((string)($r['title'] ?? ''), 120, false)];
    if ($out['title'] === '') return null;
    foreach (['by' => 80, 'attr' => 80, 'desc' => 600, 'prep' => 60, 'cook' => 60, 'total' => 60, 'serves' => 60, 'tools' => 300] as $k => $max) {
        $v = clean((string)($r[$k] ?? ''), $max, false);
        if ($v !== '') $out[$k] = $v;
    }
    $n = clean((string)($r['notes'] ?? ''), 2000, true);
    if ($n !== '') $out['notes'] = $n;
    $d = (int)($r['diff'] ?? 0);
    $out['diff'] = ($d >= 1 && $d <= 3) ? $d : 0;
    foreach (['ing' => [120, 300], 'dir' => [80, 2000]] as $k => $lim) {
        $a = [];
        if (isset($r[$k]) && is_array($r[$k])) {
            foreach ($r[$k] as $x) {
                $x = clean((string)$x, $lim[1], false);
                if ($x !== '') $a[] = $x;
                if (count($a) >= $lim[0]) break;
            }
        }
        if ($a) $out[$k] = $a;
    }
    return $out;
}
function publicPost(array $r): array {
    return ['id' => (string)$r['id'], 'kind' => $r['kind'], 'target' => $r['target'], 'title' => $r['title'], 'text' => $r['text'], 'name' => $r['name'], 'ts' => (int)$r['ts'] * 1000, 'edited' => $r['edited'] ? (int)$r['edited'] * 1000 : null];
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    $rows = $db->query('SELECT id,kind,target,title,text,name,ts,edited FROM posts ORDER BY ts ASC, id ASC LIMIT 3000')->fetchAll(PDO::FETCH_ASSOC);
    $recipes = [];
    foreach ($db->query('SELECT cat,slug,deleted,data FROM recipes ORDER BY ts ASC, rowid ASC')->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $recipes[] = ['cat' => $x['cat'], 'slug' => $x['slug'], 'deleted' => (int)$x['deleted'], 'r' => $x['data'] ? json_decode($x['data'], true) : null];
    }
    out(200, ['posts' => array_map('publicPost', $rows), 'admin' => $admin, 'adminReady' => $configured, 'recipes' => $recipes]);
}
if ($method !== 'POST') out(405, ['error' => 'method']);
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'cookbook') out(400, ['error' => 'bad_request']);
$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) out(400, ['error' => 'bad_json']);
$action = (string)($in['action'] ?? '');

if ($action === 'login') {
    if (!$configured) out(400, ['error' => 'not_configured']);
    if (limited($db, $ip, 'login', 8, 600)) out(429, ['error' => 'slow_down']);
    mark($db, $ip, 'login');
    $pw = (string)($in['password'] ?? '');
    $ok = hash_equals((string)$ADMIN_PASSWORD, $pw);
    if (!$ok) out(403, ['error' => 'wrong_password']);
    session_regenerate_id(true);
    $_SESSION['keeper'] = true;
    out(200, ['admin' => true]);
}
if ($action === 'logout') { $_SESSION = []; session_destroy(); out(200, ['admin' => false]); }

if (in_array($action, ['recipe_save', 'recipe_delete', 'recipe_revert'], true)) {
    if (!$admin) out(403, ['error' => 'keeper_only']);
    $cat = (string)($in['cat'] ?? ''); $slug = (string)($in['slug'] ?? '');
    if (!preg_match('/^[a-z0-9-]{1,60}$/', $cat) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $slug)) out(400, ['error' => 'ids']);
    $st = $db->prepare('SELECT deleted FROM recipes WHERE cat=? AND slug=?'); $st->execute([$cat, $slug]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($action === 'recipe_revert') { $db->prepare('DELETE FROM recipes WHERE cat=? AND slug=?')->execute([$cat, $slug]); out(200, ['ok' => true]); }
    if ($action === 'recipe_delete') {
        if ($row) $db->prepare('UPDATE recipes SET deleted=1, data=NULL WHERE cat=? AND slug=?')->execute([$cat, $slug]);
        else $db->prepare('INSERT INTO recipes(cat,slug,deleted,data,ts) VALUES(?,?,1,NULL,?)')->execute([$cat, $slug, time()]);
        out(200, ['ok' => true]);
    }
    $r = cleanRecipe($in['recipe'] ?? null);
    if (!$r) out(400, ['error' => 'title']);
    if (!empty($in['isNew']) && $row) out(409, ['error' => 'exists']);
    $r['slug'] = $slug; $r['cat'] = $cat;
    $json = json_encode($r, JSON_UNESCAPED_UNICODE);
    if ($row) $db->prepare('UPDATE recipes SET deleted=0, data=? WHERE cat=? AND slug=?')->execute([$json, $cat, $slug]);
    else $db->prepare('INSERT INTO recipes(cat,slug,deleted,data,ts) VALUES(?,?,0,?,?)')->execute([$cat, $slug, $json, time()]);
    out(200, ['ok' => true]);
}

if ($action === 'create') {
    if (!empty($in['website'])) out(200, ['ok' => true]);               // honeypot: bots fill this in, silently drop
    $kind = (string)($in['kind'] ?? '');
    if (!in_array($kind, ['comment', 'thread', 'reply'], true)) out(400, ['error' => 'kind']);
    $text = clean((string)($in['text'] ?? ''), 4000, true);
    $name = clean((string)($in['name'] ?? ''), 40, false);
    $title = $kind === 'thread' ? clean((string)($in['title'] ?? ''), 120, false) : '';
    $target = clean((string)($in['target'] ?? ''), 120, false);
    if ($text === '') out(400, ['error' => 'empty']);
    if ($kind === 'thread' && $title === '') out(400, ['error' => 'title']);
    if (substr_count(strtolower($text), 'http') > 2) out(400, ['error' => 'links']);
    if ($name === '') $name = 'A family member';
    if ($kind === 'comment') { if (!preg_match('/^[a-z0-9-]+~[A-Za-z0-9_.-]+$/', $target)) out(400, ['error' => 'target']); }
    elseif ($kind === 'reply') {
        $st = $db->prepare("SELECT COUNT(*) FROM posts WHERE id=? AND kind='thread'"); $st->execute([(int)$target]);
        if (!(int)$st->fetchColumn()) out(404, ['error' => 'no_thread']);
        $target = (string)(int)$target;
    } else $target = '';
    if (!$admin && (limited($db, $ip, 'post', 4, 60) || limited($db, $ip, 'post', 60, 86400))) out(429, ['error' => 'slow_down']);
    mark($db, $ip, 'post');
    $token = bin2hex(random_bytes(16));
    $now = time();
    $db->prepare('INSERT INTO posts(kind,target,title,text,name,token_hash,ts) VALUES(?,?,?,?,?,?,?)')->execute([$kind, $target, $title, $text, $name, hash('sha256', $token), $now]);
    $id = (string)$db->lastInsertId();
    out(200, ['id' => $id, 'token' => $token]);
}

if ($action === 'edit' || $action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    $st = $db->prepare('SELECT id,kind,token_hash FROM posts WHERE id=?'); $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) out(404, ['error' => 'not_found']);
    $owns = $admin || hash_equals($row['token_hash'], hash('sha256', (string)($in['token'] ?? '')));
    if (!$owns) out(403, ['error' => 'not_yours']);
    if ($action === 'edit') {
        $text = clean((string)($in['text'] ?? ''), 4000, true);
        if ($text === '') out(400, ['error' => 'empty']);
        $db->prepare('UPDATE posts SET text=?, edited=? WHERE id=?')->execute([$text, time(), $id]);
    } else {
        if ($row['kind'] === 'thread') $db->prepare("DELETE FROM posts WHERE kind='reply' AND target=?")->execute([(string)$id]);
        $db->prepare('DELETE FROM posts WHERE id=?')->execute([$id]);
    }
    out(200, ['ok' => true]);
}
out(400, ['error' => 'action']);
