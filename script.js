/*
 * Email Domains Mapper - landing page logic
 *
 * Everything runs in the browser. No data is sent anywhere: the config
 * generator only builds a text block from the form fields and shows it.
 */

/* ---------- Theme handling ---------- */
// Remembers the user's choice in localStorage. "auto" follows the OS preference.
const themeSwitch = document.getElementById('themeSwitch');

function resolvedTheme() {
  const pref = localStorage.getItem('theme') || 'auto';
  if (pref === 'light') return 'light';
  if (pref === 'dark') return 'dark';
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyTheme() {
  const pref = localStorage.getItem('theme') || 'auto';
  // "auto" means: let the CSS media query decide, so remove the override.
  if (pref === 'auto') document.documentElement.removeAttribute('data-theme');
  else document.documentElement.setAttribute('data-theme', pref);

  // Update the button label + icon to reflect the current preference.
  const icons = { auto: 'bi-circle-half', light: 'bi-sun', dark: 'bi-moon-stars' };
  const labels = { auto: 'Auto', light: 'Light', dark: 'Dark' };
  themeSwitch.innerHTML = `<i class="bi ${icons[pref]}"></i> ${labels[pref]} \u25BE`;
}

// Cycle through Auto -> Light -> Dark -> Auto on click.
themeSwitch.addEventListener('click', () => {
  const order = ['auto', 'light', 'dark'];
  const cur = localStorage.getItem('theme') || 'auto';
  const next = order[(order.indexOf(cur) + 1) % order.length];
  localStorage.setItem('theme', next);
  applyTheme();
});

// If the OS theme changes while we're in "auto" mode, re-apply.
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
  if ((localStorage.getItem('theme') || 'auto') === 'auto') applyTheme();
});

applyTheme();


/* ---------- Helpers ---------- */

// Escape a value for use inside a single-quoted PHP string.
function esc(s) { return String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'"); }

function htmlEsc(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// Split a full host URL like "ssl://imap.example.org:993" into its parts.
function splitHost(v) {
  if (typeof v !== 'string') return { proto: 'ssl://', host: '', port: '' };
  const m = v.trim().match(/^(ssl|tls):\/\/((?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?):([0-9]{1,5})$/);
  if (!m || m[2].length > 253 || Number(m[3]) < 1 || Number(m[3]) > 65535) return { proto: 'ssl://', host: '', port: '' };
  return { proto: m[1] + '://', host: m[2], port: m[3] };
}

function validateHost(proto, host, port) {
  if (!proto) return 'Protocol required.';
  if (!host.trim()) return 'Host required.';
  if (!/^[a-zA-Z0-9.\-]+$/.test(host.trim())) return 'Invalid host.';
  if (!port) return 'Port required.';
  const p = parseInt(port, 10);
  if (isNaN(p) || p < 1 || p > 65535) return 'Port must be 1-65535.';
  return '';
}

function validateDomains(v) {
  if (!v.trim()) return 'At least one domain required.';
  const parts = v.split(/[\n,]/).map(d => d.trim()).filter(Boolean);
  if (parts.length === 0) return 'At least one domain required.';
  for (const d of parts) {
    if (!/^[a-zA-Z0-9.\-]+$/.test(d) || !d.includes('.')) return 'Invalid domain: ' + d;
  }
  return '';
}


/* ---------- Mapping cards ---------- */

const mappingsEl = document.getElementById('mappings');
let mappingCount = 0;

function addMapping(values) {
  values = values || {};
  const sh = splitHost(values.imap || values._imapFull || '');
  const ss = splitHost(values.smtp || values._smtpFull || '');
  const sv = splitHost(values.sieve || values._sieveFull || '');
  const id = ++mappingCount;
  const div = document.createElement('div');
  div.className = 'mapping';
  div.dataset.id = id;
  div.innerHTML = `
    <h4>Mapping ${id} <button class="btn danger" type="button" data-remove="${id}" aria-label="Remove mapping" style="padding:.2rem .6rem;font-size:.8rem;"><i class="bi bi-trash"></i> Remove</button></h4>
    <div class="field">
      <label>Domains (one per line)</label>
      <textarea data-field="domains" rows="3" placeholder="example.com&#10;example.net">${htmlEsc(Array.isArray(values.domains) ? values.domains.join('\n') : (values.domains || ''))}</textarea>
      <div class="err" data-err="domains"></div>
    </div>
    <div class="field">
      <label>IMAP host</label>
      <div class="host-row">
        <select data-field="imapProto"><option value="ssl://" ${sh.proto==='ssl://'?'selected':''}>ssl://</option><option value="tls://" ${sh.proto==='tls://'?'selected':''}>tls://</option></select>
        <input type="text" data-field="imapHost" placeholder="imap.example.org" value="${htmlEsc(sh.host)}">
        <input type="number" data-field="imapPort" placeholder="993" min="1" max="65535" value="${sh.port}">
      </div>
      <div class="err" data-err="imap"></div>
    </div>
    <div class="field">
      <label>SMTP host</label>
      <div class="host-row">
        <select data-field="smtpProto"><option value="ssl://" ${ss.proto==='ssl://'?'selected':''}>ssl://</option><option value="tls://" ${ss.proto==='tls://'?'selected':''}>tls://</option></select>
        <input type="text" data-field="smtpHost" placeholder="smtp.example.org" value="${htmlEsc(ss.host)}">
        <input type="number" data-field="smtpPort" placeholder="587" min="1" max="65535" value="${ss.port}">
      </div>
      <div class="err" data-err="smtp"></div>
    </div>
    <div class="field">
      <label>ManageSieve host (optional)</label>
      <div class="host-row">
        <select data-field="sieveProto"><option value="ssl://" ${sv.proto==='ssl://'?'selected':''}>ssl://</option><option value="tls://" ${sv.proto==='tls://'?'selected':''}>tls://</option></select>
        <input type="text" data-field="sieveHost" placeholder="sieve.example.org" value="${htmlEsc(sv.host)}">
        <input type="number" data-field="sievePort" placeholder="4190" min="1" max="65535" value="${sv.port}">
      </div>
      <div class="err" data-err="sieve"></div>
    </div>
  `;
  mappingsEl.appendChild(div);
  refreshRemoveButtons();
}

// Disable "Remove" when only one mapping remains (at least one is required).
function refreshRemoveButtons() {
  const btns = mappingsEl.querySelectorAll('[data-remove]');
  const onlyOne = btns.length <= 1;
  btns.forEach(b => {
    b.disabled = onlyOne;
    b.title = onlyOne ? 'At least one mapping is required' : 'Remove this mapping';
  });
}

function collect() {
  const maps = [];
  let warning = '';
  let hasError = false;
  mappingsEl.querySelectorAll('.mapping').forEach(m => {
    const g = (f) => m.querySelector(`[data-field="${f}"]`);
    const domainsRaw = g('domains').value.trim();
    const imapProto = g('imapProto').value;
    const imapHost = g('imapHost').value.trim();
    const imapPort = g('imapPort').value.trim();
    const smtpProto = g('smtpProto').value;
    const smtpHost = g('smtpHost').value.trim();
    const smtpPort = g('smtpPort').value.trim();
    const sieveProto = g('sieveProto').value;
    const sieveHost = g('sieveHost').value.trim();
    const sievePort = g('sievePort').value.trim();
    m.querySelectorAll('.err').forEach(e => e.textContent = '');
    m.querySelectorAll('input,select,textarea').forEach(i => i.classList.remove('invalid'));
    const sieveProvided = !!(sieveHost || sievePort);
    if (!domainsRaw && !imapHost && !imapPort && !smtpHost && !smtpPort && !sieveHost && !sievePort) return;
    const dErr = validateDomains(domainsRaw);
    const iErr = validateHost(imapProto, imapHost, imapPort);
    const sErr = validateHost(smtpProto, smtpHost, smtpPort);
    const vErr = sieveProvided ? validateHost(sieveProto, sieveHost, sievePort) : '';
    if (dErr) { m.querySelector('[data-err="domains"]').textContent = dErr; g('domains').classList.add('invalid'); hasError = true; }
    if (iErr) { m.querySelector('[data-err="imap"]').textContent = iErr; if (!imapHost) g('imapHost').classList.add('invalid'); else if (!imapPort) g('imapPort').classList.add('invalid'); else g('imapProto').classList.add('invalid'); hasError = true; }
    if (sErr) { m.querySelector('[data-err="smtp"]').textContent = sErr; if (!smtpHost) g('smtpHost').classList.add('invalid'); else if (!smtpPort) g('smtpPort').classList.add('invalid'); else g('smtpProto').classList.add('invalid'); hasError = true; }
    if (vErr) { m.querySelector('[data-err="sieve"]').textContent = vErr; if (!sieveHost) g('sieveHost').classList.add('invalid'); else if (!sievePort) g('sievePort').classList.add('invalid'); else g('sieveProto').classList.add('invalid'); hasError = true; }
    if (hasError) return;
    const domains = domainsRaw.split(/[\n,]/).map(d => d.trim()).filter(Boolean);
    const entry = { domains, imap: imapProto + imapHost + ':' + imapPort, smtp: smtpProto + smtpHost + ':' + smtpPort };
    if (sieveProvided) entry.sieve = sieveProto + sieveHost + ':' + sievePort;
    maps.push(entry);
  });
  if (hasError) warning = 'Please fix the highlighted fields.';
  return { maps, warning, hasError };
}

function generate() {
  const { maps, warning, hasError } = collect();
  const warnEl = document.getElementById('warning');
  if (hasError) { warnEl.textContent = warning; warnEl.hidden = false; return; }
  warnEl.hidden = true;
  const out = document.getElementById('output');
  if (maps.length === 0) {
    out.textContent = '// Add at least one mapping first.';
    out.classList.add('is-placeholder');
    return;
  }
  out.classList.remove('is-placeholder');
  const level = document.getElementById('loglevel').value;
  const lines = [];
  lines.push('<?php');
  lines.push('');
  lines.push("$config['email_domains_mapper'] = [");
  lines.push("    'log_level' => " + level + ",");
  lines.push("    'mappings' => [");
  maps.forEach((m) => {
    lines.push('        [');
    lines.push("            'domains' => ['" + m.domains.map(esc).join("', '") + "'],");
    lines.push("            'imap' => '" + esc(m.imap) + "',");
    lines.push("            'smtp' => '" + esc(m.smtp) + "',");
    if (m.sieve) lines.push("            'managesieve_host' => '" + esc(m.sieve) + "',");
    lines.push('        ],');
  });
  lines.push('    ],');
  lines.push('];');
  lines.push('');
  out.textContent = lines.join('\n');
}


/* ---------- Parse pasted config (PHP or JSON) ---------- */

function tokenizePhp(text) {
  const tokens = [];
  for (let i = 0; i < text.length;) {
    const c = text[i];
    if (/\s/.test(c)) { i++; continue; }
    if (text.startsWith('<?php', i)) { i += 5; continue; }
    if (text.startsWith('?>', i)) { i += 2; continue; }
    if (text.startsWith('=>', i)) { tokens.push({ type: 'symbol', value: '=>' }); i += 2; continue; }
    if (text.startsWith('//', i) || c === '#') {
      while (i < text.length && text[i] !== '\n') i++;
      continue;
    }
    if (text.startsWith('/*', i)) {
      const end = text.indexOf('*/', i + 2);
      if (end < 0) throw new Error('Unclosed PHP comment');
      i = end + 2;
      continue;
    }
    if (c === "'" || c === '"') {
      const quote = c;
      let value = '';
      i++;
      let closed = false;
      while (i < text.length) {
        if (text[i] === quote) { i++; closed = true; break; }
        if (text[i] === '\\' && i + 1 < text.length) {
          const next = text[i + 1];
          if (quote === "'" && next !== "'" && next !== '\\') value += '\\';
          value += next;
          i += 2;
        } else value += text[i++];
      }
      if (!closed) throw new Error('Unclosed PHP string');
      tokens.push({ type: 'string', value });
      continue;
    }
    if (/[0-9]/.test(c)) {
      let end = i + 1;
      while (end < text.length && /[0-9]/.test(text[end])) end++;
      tokens.push({ type: 'number', value: Number(text.slice(i, end)) });
      i = end;
      continue;
    }
    if (/[a-zA-Z_$]/.test(c)) {
      let end = i + 1;
      while (end < text.length && /[a-zA-Z0-9_$]/.test(text[end])) end++;
      tokens.push({ type: 'identifier', value: text.slice(i, end) });
      i = end;
      continue;
    }
    tokens.push({ type: 'symbol', value: c });
    i++;
  }
  return tokens;
}

function parsePhpArray(tokens, state) {
  if (tokens[state.i]?.value !== '[') throw new Error('Expected PHP array');
  state.i++;
  const entries = [];
  while (state.i < tokens.length && tokens[state.i].value !== ']') {
    const first = parsePhpValue(tokens, state);
    if (tokens[state.i]?.value === '=>') {
      state.i++;
      entries.push([first, parsePhpValue(tokens, state)]);
    } else entries.push([null, first]);
    if (tokens[state.i]?.value === ',') state.i++;
    else if (tokens[state.i]?.value !== ']') throw new Error('Expected comma in PHP array');
  }
  if (tokens[state.i]?.value !== ']') throw new Error('Unclosed PHP array');
  state.i++;
  if (entries.every(([key]) => key === null)) return entries.map(([, value]) => value);
  const out = Object.create(null);
  entries.forEach(([key, value], index) => { out[key === null ? String(index) : String(key)] = value; });
  return out;
}

function parsePhpValue(tokens, state) {
  const token = tokens[state.i++];
  if (!token) throw new Error('Expected PHP value');
  if (token.value === '[') { state.i--; return parsePhpArray(tokens, state); }
  if (token.type === 'string' || token.type === 'number') return token.value;
  throw new Error('Unsupported PHP value');
}

function readPhpConfig(text) {
  const tokens = tokenizePhp(text);
  for (let i = 0; i < tokens.length - 5; i++) {
    if (tokens[i].value === '$config' && tokens[i + 1].value === '[' &&
        tokens[i + 2].type === 'string' && tokens[i + 2].value === 'email_domains_mapper' &&
        tokens[i + 3].value === ']' && tokens[i + 4].value === '=') {
      const state = { i: i + 5 };
      return parsePhpValue(tokens, state);
    }
  }
  return null;
}

function normalizeMapping(m) {
  if (!m || typeof m !== 'object' || Array.isArray(m)) return null;
  if (!Array.isArray(m.domains) || m.domains.some(d => typeof d !== 'string')) return null;
  const domains = m.domains;
  const normalizedDomains = domains.map(d => d.trim()).filter(Boolean);
  const domainText = normalizedDomains.join('\n');
  const ih = splitHost(m.imap);
  const sh = splitHost(m.smtp);
  if (validateDomains(domainText) || !ih.host || !sh.host) return null;
  const vh = splitHost(m.managesieve_host);
  return {
    domains: domainText,
    imap: ih.host ? m.imap : '', smtp: sh.host ? m.smtp : '',
    sieve: vh.host ? m.managesieve_host : ''
  };
}

function parseConfig(text) {
  text = text.trim();
  let cfg;
  if (text.startsWith('{') || text.startsWith('[')) {
    try { cfg = JSON.parse(text); } catch (e) { cfg = null; }
  }
  if (!cfg) {
    try { cfg = readPhpConfig(text); } catch (e) { return { logLevel: null, maps: [], skipped: 0 }; }
  }
  if (!cfg) return { logLevel: null, maps: [], skipped: 0 };
  if (cfg.email_domains_mapper && typeof cfg.email_domains_mapper === 'object') cfg = cfg.email_domains_mapper;
  const rawMaps = Array.isArray(cfg) ? cfg : (Array.isArray(cfg.mappings) ? cfg.mappings :
    (cfg.mappings && typeof cfg.mappings === 'object' ? Object.values(cfg.mappings) : []));
  const maps = [];
  let skipped = 0;
  rawMaps.forEach(m => {
    const normalized = normalizeMapping(m);
    if (normalized) maps.push(normalized);
    else skipped++;
  });
  const level = cfg.log_level;
  const logLevel = Number.isInteger(level) && level >= 0 && level <= 4 ? level : null;
  return { logLevel, maps, skipped };
}


/* ---------- Wire up the UI ---------- */

const pasteEl = document.getElementById('paste');
const loadBtn = document.getElementById('loadPasted');

// "Load into form" is only useful when there's something to load.
function updateLoadBtn() { loadBtn.disabled = !pasteEl.value.trim(); }
pasteEl.addEventListener('input', updateLoadBtn);
updateLoadBtn();

document.getElementById('loadPasted').addEventListener('click', () => {
  const text = document.getElementById('paste').value;
  const status = document.getElementById('loadStatus');
  const parsed = parseConfig(text);
  if (!parsed || (!parsed.maps.length && parsed.logLevel == null)) {
    status.textContent = 'Could not parse any mappings.';
    status.className = 'note warn';
    return;
  }
  if (parsed.logLevel != null) document.getElementById('loglevel').value = parsed.logLevel;
  mappingsEl.innerHTML = '';
  mappingCount = 0;
  parsed.maps.forEach(m => addMapping(m));
  if (parsed.maps.length === 0) addMapping();
  refreshRemoveButtons();
  status.textContent = `Loaded ${parsed.maps.length} mapping(s).${parsed.skipped ? ` Skipped ${parsed.skipped} invalid entr${parsed.skipped === 1 ? 'y' : 'ies'}.` : ''}`;
  status.className = 'note ok';
});

document.getElementById('addMapping').addEventListener('click', () => addMapping());

document.getElementById('generate').addEventListener('click', generate);

document.getElementById('reset').addEventListener('click', () => {
  mappingsEl.innerHTML = '';
  mappingCount = 0;
  addMapping();
  const out = document.getElementById('output');
  out.textContent = '// Click "Generate config" to produce config.inc.php contents.';
  out.classList.add('is-placeholder');
  document.getElementById('warning').hidden = true;
  document.getElementById('paste').value = '';
  document.getElementById('loadStatus').textContent = '';
});

mappingsEl.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-remove]');
  if (!btn || btn.disabled) return;
  const mapping = btn.closest('.mapping');
  if (mapping) mapping.remove();
  refreshRemoveButtons();
});

document.getElementById('copy').addEventListener('click', () => {
  const text = document.getElementById('output').textContent;
  navigator.clipboard.writeText(text).then(() => {
    const btn = document.getElementById('copy');
    const prev = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = prev, 1500);
  });
});

document.getElementById('loadExample').addEventListener('click', () => {
  mappingsEl.innerHTML = '';
  mappingCount = 0;
  addMapping({
    domains: 'example.com\nexample.net',
    imap: 'ssl://imap.example.org:993',
    smtp: 'tls://smtp.example.org:587',
    sieve: 'tls://sieve.example.org:4190'
  });
  addMapping({
    domains: 'example.co.uk',
    imap: 'ssl://imap.example.co.uk:993',
    smtp: 'ssl://smtp.example.co.uk:465',
    sieve: ''
  });
});

/* Back to top button: show after scrolling down, smooth-scroll on click */
const toTopBtn = document.getElementById('toTop');
window.addEventListener('scroll', () => {
  toTopBtn.classList.toggle('visible', window.scrollY > 300);
}, { passive: true });
toTopBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

// Start with one empty mapping so the form is never completely empty.
addMapping();
