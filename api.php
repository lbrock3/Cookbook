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
    $db->exec('CREATE TABLE IF NOT EXISTS subs (id INTEGER PRIMARY KEY AUTOINCREMENT, cat TEXT NOT NULL, name TEXT NOT NULL DEFAULT "", data TEXT NOT NULL, ts INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS made (id INTEGER PRIMARY KEY AUTOINCREMENT, target TEXT NOT NULL, name TEXT NOT NULL, note TEXT NOT NULL DEFAULT "", img TEXT NOT NULL DEFAULT "", made_on TEXT NOT NULL DEFAULT "", token_hash TEXT NOT NULL, ts INTEGER NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS made_target ON made(target)');
    $db->exec('CREATE TABLE IF NOT EXISTS shares (code TEXT PRIMARY KEY, data TEXT NOT NULL, ts INTEGER NOT NULL)');
    $st = $db->query("SELECT v FROM meta WHERE k='salt'");
    $salt = $st->fetchColumn(); $st->closeCursor(); $st = null;
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
    $img = (string)($r['img'] ?? '');
    if (preg_match('#^photos/[a-f0-9]{24}\.jpg$#', $img)) $out['img'] = $img;
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
// ---- Recipe photos: stored as plain JPEG files in ./photos (random names, nothing else allowed) ----
function photoDir(): string {
    $d = __DIR__ . '/photos';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n</FilesMatch>\n");
    if (!is_file($d . '/index.html')) @file_put_contents($d . '/index.html', '');
    return $d;
}
function savePhoto(string $dataUrl): ?string {
    if (!preg_match('#^data:image/(?:jpeg|png|webp);base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) return null;
    $bin = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
    if ($bin === false || strlen($bin) < 200 || strlen($bin) > 3000000) return null;
    $info = @getimagesizefromstring($bin);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return null;
    if ($info[0] < 16 || $info[1] < 16 || $info[0] > 8000 || $info[1] > 8000) return null;
    $out = $bin;
    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        // Re-encode: guarantees a real image, drops hidden data, caps the size, flattens transparency onto white.
        $im = @imagecreatefromstring($bin);
        if (!$im) return null;
        $w = imagesx($im); $h = imagesy($im); $max = 1400;
        $s = max($w, $h) > $max ? $max / max($w, $h) : 1.0;
        $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start(); imagejpeg($dst, null, 84); $out = (string)ob_get_clean();
        imagedestroy($im); imagedestroy($dst);
        if ($out === '') return null;
    } elseif ($info[2] !== IMAGETYPE_JPEG) return null;
    $name = bin2hex(random_bytes(12)) . '.jpg';
    if (@file_put_contents(photoDir() . '/' . $name, $out) === false) return null;
    return 'photos/' . $name;
}
function deletePhoto($img): void {
    if (is_string($img) && preg_match('#^photos/([a-f0-9]{24}\.jpg)$#', $img, $m)) @unlink(__DIR__ . '/photos/' . $m[1]);
}
function imgOf($json): string {
    $d = is_string($json) ? json_decode($json, true) : null;
    return is_array($d) && isset($d['img']) && is_string($d['img']) ? $d['img'] : '';
}
function publicPost(array $r): array {
    return ['id' => (string)$r['id'], 'kind' => $r['kind'], 'target' => $r['target'], 'title' => $r['title'], 'text' => $r['text'], 'name' => $r['name'], 'ts' => (int)$r['ts'] * 1000, 'edited' => $r['edited'] ? (int)$r['edited'] * 1000 : null];
}

// ---- Automatic backups: at most one snapshot of the database a day, the newest 14 are kept in data/backups ----
function snapshot(PDO $db, string $path): bool {
    try { $db->exec("VACUUM INTO " . $db->quote($path)); return is_file($path) && filesize($path) > 0; } catch (Throwable $e) { error_log('cookbook backup: ' . $e->getMessage()); return false; }
}
function backupDir(string $dir): string {
    $b = $dir . '/backups';
    if (!is_dir($b)) @mkdir($b, 0755, true);
    if (!is_file($b . '/.htaccess')) @file_put_contents($b . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    if (!is_file($b . '/index.html')) @file_put_contents($b . '/index.html', '');
    return $b;
}
function backupList(string $dir): array {
    $o = [];
    foreach (glob($dir . '/backups/backup-*.sqlite') ?: [] as $f) $o[] = ['name' => basename($f), 'ts' => (int)filemtime($f) * 1000, 'size' => (int)filesize($f)];
    usort($o, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return $o;
}
function maybeBackup(PDO $db, string $dir): void {
    try {
        $st = $db->query("SELECT v FROM meta WHERE k='last_backup'");
        $last = (int)$st->fetchColumn(); $st->closeCursor(); $st = null;
        if (time() - $last < 82800) return;
        $db->prepare("INSERT INTO meta(k,v) VALUES('last_backup',?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([(string)time()]);
        $b = backupDir($dir);
        $f = $b . '/backup-' . gmdate('Y-m-d-Hi') . '-' . bin2hex(random_bytes(4)) . '.sqlite';
        if (!snapshot($db, $f)) { @unlink($f); return; }
        foreach (glob($b . '/tmp-*') ?: [] as $t) { if (filemtime($t) < time() - 3600) @unlink($t); }
        $all = backupList($dir);
        foreach (array_slice($all, 14) as $old) @unlink($b . '/' . $old['name']);
    } catch (Throwable $e) {}
}
maybeBackup($db, $dir);

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET' && ($_GET['action'] ?? '') === 'share') {
    $code = strtolower((string)($_GET['code'] ?? ''));
    if (!preg_match('/^[a-z0-9]{6,12}$/', $code)) out(400, ['error' => 'code']);
    $db->prepare('DELETE FROM shares WHERE ts < ?')->execute([time() - 60 * 86400]);
    $st = $db->prepare('SELECT data FROM shares WHERE code=?'); $st->execute([$code]);
    $d = $st->fetchColumn();
    if ($d === false) out(404, ['error' => 'not_found']);
    out(200, ['list' => json_decode((string)$d, true)]);
}
if ($method === 'GET' && ($_GET['action'] ?? '') === 'backup') {
    if (!$admin) { http_response_code(403); header('Content-Type: text/plain'); echo 'Sign in as keeper first.'; exit; }
    @set_time_limit(120);
    $tmp = backupDir($dir);
    $tmpDb = $tmp . '/tmp-' . bin2hex(random_bytes(8)) . '.sqlite';
    if (!snapshot($db, $tmpDb)) { @unlink($tmpDb); http_response_code(500); header('Content-Type: text/plain'); echo 'Backup failed.'; exit; }
    $stamp = gmdate('Y-m-d');
    if (class_exists('ZipArchive') && (($_GET['kind'] ?? '') !== 'db')) {
        $zipPath = $tmp . '/tmp-' . bin2hex(random_bytes(8)) . '.zip';
        $z = new ZipArchive();
        if ($z->open($zipPath, ZipArchive::CREATE) === true) {
            $z->addFile($tmpDb, 'data/database-' . $stamp . '.sqlite');
            $pd = __DIR__ . '/photos';
            foreach (glob($pd . '/*.jpg') ?: [] as $ph) $z->addFile($ph, 'photos/' . basename($ph));
            $z->addFromString('READ-ME.txt', "Family Cookbook backup, " . $stamp . ".\n\nTo restore: in the site's data folder, replace the database file (its name starts with cookbook- and ends in .sqlite; data/dbname.php tells you which one) with database-" . $stamp . ".sqlite renamed to that name, and copy the photos folder back next to index.html.\n");
            $z->close();
            @unlink($tmpDb);
            header_remove('Content-Type'); header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="family-cookbook-backup-' . $stamp . '.zip"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath); @unlink($zipPath); exit;
        }
    }
    header_remove('Content-Type'); header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="family-cookbook-database-' . $stamp . '.sqlite"');
    header('Content-Length: ' . filesize($tmpDb));
    readfile($tmpDb); @unlink($tmpDb); exit;
}
if ($method === 'GET') {
    $rows = $db->query('SELECT id,kind,target,title,text,name,ts,edited FROM posts ORDER BY ts ASC, id ASC LIMIT 3000')->fetchAll(PDO::FETCH_ASSOC);
    $recipes = [];
    foreach ($db->query('SELECT cat,slug,deleted,data FROM recipes ORDER BY ts ASC, rowid ASC')->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $recipes[] = ['cat' => $x['cat'], 'slug' => $x['slug'], 'deleted' => (int)$x['deleted'], 'r' => $x['data'] ? json_decode($x['data'], true) : null];
    }
    $subs = [];
    if ($admin) {
        foreach ($db->query('SELECT id,cat,name,data,ts FROM subs ORDER BY ts ASC, id ASC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $subs[] = ['id' => (string)$x['id'], 'cat' => $x['cat'], 'name' => $x['name'], 'ts' => (int)$x['ts'] * 1000, 'r' => json_decode($x['data'], true)];
        }
    }
    $made = [];
    foreach ($db->query('SELECT id,target,name,note,img,made_on,ts FROM made ORDER BY made_on DESC, id DESC LIMIT 2000')->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $made[] = ['id' => (string)$x['id'], 'target' => $x['target'], 'name' => $x['name'], 'note' => $x['note'], 'img' => $x['img'], 'on' => $x['made_on'], 'ts' => (int)$x['ts'] * 1000];
    }
    $res = ['posts' => array_map('publicPost', $rows), 'admin' => $admin, 'adminReady' => $configured, 'recipes' => $recipes, 'subs' => $subs, 'made' => $made];
    if ($admin) { $res['backups'] = backupList($dir); $res['zip'] = class_exists('ZipArchive'); }
    out(200, $res);
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

if (in_array($action, ['recipe_save', 'recipe_move', 'recipe_delete', 'recipe_revert', 'photo_upload', 'sub_reject'], true)) {
    if (!$admin) out(403, ['error' => 'keeper_only']);
    if ($action === 'photo_upload') {
        $img = savePhoto((string)($in['image'] ?? ''));
        if (!$img) out(400, ['error' => 'photo']);
        out(200, ['img' => $img]);
    }
    if ($action === 'sub_reject') {
        $st = $db->prepare('SELECT data FROM subs WHERE id=?'); $st->execute([(int)($in['id'] ?? 0)]);
        $d = $st->fetchColumn();
        if ($d === false) out(404, ['error' => 'not_found']);
        deletePhoto(imgOf($d));
        $db->prepare('DELETE FROM subs WHERE id=?')->execute([(int)$in['id']]);
        out(200, ['ok' => true]);
    }
    $cat = (string)($in['cat'] ?? ''); $slug = (string)($in['slug'] ?? '');
    if (!preg_match('/^[a-z0-9-]{1,60}$/', $cat) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $slug)) out(400, ['error' => 'ids']);
    $st = $db->prepare('SELECT deleted,data FROM recipes WHERE cat=? AND slug=?'); $st->execute([$cat, $slug]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $oldImg = $row ? imgOf($row['data']) : '';
    if ($action === 'recipe_revert') { $db->prepare('DELETE FROM recipes WHERE cat=? AND slug=?')->execute([$cat, $slug]); deletePhoto($oldImg); out(200, ['ok' => true]); }
    if ($action === 'recipe_delete') {
        deletePhoto($oldImg);
        if ($row) $db->prepare('UPDATE recipes SET deleted=1, data=NULL WHERE cat=? AND slug=?')->execute([$cat, $slug]);
        else $db->prepare('INSERT INTO recipes(cat,slug,deleted,data,ts) VALUES(?,?,1,NULL,?)')->execute([$cat, $slug, time()]);
        out(200, ['ok' => true]);
    }
    if ($action === 'recipe_move') {
        // Move a recipe to another chapter. The old spot keeps a small "moved to" note so old links, menus,
        // favorites and grocery lists still find it; comments and the photo go along with the recipe.
        $to = (string)($in['to'] ?? ''); $ns = (string)($in['newSlug'] ?? '');
        if (!preg_match('/^[a-z0-9-]{1,60}$/', $to) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $ns) || $to === $cat) out(400, ['error' => 'ids']);
        $r = cleanRecipe($in['recipe'] ?? null);
        if (!$r) out(400, ['error' => 'title']);
        $st = $db->prepare('SELECT deleted,data FROM recipes WHERE cat=? AND slug=?'); $st->execute([$to, $ns]);
        $dest = $st->fetch(PDO::FETCH_ASSOC);
        $reclaim = false;
        if ($dest) {                                                     // only an old "moved away" note may be taken over
            $dd = $dest['data'] ? json_decode($dest['data'], true) : null;
            if ((int)$dest['deleted'] === 1 && is_array($dd) && isset($dd['to'])) $reclaim = true; else out(409, ['error' => 'exists']);
        }
        $r['slug'] = $ns; $r['cat'] = $to;
        $json = json_encode($r, JSON_UNESCAPED_UNICODE);
        $note = json_encode(['to' => $to . '~' . ($ns), 'title' => $r['title']], JSON_UNESCAPED_UNICODE);
        $db->beginTransaction();
        try {
            if ($reclaim) $db->prepare('UPDATE recipes SET deleted=0, data=? WHERE cat=? AND slug=?')->execute([$json, $to, $ns]);
            else $db->prepare('INSERT INTO recipes(cat,slug,deleted,data,ts) VALUES(?,?,0,?,?)')->execute([$to, $ns, $json, time()]);
            if ($row) $db->prepare('UPDATE recipes SET deleted=1, data=? WHERE cat=? AND slug=?')->execute([$note, $cat, $slug]);
            else $db->prepare('INSERT INTO recipes(cat,slug,deleted,data,ts) VALUES(?,?,1,?,?)')->execute([$cat, $slug, $note, time()]);
            $db->prepare("UPDATE posts SET target=? WHERE kind='comment' AND target=?")->execute([$to . '~' . $ns, $cat . '~' . $slug]);
            $db->prepare('UPDATE made SET target=? WHERE target=?')->execute([$to . '~' . $ns, $cat . '~' . $slug]);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); out(500, ['error' => 'db_error']); }
        if ($oldImg !== '' && $oldImg !== ($r['img'] ?? '')) deletePhoto($oldImg);
        out(200, ['ok' => true]);
    }
    $r = cleanRecipe($in['recipe'] ?? null);
    if (!$r) out(400, ['error' => 'title']);
    if (!empty($in['isNew']) && $row) out(409, ['error' => 'exists']);
    $r['slug'] = $slug; $r['cat'] = $cat;
    $json = json_encode($r, JSON_UNESCAPED_UNICODE);
    if ($row) $db->prepare('UPDATE recipes SET deleted=0, data=? WHERE cat=? AND slug=?')->execute([$json, $cat, $slug]);
    else $db->prepare('INSERT INTO recipes(cat,slug,deleted,data,ts) VALUES(?,?,0,?,?)')->execute([$cat, $slug, $json, time()]);
    if ($oldImg !== '' && $oldImg !== ($r['img'] ?? '')) deletePhoto($oldImg);
    if (!empty($in['sub'])) {                                            // approved: remove from the queue (its photo now belongs to the recipe)
        $st = $db->prepare('SELECT data FROM subs WHERE id=?'); $st->execute([(int)$in['sub']]);
        $sd = $st->fetchColumn(); $st->closeCursor();
        if ($sd !== false) { $subImg = imgOf($sd); if ($subImg !== '' && $subImg !== ($r['img'] ?? '')) deletePhoto($subImg); }
        $db->prepare('DELETE FROM subs WHERE id=?')->execute([(int)$in['sub']]);
    }
    out(200, ['ok' => true]);
}

if ($action === 'made_create') {
    if (!empty($in['website'])) out(200, ['ok' => true]);
    $target = (string)($in['target'] ?? '');
    if (!preg_match('/^[a-z0-9-]+~[A-Za-z0-9_.-]+$/', $target) || strlen($target) > 120) out(400, ['error' => 'target']);
    $name = clean((string)($in['name'] ?? ''), 40, false); if ($name === '') $name = 'A family member';
    $note = clean((string)($in['note'] ?? ''), 600, true);
    $on = (string)($in['on'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || !checkdate((int)substr($on, 5, 2), (int)substr($on, 8, 2), (int)substr($on, 0, 4))) $on = gmdate('Y-m-d');
    if ($on > gmdate('Y-m-d', time() + 86400)) $on = gmdate('Y-m-d');
    if (substr_count(strtolower($note), 'http') > 1) out(400, ['error' => 'links']);
    if (!$admin && (limited($db, $ip, 'made', 8, 3600) || limited($db, $ip, 'made', 30, 86400))) out(429, ['error' => 'slow_down']);
    $img = '';
    $image = (string)($in['image'] ?? '');
    if ($image !== '') { $img = savePhoto($image) ?: ''; if ($img === '') out(400, ['error' => 'photo']); }
    mark($db, $ip, 'made');
    $token = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO made(target,name,note,img,made_on,token_hash,ts) VALUES(?,?,?,?,?,?,?)')->execute([$target, $name, $note, $img, $on, hash('sha256', $token), time()]);
    out(200, ['id' => (string)$db->lastInsertId(), 'token' => $token]);
}
if ($action === 'made_delete') {
    $st = $db->prepare('SELECT id,img,token_hash FROM made WHERE id=?'); $st->execute([(int)($in['id'] ?? 0)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) out(404, ['error' => 'not_found']);
    if (!$admin && !hash_equals($row['token_hash'], hash('sha256', (string)($in['token'] ?? '')))) out(403, ['error' => 'not_yours']);
    deletePhoto($row['img']);
    $db->prepare('DELETE FROM made WHERE id=?')->execute([(int)$row['id']]);
    out(200, ['ok' => true]);
}
if ($action === 'share_create') {
    $l = $in['list'] ?? null;
    if (!is_array($l)) out(400, ['error' => 'list']);
    $clean = ['r' => [], 'c' => [], 'm' => []];
    foreach (array_slice(is_array($l['r'] ?? null) ? $l['r'] : [], 0, 100) as $e) {
        if (is_array($e) && isset($e[0], $e[1]) && is_string($e[0]) && preg_match('/^[a-z0-9-]+~[A-Za-z0-9_.-]+$/', $e[0]) && in_array((float)$e[1], [0.5, 1.0, 2.0, 3.0, 4.0], true)) $clean['r'][] = [$e[0], (float)$e[1]];
    }
    foreach (array_slice(is_array($l['c'] ?? null) ? $l['c'] : [], 0, 600) as $k) { if (is_string($k) && strlen($k) <= 160) $clean['c'][] = $k; }
    foreach (array_slice(is_array($l['m'] ?? null) ? $l['m'] : [], 0, 100) as $m) {
        if (is_array($m) && isset($m['id'], $m['t']) && is_string($m['t']) && is_string($m['id'])) { $t = clean($m['t'], 120, false); if ($t !== '') $clean['m'][] = ['id' => substr(preg_replace('/[^A-Za-z0-9]/', '', $m['id']), 0, 16), 't' => $t]; }
    }
    if (!$clean['r'] && !$clean['m']) out(400, ['error' => 'empty']);
    if (!$admin && (limited($db, $ip, 'share', 12, 3600) || limited($db, $ip, 'share', 40, 86400))) out(429, ['error' => 'slow_down']);
    mark($db, $ip, 'share');
    $db->prepare('DELETE FROM shares WHERE ts < ?')->execute([time() - 60 * 86400]);
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 30000) out(400, ['error' => 'big']);
    $alpha = 'abcdefghjkmnpqrstuvwxyz23456789';
    for ($try = 0; $try < 8; $try++) {
        $code = ''; for ($i = 0; $i < 7; $i++) $code .= $alpha[random_int(0, strlen($alpha) - 1)];
        $st = $db->prepare('SELECT 1 FROM shares WHERE code=?'); $st->execute([$code]);
        if (!$st->fetchColumn()) { $db->prepare('INSERT INTO shares(code,data,ts) VALUES(?,?,?)')->execute([$code, $json, time()]); out(200, ['code' => $code]); }
    }
    out(500, ['error' => 'db_error']);
}

if ($action === 'sub_create') {
    if (!empty($in['website'])) out(200, ['ok' => true]);               // honeypot
    $cat = (string)($in['cat'] ?? '');
    if (!preg_match('/^[a-z0-9-]{1,60}$/', $cat)) out(400, ['error' => 'cat']);
    $r = cleanRecipe($in['recipe'] ?? null);
    if (!$r) out(400, ['error' => 'title']);
    unset($r['img']);                                                    // a visitor can only attach a new photo, never point at an existing one
    if (empty($r['ing']) || empty($r['dir'])) out(400, ['error' => 'incomplete']);
    if (substr_count(strtolower(json_encode($r)), 'http') > 3) out(400, ['error' => 'links']);
    if (!$admin && (limited($db, $ip, 'sub', 15, 3600) || limited($db, $ip, 'sub', 40, 86400))) out(429, ['error' => 'slow_down']);
    if ((int)$db->query('SELECT COUNT(*) FROM subs')->fetchColumn() >= 100) out(429, ['error' => 'full']);
    $image = (string)($in['image'] ?? '');
    if ($image !== '') {
        $img = savePhoto($image);
        if (!$img) out(400, ['error' => 'photo']);
        $r['img'] = $img;
    }
    mark($db, $ip, 'sub');
    $name = clean((string)($r['by'] ?? ''), 80, false);
    $db->prepare('INSERT INTO subs(cat,name,data,ts) VALUES(?,?,?,?)')->execute([$cat, $name, json_encode($r, JSON_UNESCAPED_UNICODE), time()]);
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
