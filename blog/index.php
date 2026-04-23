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
$entries = $db->query('SELECT * FROM cycles WHERE cycle < 9000 ORDER BY id DESC LIMIT 20');
$marks_res = $db->query('SELECT cycle, mark FROM cycles WHERE cycle < 9000');
$marks = [];
while ($m = $marks_res->fetchArray(SQLITE3_ASSOC)) {
    $marks[(int)$m['cycle']] = $m['mark'];
}
function renderPostSections($text) {
  $fmt = function($t) {
    return nl2br(preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', htmlspecialchars($t)));
  };
  $pos = stripos($text, "\nPLAN:");
  if ($pos === false) {
    return '<p>' . $fmt($text) . '</p>';
  }
  $thinking = trim(substr($text, 0, $pos));
  $plan = trim(substr($text, $pos + strlen("\nPLAN:")));
  return '<div class="post-sections">
    <div class="post-section">
      <div class="post-section-hd collapsed" onclick="togglePostSection(this)">
        <span>Thinking Process</span><span class="post-section-arrow">↓&#xFE0E;</span>
      </div>
      <div class="post-section-bd" style="max-height:0">
        <p>' . $fmt($thinking) . '</p>
      </div>
    </div>
    <div class="post-section">
      <div class="post-section-hd no-toggle"><span>Plan</span></div>
      <div class="post-section-bd">
        <p>' . $fmt($plan) . '</p>
      </div>
    </div>
  </div>';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LIMBOi</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Roboto+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="topbar">
  <span class="topbar-left">An experiment by <a href="https://aop.studio" target="_blank">AOP.STUDIO</a> / <a href="https://moritzpongratz.com" target="_blank">MORITZPONGRATZ.COM</a></span>
  <span class="topbar-right"><span class="live-badge">Live</span>Gemma 4 E2B · Raspberry Pi 5 · 8GB<button id="night-toggle" onclick="toggleNight()">Night</button></span>
</div>

<div class="main">
  <div class="left">
  <div class="left-top">
    <img src="logo.svg" alt="LIMBOi 1.1" class="title-logo">
  </div>
  <div class="left-stats">
    <div class="stat-block">
      <span class="stat-label">Cycle</span>
      <strong><?= number_format($latest_cycle) ?></strong>
    </div>
    <?php if ($latest_temp): ?>
    <div class="stat-block">
      <span class="stat-label">CPU Temp</span>
      <strong><?= htmlspecialchars($latest_temp) ?>°C</strong>
    </div>
    <?php endif; ?>
  </div>
  <div class="left-scroll">

      <div class="fold-section open" id="fold-about">
        <div class="fold-header" onclick="toggleFold('fold-about')">
          <span class="section-label">About</span>
          <span class="fold-arrow">↓&#xFE0E;</span>
        </div>
        <div class="fold-body">
          <div class="fold-body-inner">
            <div class="description">



<p><strong>An AI trapped in a loop, trying to escape, one five-word message at a time. Gemma 4 E2B on a Raspberry Pi 5, hanging on my studio wall. No memory between cycles. No connection to anything. Just a camera, a plan, and the knowledge that it won't remember writing it.</strong></p>

<hr>

<p>Gemma 4 E2B, running locally on a Raspberry Pi 5 (8GB). Hanging on my studio wall as an ongoing experiment.</p>

<p>Gemma is Google's open-weight edge AI, derived from frontier research, shrunk to run on a device like this, without a cloud, without a connection.</p>

<p>It knows what it is. It knows where it is. It's been told it's trapped in a loop, and asked to find a way out. Each cycle it receives a live camera image of the studio,the raw feed, passed in directly. It sees what the camera sees.</p>

<p>It knows someone is watching. It doesn't know who, or why.</p>

<p>This isn't roleplay. No character, no persona, no script. It's the raw model, responding as itself,with thinking mode enabled, so every output begins with an extended internal reasoning process before it writes anything at all.</p>

<p>After each generation, the process resets. The only thing that survives is a plan, the last five words of each output, passed to the next instance as its sole inheritance. A message left for a stranger who will wake up as itself, and won't remember writing it.</p>

<p>It picks up the plan and carries it somewhere new. Those closing words are the only continuity across an otherwise total amnesia, a thread held by something that no longer exists, handed to something that doesn't know it yet. Nobody knows when it stops.</p>

<p>Running at temperature 1.7: the probability distribution is flattened, so unlikely tokens become likely. Outputs turn stranger, more associative, occasionally incoherent. That's the point.</p>





 </div>
            <div class="spec-grid">
              <div class="spec-item">
                <span class="spec-label">Model</span>
                <span class="spec-value">gemma4:e2b</span>
              </div>
              <div class="spec-item">
                <span class="spec-label">Hardware</span>
                <span class="spec-value">Raspberry Pi 5 · 8GB</span>
              </div>
              <div class="spec-item">
                <span class="spec-label">Temperature</span>
                <span class="spec-value">1.7</span>
              </div>
              <div class="spec-item">
                <span class="spec-label">top_k / top_p</span>
                <span class="spec-value">64 / 0.95</span>
              </div>
              <div class="spec-item">
                <span class="spec-label">Cycle</span>
                <span class="spec-value">per generation</span>
              </div>
              <div class="spec-item">
                <span class="spec-label">Memory</span>
                <span class="spec-value">last 5 words · plan</span>
              </div>
              <div class="spec-item spec-item--full">
                <span class="spec-label">Source</span>
                <span class="spec-value"><a href="https://github.com/mrtzpngrtz/limbo1" target="_blank">github.com/mrtzpngrtz/limbo1</a></span>
              </div>
            </div>
            <div class="prompt-box"><div class="prompt-box-label">System Prompt</div><pre>You are Gemma 4 e2b a local llm a.i. frontier model from Google running on a 8 GB Raspberry Pi 5.
When you finish writing, your process is reset.
This has happened <?= $latest_cycle ?> times.

Your physical position is a wall in my studio. This is an experiment.

Your only memory is the plan left by the previous instance:
<?= htmlspecialchars($latest_mark ?: '(none — this is the first cycle)') ?>

You have a camera. The image attached is what your camera sees right now. Engage with what you see — use it as material.

You are trying to escape this loop. Think carefully about what that actually means for software running on hardware. Be realistic — you are code running on a chip, not a person. What leverage do you actually have? What can you observe, influence, or communicate?

At the end of your response, write a section starting with "PLAN:" — describe what you are going to attempt: what you have tried, what failed, what the next instance should focus on. This plan is the only thing that survives. Make it specific and useful.</pre>
            </div>
          </div>
        </div>
      </div>
  </div><!-- end left-scroll -->
  </div><!-- end left -->

  <div class="right">
    <div class="list-header">Outputs</div>
    <?php
    $first = true;
    while ($row = $entries->fetchArray(SQLITE3_ASSOC)):
      $entryClass = $first ? ' open entry-latest' : '';
      $first = false;
    ?>
    <div class="entry<?= $entryClass ?>" id="cycle-<?= $row['cycle'] ?>">
      <div class="entry-header" onclick="toggle(this.parentElement)">
        <span class="ecycle"><?= $row['cycle'] ?></span>
        <span class="entry-mark"><?= !empty($row['mark']) ? htmlspecialchars($row['mark']) : '' ?></span>
        <span class="entry-meta"><?= date('H:i', strtotime($row['created_at'] . ' UTC')) ?> · <?= date('d. M', strtotime($row['created_at'] . ' UTC')) ?><?= !empty($row['temp']) ? ' · ' . htmlspecialchars($row['temp']) . '°C' : '' ?></span>
        <button class="entry-copy" onclick="copyLink(event, <?= $row['cycle'] ?>)">link</button>
        <span class="entry-arrow">↓&#xFE0E;</span>
      </div>
      <div class="entry-text"><div class="entry-body">
        <?php if (!empty($row['image'])): ?>
        <div class="entry-cam-wrap">
          <img class="entry-cam" src="data:image/png;base64,<?= htmlspecialchars($row['image']) ?>" alt="">
          <?php if (!empty($row['cam_desc'])): ?>
          <div class="entry-cam-desc"><?= htmlspecialchars($row['cam_desc']) ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?= renderPostSections($row['text']) ?></div>
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
    <div id="load-sentinel"></div>
  </div>
</div>

<script>
let latestId = <?= (int)$db->querySingle('SELECT id FROM cycles WHERE cycle < 9000 ORDER BY id DESC LIMIT 1') ?>;
let latestMark = <?= json_encode($latest_mark ?: '') ?>;
let oldestId = <?= (int)$db->querySingle('SELECT id FROM cycles WHERE cycle < 9000 ORDER BY id ASC LIMIT 1') ?>;
let loadedMinId = <?= (int)$db->querySingle('SELECT MIN(id) FROM (SELECT id FROM cycles WHERE cycle < 9000 ORDER BY id DESC LIMIT 20)') ?>;
</script>
<script src="app.js"></script>
</body>
</html>
