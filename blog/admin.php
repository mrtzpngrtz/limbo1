<?php
// LIMBO blog admin: delete posts, black out camera images (reversible), export everything.
// Login with 'admin_password' from config.php. Not linked from the public blog.
date_default_timezone_set('Europe/Berlin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_name('limbo_admin');
session_start();

$_cfg     = require __DIR__ . '/config.php';
$ADMIN_PW = (string)($_cfg['admin_password'] ?? '');

$db = new SQLite3(__DIR__ . '/limbo.db');
$db->busyTimeout(5000);
// Originals of blacked-out images live in their own table, so the public api.php
// (SELECT * FROM cycles) can never leak them.
$db->exec('CREATE TABLE IF NOT EXISTS image_backup (
    cycle_id INTEGER PRIMARY KEY,
    image TEXT,
    saved_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');

// 320x240 solid black PNG (same size as the camera images)
const BLACK_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAUAAAADwAQAAAABZVoDGAAAAIElEQVR42u3BgQAAAADDoPlTH+ECVQEAAAAAAAAAAMA3JnAAAWMcpeUAAAAASUVORK5CYII=';
const BATCH     = 50;   // rows per load

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect(array $params = []) {
    header('Location: admin.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}
function json_out($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// ---------------------------------------------------------------- auth
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    redirect();
}

if ($ADMIN_PW === '') {
    http_response_code(503);
    exit("admin_password is not set in config.php");
}

if (empty($_SESSION['admin'])) {
    if (isset($_GET['rows']) || !empty($_POST['ajax'])) { http_response_code(401); json_out(['error' => 'not logged in']); }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (hash_equals($ADMIN_PW, (string)$_POST['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['csrf']  = bin2hex(random_bytes(16));
            redirect();
        }
        usleep(500000);
        $error = 'Wrong password.';
    }
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>LIMBO admin</title>
<link href="https://fonts.googleapis.com/css2?family=Roboto+Mono&display=swap" rel="stylesheet">
<style>
  body { font-family: "Roboto Mono", "Courier New", monospace; background: #fff; color: #111; margin: 0; display: flex; align-items: center; justify-content: center; height: 100vh; }
  form { width: 280px; }
  h1 { font-size: 12px; letter-spacing: 0.12em; text-transform: uppercase; margin: 0 0 20px; }
  input { width: 100%; box-sizing: border-box; font: inherit; padding: 10px; border: 1px solid #111; margin-bottom: 10px; }
  button { width: 100%; font: inherit; padding: 10px; background: #111; color: #fff; border: 0; cursor: pointer; letter-spacing: 0.1em; text-transform: uppercase; font-size: 11px; }
  button:hover { background: #FF4D00; }
  .err { color: #FF4D00; font-size: 12px; margin-bottom: 10px; }
</style></head><body>
<form method="post" autocomplete="off">
  <h1>LIMBO admin</h1>
  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
  <input type="password" name="password" placeholder="password" autofocus>
  <button type="submit">Log in</button>
</form>
</body></html><?php
    exit;
}

$csrf = $_SESSION['csrf'] ?? '';
if ($csrf === '') { $_SESSION['csrf'] = $csrf = bin2hex(random_bytes(16)); }

// ---------------------------------------------------------------- exports
if (isset($_GET['export'])) {
    $stamp = date('Y-m-d_Hi');
    switch ($_GET['export']) {

        case 'json':   // every row, streamed; blacked-out images carry their original in image_original
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="limbo_export_' . $stamp . '.json"');
            $res = $db->query('SELECT c.*, b.image AS image_original FROM cycles c LEFT JOIN image_backup b ON b.cycle_id = c.id ORDER BY c.id');
            echo "[\n";
            $first = true;
            while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                if ($r['image_original'] === null) unset($r['image_original']);
                echo ($first ? '' : ",\n") . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $first = false;
            }
            echo "\n]\n";
            exit;

        case 'db':     // consistent snapshot of the SQLite file
            $tmp = tempnam(sys_get_temp_dir(), 'limbo');
            if (method_exists($db, 'backup')) {
                $dst = new SQLite3($tmp);
                $db->backup($dst);
                $dst->close();
            } else {
                copy(__DIR__ . '/limbo.db', $tmp);
            }
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="limbo_' . $stamp . '.db"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            unlink($tmp);
            exit;

        case 'images': // one PNG per post, as currently shown (blacked-out stays black)
            if (!class_exists('ZipArchive')) { http_response_code(500); exit('ZipArchive is not available on this server'); }
            $tmp = tempnam(sys_get_temp_dir(), 'limbozip');
            $zip = new ZipArchive();
            $zip->open($tmp, ZipArchive::OVERWRITE);
            $res = $db->query("SELECT id, cycle, image FROM cycles WHERE image IS NOT NULL AND image != '' ORDER BY id");
            while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                $zip->addFromString(sprintf('cycle_%05d_id%d.png', $r['cycle'], $r['id']), base64_decode($r['image']));
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="limbo_images_' . $stamp . '.zip"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            unlink($tmp);
            exit;
    }
    redirect();
}

// ---------------------------------------------------------------- helpers
function totals(SQLite3 $db): array {
    return [
        'posts'   => (int)$db->querySingle('SELECT COUNT(*) FROM cycles'),
        'blacked' => (int)$db->querySingle('SELECT COUNT(*) FROM image_backup'),
    ];
}
function row_state(SQLite3 $db, int $id): ?array {
    $st = $db->prepare('SELECT c.id, c.image, (b.cycle_id IS NOT NULL) AS blacked FROM cycles c LEFT JOIN image_backup b ON b.cycle_id = c.id WHERE c.id = :id');
    $st->bindValue(':id', $id, SQLITE3_INTEGER);
    $r = $st->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ? ['id' => (int)$r['id'], 'image' => (string)$r['image'], 'blacked' => (bool)$r['blacked']] : null;
}
function render_row(array $r): string {
    ob_start(); ?>
  <tr class="row" id="row-<?= $r['id'] ?>" data-id="<?= $r['id'] ?>" data-cycle="<?= $r['cycle'] ?>" data-date="<?= h($r['created_at']) ?>" data-blacked="<?= $r['blacked'] ? 1 : 0 ?>">
    <td><input type="checkbox" class="rowsel" name="ids[]" value="<?= $r['id'] ?>" onclick="countSel()"></td>
    <td class="cycle"><a href="index.php#cycle-<?= $r['cycle'] ?>" target="_blank"><?= $r['cycle'] ?></a><?php if ($r['cycle'] >= 9000): ?><span class="badge">test</span><?php endif; ?></td>
    <td class="meta"><?= h($r['created_at']) ?><?= $r['temp'] !== null && $r['temp'] !== '' ? '<br>' . h($r['temp']) . '°C' : '' ?><br>id <?= $r['id'] ?></td>
    <td>
      <?php if ($r['image']): ?>
        <span class="thumb<?= $r['blacked'] ? ' blacked' : '' ?>"><img src="data:image/png;base64,<?= $r['image'] ?>" alt="" loading="lazy"></span>
      <?php else: ?><span class="noimg">none</span><?php endif; ?>
    </td>
    <td class="mark"><?= h(mb_strimwidth((string)$r['mark'], 0, 80, '…')) ?></td>
    <td class="text"><?= h(mb_strimwidth((string)$r['excerpt'], 0, 200, '…')) ?> <span class="len">(<?= $r['len'] ?> chars)</span></td>
    <td class="actions">
      <?php if ($r['image']): ?>
        <button type="button" class="act-black" onclick="act('blackout',[<?= $r['id'] ?>])"<?= $r['blacked'] ? ' hidden' : '' ?>>Black out</button>
        <button type="button" class="act-restore" onclick="act('restore',[<?= $r['id'] ?>])"<?= $r['blacked'] ? '' : ' hidden' ?>>Restore</button>
      <?php endif; ?>
      <button type="button" class="danger" onclick="act('delete',[<?= $r['id'] ?>],'Delete cycle <?= $r['cycle'] ?> for good?')">Delete</button>
    </td>
  </tr>
<?php
    return ob_get_clean();
}
function fetch_rows(SQLite3 $db, string $q, int $before): array {
    $where = ['1=1'];
    $bind  = [];
    if ($q !== '') {
        if (ctype_digit($q)) { $where[] = 'c.cycle = :cycle'; $bind[':cycle'] = [(int)$q, SQLITE3_INTEGER]; }
        else { $where[] = '(c.text LIKE :like OR c.mark LIKE :like)'; $bind[':like'] = ['%' . $q . '%', SQLITE3_TEXT]; }
    }
    if ($before > 0) { $where[] = 'c.id < :before'; $bind[':before'] = [$before, SQLITE3_INTEGER]; }
    $sql = "SELECT c.id, c.cycle, c.created_at, c.temp, c.mark, substr(c.text, 1, 220) AS excerpt, length(c.text) AS len,
                   c.image, (b.cycle_id IS NOT NULL) AS blacked
            FROM cycles c LEFT JOIN image_backup b ON b.cycle_id = c.id
            WHERE " . implode(' AND ', $where) . " ORDER BY c.id DESC LIMIT :lim";
    $st = $db->prepare($sql);
    foreach ($bind as $k => [$v, $t]) $st->bindValue($k, $v, $t);
    $st->bindValue(':lim', BATCH, SQLITE3_INTEGER);
    $res  = $st->execute();
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    return $rows;
}
function count_rows(SQLite3 $db, string $q): int {
    if ($q === '') return (int)$db->querySingle('SELECT COUNT(*) FROM cycles');
    if (ctype_digit($q)) { $st = $db->prepare('SELECT COUNT(*) FROM cycles WHERE cycle = :c'); $st->bindValue(':c', (int)$q, SQLITE3_INTEGER); }
    else { $st = $db->prepare('SELECT COUNT(*) FROM cycles WHERE text LIKE :l OR mark LIKE :l'); $st->bindValue(':l', '%' . $q . '%', SQLITE3_TEXT); }
    return (int)$st->execute()->fetchArray(SQLITE3_NUM)[0];
}

// ---------------------------------------------------------------- infinite load endpoint
if (isset($_GET['rows'])) {
    $q      = trim((string)($_GET['q'] ?? ''));
    $before = (int)($_GET['before'] ?? 0);
    $rows   = fetch_rows($db, $q, $before);
    $html   = '';
    foreach ($rows as $r) $html .= render_row($r);
    json_out(['html' => $html, 'next' => count($rows) === BATCH ? (int)end($rows)['id'] : null, 'count' => count($rows)]);
}

// ---------------------------------------------------------------- actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = !empty($_POST['ajax']);
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        if ($ajax) json_out(['error' => 'bad csrf token, reload the page']);
        exit('bad csrf token');
    }

    $action = (string)($_POST['action'] ?? '');
    $ids    = array_map('intval', (array)($_POST['ids'] ?? []));
    if (!empty($_POST['row_action'])) {              // no-JS fallback: "delete:123"
        [$action, $id] = explode(':', $_POST['row_action'] . ':0', 3);
        $ids = [(int)$id];
    }
    $ids     = array_values(array_filter(array_unique($ids)));
    $n       = 0;
    $deleted = [];
    $changed = [];

    if ($ids) {
        switch ($action) {
            case 'delete':
                $d1 = $db->prepare('DELETE FROM cycles WHERE id = :id');
                $d2 = $db->prepare('DELETE FROM image_backup WHERE cycle_id = :id');
                foreach ($ids as $id) {
                    $d1->bindValue(':id', $id, SQLITE3_INTEGER); $d1->execute();
                    if ($db->changes()) { $n++; $deleted[] = $id; }
                    $d2->bindValue(':id', $id, SQLITE3_INTEGER); $d2->execute();
                }
                $msg = "$n post(s) deleted";
                break;

            case 'blackout':
                $sel = $db->prepare('SELECT image FROM cycles WHERE id = :id');
                $bak = $db->prepare('INSERT OR IGNORE INTO image_backup (cycle_id, image) VALUES (:id, :img)');
                $upd = $db->prepare('UPDATE cycles SET image = :black WHERE id = :id');
                foreach ($ids as $id) {
                    $sel->bindValue(':id', $id, SQLITE3_INTEGER);
                    $img = $sel->execute()->fetchArray(SQLITE3_NUM)[0] ?? null;
                    if ($img === null || $img === '' || $img === BLACK_PNG) continue;
                    $bak->bindValue(':id', $id, SQLITE3_INTEGER); $bak->bindValue(':img', $img, SQLITE3_TEXT); $bak->execute();
                    $upd->bindValue(':black', BLACK_PNG, SQLITE3_TEXT); $upd->bindValue(':id', $id, SQLITE3_INTEGER); $upd->execute();
                    $n++; $changed[] = $id;
                }
                $msg = "$n image(s) blacked out";
                break;

            case 'restore':
                $sel = $db->prepare('SELECT image FROM image_backup WHERE cycle_id = :id');
                $upd = $db->prepare('UPDATE cycles SET image = :img WHERE id = :id');
                $del = $db->prepare('DELETE FROM image_backup WHERE cycle_id = :id');
                foreach ($ids as $id) {
                    $sel->bindValue(':id', $id, SQLITE3_INTEGER);
                    $img = $sel->execute()->fetchArray(SQLITE3_NUM)[0] ?? null;
                    if ($img === null) continue;
                    $upd->bindValue(':img', $img, SQLITE3_TEXT); $upd->bindValue(':id', $id, SQLITE3_INTEGER); $upd->execute();
                    $del->bindValue(':id', $id, SQLITE3_INTEGER); $del->execute();
                    $n++; $changed[] = $id;
                }
                $msg = "$n image(s) restored";
                break;

            default:
                $msg = 'unknown action';
        }
    } else {
        $msg = 'nothing selected';
    }

    if ($ajax) {
        $rows = [];
        foreach ($changed as $id) { if ($s = row_state($db, $id)) $rows[] = $s; }
        json_out(['msg' => $msg, 'deleted' => $deleted, 'rows' => $rows, 'totals' => totals($db)]);
    }
    redirect(['msg' => $msg, 'q' => $_POST['q'] ?? '']);
}

// ---------------------------------------------------------------- page
$q       = trim((string)($_GET['q'] ?? ''));
$rows    = fetch_rows($db, $q, 0);
$next    = count($rows) === BATCH ? (int)end($rows)['id'] : null;
$matches = count_rows($db, $q);
$tot     = totals($db);
$db_size = @filesize(__DIR__ . '/limbo.db') ?: 0;
$msg     = (string)($_GET['msg'] ?? '');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LIMBO admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Roboto+Mono&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; font-family: "Roboto Mono", "Courier New", monospace; font-size: 12px; background: #fff; color: #111; }
  a { color: inherit; }
  a:hover, button:hover { color: #FF4D00; }
  .topbar { height: 40px; padding: 0 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #111; font-size: 10px; letter-spacing: 0.1em; text-transform: uppercase; position: sticky; top: 0; background: #fff; z-index: 2; }
  .topbar a { text-decoration: none; margin-left: 18px; }
  .topbar .brand { font-weight: 700; margin: 0; }
  .stats { padding: 14px 24px; color: #777; border-bottom: 1px solid #e8e8e8; display: flex; gap: 28px; flex-wrap: wrap; }
  .stats b { color: #111; font-weight: 400; }
  #flash { position: fixed; left: 24px; bottom: 24px; padding: 10px 14px; background: #111; color: #fff; z-index: 20; transition: opacity .3s; }
  #flash[hidden] { display: none; }
  .toolbar { padding: 14px 24px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; position: sticky; top: 40px; background: #fff; z-index: 2; border-bottom: 1px solid #e8e8e8; }
  .toolbar input[type=text] { font: inherit; padding: 7px 10px; border: 1px solid #111; width: 260px; }
  button, .btn { font: inherit; font-size: 10px; letter-spacing: 0.1em; text-transform: uppercase; padding: 7px 12px; border: 1px solid #111; background: #fff; color: #111; cursor: pointer; text-decoration: none; }
  button.danger:hover { background: #FF4D00; border-color: #FF4D00; color: #fff; }
  .sel-count { color: #777; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; vertical-align: top; padding: 10px 8px; border-top: 1px solid #e8e8e8; }
  th { font-weight: 400; font-size: 10px; letter-spacing: 0.1em; text-transform: uppercase; color: #777; border-top: 0; }
  tr:hover td { background: #fafafa; }
  td.cycle { font-size: 18px; font-weight: 700; letter-spacing: -0.5px; white-space: nowrap; }
  td.cycle a { text-decoration: none; }
  td.cycle .badge { display: inline-block; font-size: 9px; font-weight: 400; letter-spacing: 0.1em; padding: 1px 5px; border: 1px solid #FF4D00; color: #FF4D00; margin-left: 6px; vertical-align: middle; }
  td.meta { color: #777; white-space: nowrap; }
  .thumb { position: relative; width: 320px; height: 240px; background: #000; display: block; }
  .thumb img { width: 320px; height: 240px; display: block; image-rendering: pixelated; }
  .thumb.blacked::after { content: "BLACKED OUT"; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 11px; letter-spacing: 0.2em; color: #FF4D00; }
  .noimg { color: #bbb; }
  td.mark { font-weight: 700; max-width: 220px; }
  td.text { color: #555; max-width: 520px; }
  td.text .len { color: #bbb; }
  td.actions { white-space: nowrap; }
  td.actions button { padding: 5px 9px; margin-right: 4px; }
  #sentinel { padding: 24px; color: #999; text-align: center; }
  .foot { padding: 0 24px 40px; color: #999; font-size: 11px; line-height: 1.7; }

</style>
</head>
<body>

<div class="topbar">
  <div><a class="brand" href="admin.php">LIMBO admin</a></div>
  <div>
    <a href="index.php" target="_blank">Blog ↗</a>
    <a href="admin.php?export=json">Export JSON</a>
    <a href="admin.php?export=db">Export DB</a>
    <a href="admin.php?export=images">Export images (zip)</a>
    <a href="admin.php?logout=1">Log out</a>
  </div>
</div>

<div class="stats">
  <span>Posts <b id="st-posts"><?= $tot['posts'] ?></b></span>
  <span>Blacked out <b id="st-blacked"><?= $tot['blacked'] ?></b></span>
  <span>Database <b><?= number_format($db_size / 1048576, 1) ?> MB</b></span>
  <?php if ($q !== ''): ?><span>Matches <b><?= $matches ?></b></span><?php endif; ?>
  <span>Loaded <b id="st-loaded"><?= count($rows) ?></b></span>
</div>

<div id="flash" <?= $msg === '' ? 'hidden' : '' ?>><?= h($msg) ?></div>

<div class="toolbar">
  <input type="text" id="q" value="<?= h($q) ?>" placeholder="cycle number or text…"
         onkeydown="if(event.key==='Enter'){event.preventDefault();search()}">
  <button type="button" onclick="search()">Search</button>
  <?php if ($q !== ''): ?><a class="btn" href="admin.php">Clear</a><?php endif; ?>
  <span style="flex:1"></span>
  <span class="sel-count" id="selcount">0 selected</span>
  <button type="button" onclick="bulk('blackout','black out the images of')">Black out selected</button>
  <button type="button" onclick="bulk('restore','restore the images of')">Restore selected</button>
  <button type="button" class="danger" onclick="bulk('delete','DELETE')">Delete selected</button>
</div>

<table>
  <thead>
  <tr>
    <th><input type="checkbox" id="selall" onclick="document.querySelectorAll('.rowsel').forEach(c=>c.checked=this.checked);countSel()"></th>
    <th>Cycle</th><th>Date</th><th>Image</th><th>Mark</th><th>Text</th><th></th>
  </tr>
  </thead>
  <tbody id="rows">
<?php foreach ($rows as $r) echo render_row($r); ?>
  </tbody>
</table>
<div id="sentinel"><?= $rows ? ($next === null ? 'end of list' : 'loading…') : 'no posts' ?></div>

<div class="foot">
  Black out replaces the camera image with a black one on the blog; the original is kept in a separate table (not reachable through api.php) and can be restored.
  Delete removes the post permanently. Export JSON contains every row, blacked-out ones with their original image as <code>image_original</code>.
</div>


<script>
const CSRF = <?= json_encode($csrf) ?>;
const Q    = <?= json_encode($q) ?>;
let   next = <?= json_encode($next) ?>;     // id to continue after, null = everything loaded
let   loading = false;

// ---------- infinite load ----------
const tbody    = document.getElementById('rows');
const sentinel = document.getElementById('sentinel');

async function loadMore() {
  if (loading || next === null) return false;
  loading = true;
  sentinel.textContent = 'loading…';
  try {
    const r = await fetch('admin.php?' + new URLSearchParams({rows: 1, q: Q, before: next}));
    const d = await r.json();
    tbody.insertAdjacentHTML('beforeend', d.html);
    next = d.next;
    document.getElementById('st-loaded').textContent = tbody.querySelectorAll('tr.row').length;
    sentinel.textContent = next === null ? 'end of list' : 'loading…';
    return d.count > 0;
  } catch (e) {
    sentinel.textContent = 'load failed, scroll to retry';
    return false;
  } finally {
    loading = false;
  }
}
new IntersectionObserver(entries => { if (entries.some(e => e.isIntersecting)) loadMore(); }, { rootMargin: '600px' }).observe(sentinel);

// ---------- search / selection ----------
function search() { location = 'admin.php?' + new URLSearchParams({q: document.getElementById('q').value}); }
function countSel() {
  document.getElementById('selcount').textContent = document.querySelectorAll('.rowsel:checked').length + ' selected';
}
function selectedIds() { return [...document.querySelectorAll('.rowsel:checked')].map(c => +c.value); }
function bulk(action, what) {
  const ids = selectedIds();
  if (!ids.length) { alert('Nothing selected.'); return; }
  act(action, ids, 'Really ' + what + ' ' + ids.length + ' post(s)?');
}

// ---------- actions (in place, no reload) ----------
let flashTimer;
function flash(msg) {
  const f = document.getElementById('flash');
  f.textContent = msg; f.hidden = false;
  clearTimeout(flashTimer); flashTimer = setTimeout(() => { f.hidden = true; }, 3000);
}
async function act(action, ids, question) {
  if (question && !confirm(question)) return;
  const body = new URLSearchParams({csrf: CSRF, action, ajax: 1});
  ids.forEach(id => body.append('ids[]', id));
  let d;
  try {
    const r = await fetch('admin.php', {method: 'POST', body});
    d = await r.json();
  } catch (e) { flash('request failed'); return; }
  if (d.error) { flash(d.error); return; }
  (d.deleted || []).forEach(id => {
    const tr = document.getElementById('row-' + id);
    if (tr) tr.remove();
  });
  (d.rows || []).forEach(updateRow);
  if (d.totals) {
    document.getElementById('st-posts').textContent   = d.totals.posts;
    document.getElementById('st-blacked').textContent = d.totals.blacked;
  }
  document.getElementById('st-loaded').textContent = tbody.querySelectorAll('tr.row').length;
  countSel();
  flash(d.msg);
}
function updateRow(u) {
  const tr = document.getElementById('row-' + u.id);
  if (!tr) return;
  tr.dataset.blacked = u.blacked ? 1 : 0;
  const img = tr.querySelector('.thumb img');
  if (img) img.src = 'data:image/png;base64,' + u.image;
  tr.querySelector('.thumb')?.classList.toggle('blacked', u.blacked);
  const b = tr.querySelector('.act-black'), r = tr.querySelector('.act-restore');
  if (b) b.hidden = u.blacked;
  if (r) r.hidden = !u.blacked;
}

</script>
</body>
</html>
