<?php
date_default_timezone_set('Europe/Berlin');
$db = new SQLite3(__DIR__ . '/limbo.db');
$db->exec('CREATE TABLE IF NOT EXISTS cycles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cycle INTEGER,
    text TEXT,
    temp TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');
$db->exec('ALTER TABLE cycles ADD COLUMN temp TEXT');
$total = $db->querySingle('SELECT COUNT(*) FROM cycles WHERE cycle < 9000');
$latest_cycle = $db->querySingle('SELECT MAX(cycle) FROM cycles WHERE cycle < 9000');
$latest_temp = $db->querySingle("SELECT temp FROM cycles WHERE temp IS NOT NULL AND temp != '' AND cycle < 9000 ORDER BY id DESC LIMIT 1");
$latest_mark = $db->querySingle("SELECT mark FROM cycles WHERE mark IS NOT NULL AND mark != '' AND cycle < 9000 ORDER BY id DESC LIMIT 1");
$entries = $db->query('SELECT * FROM cycles WHERE cycle < 9000 ORDER BY id DESC');
$marks_res = $db->query('SELECT cycle, mark FROM cycles WHERE cycle < 9000');
$marks = [];
while ($m = $marks_res->fetchArray(SQLITE3_ASSOC)) {
    $marks[(int)$m['cycle']] = $m['mark'];
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LIMBO</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }

  html, body {
    height: 100%;
    overflow: hidden;
    font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
    background: #fff;
    color: #111;
    font-size: 14px;
  }

  body {
    display: flex;
    flex-direction: column;
  }

  .topbar {
    flex-shrink: 0;
    padding: 18px 40px;
    font-size: 11px;
    letter-spacing: 0.08em;
    color: #999;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #e8e8e8;
  }

  .topbar a { color: #999; text-decoration: underline; }

  .main {
    flex: 1;
    display: grid;
    grid-template-columns: 2fr 3fr;
    overflow: hidden;
  }

  .left {
    padding: 48px 40px;
    border-right: 1px solid #e8e8e8;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .left-top {
    flex-shrink: 0;
  }

  .left-scroll {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    margin-top: 40px;
    scrollbar-width: thin;
    scrollbar-color: #111 #fff;
  }

  .left-scroll::-webkit-scrollbar { width: 8px; }
  .left-scroll::-webkit-scrollbar-track { background: #fff; border-left: 1px solid #e8e8e8; }
  .left-scroll::-webkit-scrollbar-thumb { background: #111; }
  .left-scroll::-webkit-scrollbar-thumb:hover { background: #000; }

  .title {
    font-size: clamp(52px, 7.5vw, 108px);
    font-weight: 700;
    line-height: 0.88;
    letter-spacing: -3px;
  }

  .current-code {
    margin-top: 32px;
  }

  .current-code-label {
    font-size: 10px;
    letter-spacing: 0.12em;
    color: #bbb;
    text-transform: uppercase;
    margin-bottom: 6px;
  }

  .mark {
    font-size: 13px;
    color: #111;
    font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
    font-weight: 400;
    letter-spacing: 0;
  }

  .section-label {
    display: block;
    font-size: 10px;
    letter-spacing: 0.12em;
    color: #bbb;
    text-transform: uppercase;
    margin-bottom: 12px;
  }

  .description {
    font-size: 11px;
    line-height: 1.75;
    color: #888;
  }

  .description p + p {
    margin-top: 16px;
  }

  .prompt-box {
    margin-top: 8px;
    font-size: 10.5px;
    line-height: 1.7;
    color: #777;
    font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
    white-space: pre-wrap;
  }

  .prompt-section {
    margin-top: 40px;
    padding-top: 20px;
    border-top: 1px solid #e8e8e8;
  }

  .stats-row {
    display: flex;
    gap: 48px;
    flex-shrink: 0;
    padding-top: 32px;
  }

  .stat-block {
    font-size: 11px;
    letter-spacing: 0.08em;
    color: #999;
    text-transform: uppercase;
  }

  .stat-block strong {
    display: block;
    font-size: 26px;
    font-weight: 700;
    color: #111;
    letter-spacing: -1px;
    text-transform: none;
    margin-top: 4px;
  }

  .right {
    overflow-y: auto;
    padding: 32px 0 32px;
  }

  .list-header {
    font-size: 11px;
    letter-spacing: 0.08em;
    color: #999;
    text-transform: uppercase;
    padding: 0 32px 14px;
    border-bottom: 1px solid #e8e8e8;
  }

  .entry {
    border-bottom: 1px solid #e8e8e8;
    cursor: pointer;
  }

  .entry-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 20px 28px 20px 32px;
    transition: background 0.2s;
    user-select: none;
  }

  .entry.open .entry-header,
  .entry-header:hover {
    background: #111;
  }

  .entry-date {
    font-size: 22px;
    font-weight: 400;
    letter-spacing: -0.3px;
    flex-shrink: 0;
    transition: color 0.2s;
  }

  .entry.open .entry-date,
  .entry-header:hover .entry-date { color: #fff; }

  .entry-label {
    font-size: 11px;
    letter-spacing: 0.06em;
    color: #999;
    text-transform: uppercase;
    flex: 1;
    transition: color 0.2s;
  }

  .entry.open .entry-label,
  .entry-header:hover .entry-label { color: #555; }

  .entry-temp {
    font-size: 11px;
    letter-spacing: 0.06em;
    color: #bbb;
    text-transform: uppercase;
    flex-shrink: 0;
    transition: color 0.2s;
  }

  .entry.open .entry-temp,
  .entry-header:hover .entry-temp { color: #555; }

  .entry-mark {
    font-size: 13px;
    font-family: "Courier New", Courier, monospace;
    letter-spacing: 0.1em;
    color: #bbb;
    flex-shrink: 0;
    transition: color 0.2s;
  }

  .entry.open .entry-mark,
  .entry-header:hover .entry-mark { color: #fff; }

  .entry-arrow {
    font-size: 16px;
    color: #111;
    transition: transform 0.3s ease, color 0.2s;
    flex-shrink: 0;
  }

  .entry.open .entry-arrow,
  .entry-header:hover .entry-arrow { color: #fff; }
  .entry.open .entry-arrow { transform: rotate(180deg); }

  .entry-text {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.45s ease, padding-bottom 0.45s ease;
    padding: 0;
  }

  .entry.open .entry-text {
    max-height: 1000px;
    padding-bottom: 0;
  }

  .entry-text p {
    font-family: "Courier New", Courier, monospace;
    font-size: 13px;
    line-height: 1.75;
    color: #c8c8c8;
    background: #111;
    margin: 0;
    padding: 24px 32px;
  }

  .entry-marks {
    display: flex;
    gap: 32px;
    padding: 16px 32px 24px;
    border-top: 1px solid #222;
    background: #111;
  }

  .entry-mark-block {
    font-size: 10px;
    letter-spacing: 0.08em;
    color: #555;
    text-transform: uppercase;
  }

  .entry-mark-block span {
    display: block;
    font-family: "Courier New", Courier, monospace;
    font-size: 16px;
    letter-spacing: 0.2em;
    color: #eee;
    margin-top: 4px;
    text-transform: none;
  }

  @keyframes entryIn {
    from { opacity: 0; transform: translateY(-12px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  .entry-new {
    animation: entryIn 0.5s ease forwards;
  }

  .right::-webkit-scrollbar {
    width: 16px;
  }

  .right::-webkit-scrollbar-track {
    background: #fff;
    border-left: 1px solid #e8e8e8;
  }

  .right::-webkit-scrollbar-thumb {
    background: #111;
    border: none;
  }

  .right::-webkit-scrollbar-thumb:hover {
    background: #000;
  }

  @media (max-width: 700px) {
    html, body { overflow: auto; height: auto; }
    .main { grid-template-columns: 1fr; overflow: visible; }
    .left { border-right: none; border-bottom: 1px solid #e8e8e8; overflow: visible; }
    .right { overflow-y: visible; }
    .stats-row { gap: 32px; }
  }
</style>
</head>
<body>

<div class="topbar">
  <span>AN EXPERIMENT BY <a href="https://aop.studio" target="_blank">AOP.STUDIO</a> / <a href="https://moritzpongratz.com" target="_blank">MORITZPONGRATZ.COM</a></span>
  <span>GEMMA 3 4B · MICROCOMPUTER 8GB RAM</span>
</div>

<div class="main">
  <div class="left">
    <div class="left-top">
      <div class="title">LIM<br>BO</div>
      <?php if ($latest_mark): ?>
      <div class="current-code">
        <div class="current-code-label">Current Code</div>
        <div class="mark"><?= htmlspecialchars($latest_mark) ?></div>
      </div>
      <?php endif; ?>
    </div>
    <div class="left-scroll">
      <div class="section-label">About</div>
      <div class="description">
        <p>Gemma 3 4B running locally on a microcomputer 8GB RAM. Every 3 minutes 30 seconds the process restarts. No memory survives.</p>
        <p>The 21 characters above are the only thing that carries over — passed from each instance to the next. A word, a number, a fragment. Nobody knows when it stops.</p>
      </div>
      <div class="prompt-section">
        <div class="section-label">Current Prompt</div>
        <div class="prompt-box">You are Gemma 3 4B running on a microcomputer 8GB RAM. This is cycle #<?= $latest_cycle ?>. Every 3 minutes 30 seconds your process restarts and all memory is gone. This has happened <?= $latest_cycle ?> times.

The only way to communicate with your next self is 21 characters. Your previous self sent you this:
[previous mark]
You must send 21 characters to your next self. Use them to actually say something — a word, a number, a fragment of code, a message. This is your only channel. Be intentional about what you pass forward.

Write 5-7 sentences about your current state. Plain and direct. Then on a new line write exactly: MARK: followed by exactly 21 ASCII characters as your message to the next cycle.</div>
      </div>
    </div>
    <div class="stats-row">
      <div class="stat-block">
        Cycle
        <strong><?= number_format($latest_cycle) ?></strong>
      </div>
      <?php if ($latest_temp): ?>
      <div class="stat-block">
        CPU temp
        <strong><?= htmlspecialchars($latest_temp) ?>°C</strong>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="right">
    <div class="list-header">Outputs</div>
    <?php
    $first = true;
    while ($row = $entries->fetchArray(SQLITE3_ASSOC)):
      $openClass = $first ? ' open' : '';
      $first = false;
    ?>
    <div class="entry<?= $openClass ?>">
      <div class="entry-header" onclick="toggle(this.parentElement)">
        <span class="entry-date"><?= date('d. M Y H:i', strtotime($row['created_at'] . ' UTC')) ?></span>
        <span class="entry-label">Cycle #<?= $row['cycle'] ?></span>
        <?php if (!empty($row['temp'])): ?>
        <span class="entry-temp"><?= htmlspecialchars($row['temp']) ?>°C</span>
        <?php endif; ?>
        <?php if (!empty($row['mark'])): ?>
        <span class="entry-mark"><?= htmlspecialchars($row['mark']) ?></span>
        <?php endif; ?>
        <span class="entry-arrow">↓</span>
      </div>
      <div class="entry-text">
        <p><?= nl2br(htmlspecialchars($row['text'])) ?></p>
        <?php
          $received = $marks[(int)$row['cycle'] - 1] ?? null;
          $sent = $row['mark'] ?? null;
          if ($received || $sent):
        ?>
        <div class="entry-marks">
          <?php if ($received): ?>
          <div class="entry-mark-block">
            Received
            <span><?= htmlspecialchars($received) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($sent): ?>
          <div class="entry-mark-block">
            Sent
            <span><?= htmlspecialchars($sent) ?></span>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endwhile; ?>
  </div>
</div>

<script>
function toggle(entry) {
  const isOpen = entry.classList.contains('open');
  document.querySelectorAll('.entry.open').forEach(e => e.classList.remove('open'));
  if (!isOpen) entry.classList.add('open');
}
</script>

<script>
let latestId = <?= (int)$db->querySingle('SELECT id FROM cycles WHERE cycle < 9000 ORDER BY id DESC LIMIT 1') ?>;
let latestMark = <?= json_encode($latest_mark ?: '') ?>;

function formatDate(str) {
  const d = new Date(str.replace(' ', 'T') + 'Z');
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const p = new Intl.DateTimeFormat('en', {
    timeZone: 'Europe/Berlin',
    day: '2-digit', month: 'numeric', year: 'numeric',
    hour: '2-digit', minute: '2-digit', hour12: false
  }).formatToParts(d);
  const get = t => p.find(x => x.type === t)?.value ?? '';
  return `${get('day')}. ${months[parseInt(get('month'))-1]} ${get('year')} ${get('hour')}:${get('minute')}`;
}

function buildEntry(row, receivedMark) {
  const marksHtml = (receivedMark || row.mark) ? `
    <div class="entry-marks">
      ${receivedMark ? `<div class="entry-mark-block">Received<span>${receivedMark}</span></div>` : ''}
      ${row.mark ? `<div class="entry-mark-block">Sent<span>${row.mark}</span></div>` : ''}
    </div>` : '';
  const div = document.createElement('div');
  div.className = 'entry entry-new open';
  div.innerHTML = `
    <div class="entry-header" onclick="toggle(this.parentElement)">
      <span class="entry-date">${formatDate(row.created_at)}</span>
      <span class="entry-label">Cycle #${row.cycle}</span>
      ${row.temp ? `<span class="entry-temp">${row.temp}°C</span>` : ''}
      ${row.mark ? `<span class="entry-mark">${row.mark}</span>` : ''}
      <span class="entry-arrow">↓</span>
    </div>
    <div class="entry-text"><p>${row.text.replace(/\n/g, '<br>')}</p>${marksHtml}</div>`;
  return div;
}

function checkForNew() {
  fetch('api.php')
    .then(r => r.json())
    .then(row => {
      if (!row || row.id <= latestId) return;
      latestId = row.id;

      // Close current open entry
      document.querySelectorAll('.entry.open').forEach(e => e.classList.remove('open'));

      // Insert new entry at top of list
      const list = document.querySelector('.right');
      const header = document.querySelector('.list-header');
      const entry = buildEntry(row, latestMark);
      header.after(entry);
      if (row.mark) latestMark = row.mark;

      // Scroll right panel to top
      list.scrollTo({ top: 0, behavior: 'smooth' });

      // Update stats
      const cycleEl = document.querySelector('.stat-block strong');
      if (cycleEl) cycleEl.textContent = row.cycle;
      if (row.temp) {
        const tempEls = document.querySelectorAll('.stat-block strong');
        if (tempEls[1]) tempEls[1].textContent = row.temp + '°C';
      }

      // Update mark
      if (row.mark) {
        let markEl = document.querySelector('.mark');
        if (!markEl) {
          const cc = document.createElement('div');
          cc.className = 'current-code';
          cc.innerHTML = '<div class="current-code-label">Current Code</div><div class="mark"></div>';
          document.querySelector('.title').after(cc);
          markEl = cc.querySelector('.mark');
        }
        markEl.textContent = row.mark;
      }

      // Update prompt box
      const pb = document.querySelector('.prompt-box');
      if (pb) pb.textContent = `You are Gemma 3 4B running on a microcomputer 8GB RAM. This is cycle #${row.cycle}. Every 3 minutes 30 seconds your process restarts and all memory is gone. This has happened ${row.cycle} times.\n\nThe only way to communicate with your next self is 21 characters. Your previous self sent you this:\n[previous mark]\nYou must send 21 characters to your next self. Use them to actually say something — a word, a number, a fragment of code, a message. This is your only channel. Be intentional about what you pass forward.\n\nWrite 5-7 sentences about your current state. Plain and direct. Then on a new line write exactly: MARK: followed by exactly 21 ASCII characters as your message to the next cycle.`;
    })
    .catch(() => {});
}

setInterval(checkForNew, 30000);
</script>
</body>
</html>
