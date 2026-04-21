function toggle(entry) {
  const isOpen = entry.classList.contains('open');
  document.querySelectorAll('.entry.open').forEach(e => e.classList.remove('open'));
  if (!isOpen) entry.classList.add('open');
}

function toggleFold(id) {
  document.getElementById(id).classList.toggle('open');
}

function formatParts(str) {
  const d = new Date(str.replace(' ', 'T') + 'Z');
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const p = new Intl.DateTimeFormat('en', {
    timeZone: 'Europe/Berlin',
    day: '2-digit', month: 'numeric', year: 'numeric',
    hour: '2-digit', minute: '2-digit', hour12: false
  }).formatToParts(d);
  const get = t => p.find(x => x.type === t)?.value ?? '';
  return {
    time: `${get('hour')}:${get('minute')}`,
    date: `${get('day')}. ${months[parseInt(get('month'))-1]} ${get('year')}`
  };
}

function copyLink(e, cycle) {
  e.stopPropagation();
  const url = location.href.split('#')[0] + '#cycle-' + cycle;
  navigator.clipboard.writeText(url).catch(() => {});
  const btn = e.target;
  btn.textContent = '✓';
  btn.classList.add('copied');
  setTimeout(() => { btn.textContent = 'link'; btn.classList.remove('copied'); }, 1500);
}

function buildEntry(row, receivedMark) {
  const marksHtml = (receivedMark || row.mark) ? `
    <div class="entry-marks">
      ${receivedMark ? `<div class="entry-mark-block">Received<span>${receivedMark}</span></div>` : ''}
      ${row.mark ? `<div class="entry-mark-block">Sent<span>${row.mark}</span></div>` : ''}
    </div>` : '';
  const { time, date } = formatParts(row.created_at);
  const div = document.createElement('div');
  div.className = 'entry entry-new open';
  div.id = 'cycle-' + row.cycle;
  const imgHtml = row.image ? `<img class="entry-cam" src="data:image/png;base64,${row.image}" alt="">` : '';
  div.innerHTML = `
    <div class="entry-header" onclick="toggle(this.parentElement)">
      ${row.mark ? `<span class="entry-mark">${row.mark}</span>` : ''}
      <span class="entry-meta">
        <span class="ecycle">#${row.cycle}</span>
        <span class="sep">/</span>
        <span>${time}</span>
        <span class="sep">/</span>
        <span>${date}</span>
        ${row.temp ? `<span class="sep">/</span><span>${row.temp}°C</span>` : ''}
      </span>
      <button class="entry-copy" onclick="copyLink(event, ${row.cycle})">link</button>
      <span class="entry-arrow">↓&#xFE0E;</span>
    </div>
    <div class="entry-text"><div class="entry-body">${imgHtml}<p>${row.text.replace(/\n/g, '<br>')}</p></div>${marksHtml}</div>`;
  return div;
}

function checkForNew() {
  fetch('api.php')
    .then(r => r.json())
    .then(row => {
      if (!row || row.id <= latestId) return;
      latestId = row.id;

      document.querySelectorAll('.entry.open').forEach(e => e.classList.remove('open'));

      const list = document.querySelector('.right');
      const header = document.querySelector('.list-header');
      const entry = buildEntry(row, latestMark);
      header.after(entry);
      if (row.mark) latestMark = row.mark;

      list.scrollTo({ top: 0, behavior: 'smooth' });

      const cycleEl = document.querySelector('.stat-block strong');
      if (cycleEl) cycleEl.textContent = row.cycle;
      if (row.temp) {
        const tempEls = document.querySelectorAll('.stat-block strong');
        if (tempEls[1]) tempEls[1].textContent = row.temp + '°C';
      }
    })
    .catch(() => {});
}

setInterval(checkForNew, 30000);

// Jump to linked cycle on page load
if (location.hash) {
  const target = document.querySelector(location.hash);
  if (target) {
    target.classList.add('open');
    setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }), 200);
  }
}

let loadingMore = false;

function loadMore() {
  if (loadingMore || loadedMinId <= oldestId) return;
  loadingMore = true;
  fetch(`api.php?before=${loadedMinId}`)
    .then(r => r.json())
    .then(rows => {
      if (!rows || !rows.length) { loadedMinId = 0; return; }
      const list = document.querySelector('.right');
      const sentinel = document.getElementById('load-sentinel');
      rows.forEach((row, i) => {
        const entry = buildEntry(row, null);
        entry.classList.remove('open', 'entry-new');
        entry.classList.add('entry-lazy');
        entry.style.animationDelay = `${i * 60}ms`;
        list.insertBefore(entry, sentinel);
      });
      loadedMinId = rows[rows.length - 1].id;
    })
    .catch(() => {})
    .finally(() => { loadingMore = false; });
}

const sentinel = document.getElementById('load-sentinel');
if (sentinel) {
  new IntersectionObserver(entries => {
    if (entries[0].isIntersecting) loadMore();
  }, { root: document.querySelector('.right'), threshold: 0.1 }).observe(sentinel);
}

if (window.innerWidth <= 700) {
  document.getElementById('fold-about')?.classList.remove('open');
}
