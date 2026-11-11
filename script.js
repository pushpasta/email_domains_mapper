/*
 * Email Domains Mapper - config generator logic
 *
 * This file powers the interactive config generator on the landing page.
 * Everything runs locally in the browser: no network requests are made and
 * no data leaves the page.
 *
 * The basic flow is:
 *   1. The user either pastes an existing config or fills in the form.
 *   2. collect() validates the form and turns it into plain objects.
 *   3. generate() converts those objects into a PHP config snippet.
 *   4. The copy/reset/example buttons manipulate the same form.
 *
 * The markup lives in index.html and the styles in style.css. Elements are
 * found by id or by data-field/data-err attributes, so if you rename one of
 * those in the HTML, update the matching lookup here as well.
 */

/* ---------- Theme handling ---------- */

// The theme button in the page header. Clicking it cycles the preference.
const themeSwitch = document.getElementById('themeSwitch');

// Work out which concrete theme is active after "auto" has been resolved.
// Kept as a small helper for code that needs the actual light/dark value.
function resolvedTheme() {
  const pref = localStorage.getItem('theme') || 'auto'; // saved choice, default auto
  if (pref === 'light') return 'light';
  if (pref === 'dark') return 'dark';

  // "auto" means follow the operating system setting.
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyTheme() {
  const pref = localStorage.getItem('theme') || 'auto';

  // In auto mode we remove data-theme so style.css can use its
  // prefers-color-scheme media query. Otherwise we set the explicit theme.
  if (pref === 'auto') document.documentElement.removeAttribute('data-theme');
  else document.documentElement.setAttribute('data-theme', pref);

  // Icons come from Bootstrap Icons; \u25BE is the small down triangle.
  const icons = { auto: 'bi-circle-half', light: 'bi-sun', dark: 'bi-moon-stars' };
  const labels = { auto: 'Auto', light: 'Light', dark: 'Dark' };
  themeSwitch.innerHTML = `<i class="bi ${icons[pref]}"></i> ${labels[pref]} \u25BE`;
}

// Each click moves to the next entry in this list, wrapping back to the start.
themeSwitch.addEventListener('click', () => {
  const order = ['auto', 'light', 'dark'];
  const cur = localStorage.getItem('theme') || 'auto';
  const next = order[(order.indexOf(cur) + 1) % order.length];
  localStorage.setItem('theme', next);
  applyTheme();
});

// If the OS switches between light and dark while we are in auto mode,
// re-render the button and CSS. In manual mode we leave the user's choice alone.
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
  if ((localStorage.getItem('theme') || 'auto') === 'auto') applyTheme();
});

// Apply the saved choice when the page loads.
applyTheme();


/* ---------- Helpers ---------- */

// Escape a value for use inside a single-quoted PHP string. Backslashes must
// be escaped first, otherwise the backslash added for a quote would get
// escaped a second time.
function esc(s) { return String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'"); }

// Escape text before inserting it into an HTML string. This is used for
// values that come from the user or from a pasted config, so a hostname
// containing < or & cannot break the mapping card markup.
function htmlEsc(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// Split a full host URL like "ssl://imap.example.org:993" into the three
// parts the form fields need: protocol, hostname and port. Anything that is
// not a valid-looking URL falls back to an empty ssl:// entry.
function splitHost(v) {
  if (typeof v !== 'string') return { proto: 'ssl://', host: '', port: '' };

  // The regex reads: protocol, then a hostname, then a port.
  // Hostname labels may contain letters, digits and hyphens.
  const m = v.trim().match(/^(ssl|tls):\/\/((?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?):([0-9]{1,5})$/);

  // Reject a missing match, a hostname over 253 characters, or a bad port.
  if (!m || m[2].length > 253 || Number(m[3]) < 1 || Number(m[3]) > 65535) return { proto: 'ssl://', host: '', port: '' };

  // m[1] is the protocol without ://, m[2] the hostname, m[3] the port.
  return { proto: m[1] + '://', host: m[2], port: m[3] };
}

// Check one protocol/host/port group. Returns an error message, or an empty
// string when the values are valid. The caller puts the message in the UI.
function validateHost(proto, host, port) {
  if (!proto) return 'Protocol required.';
  if (!host.trim()) return 'Host required.';
  if (!/^[a-zA-Z0-9.\-]+$/.test(host.trim())) return 'Invalid host.';
  if (!port) return 'Port required.';
  const p = parseInt(port, 10);
  if (isNaN(p) || p < 1 || p > 65535) return 'Port must be 1-65535.';
  return '';
}

// Check the domains textarea. Domains may be separated by newlines or
// commas, but at least one is required and each must look like a domain
// (letters/digits/dots/hyphens with at least one dot).
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

// Container that holds all mapping cards, and a counter used for the
// "Mapping 1", "Mapping 2" headings and the data-id attributes.
const mappingsEl = document.getElementById('mappings');
let mappingCount = 0;

// Create and insert one mapping card. `values` is optional: it can be empty
// for a fresh card, or an object with domains/imap/smtp/sieve values (as
// returned by parseConfig() or used by the example loader).
function addMapping(values) {
  values = values || {};

  // Host values may be full URLs ("ssl://imap.example.org:993"). Split them
  // into the protocol/host/port pieces that the three inputs need.
  const sh = splitHost(values.imap || values._imapFull || '');
  const ss = splitHost(values.smtp || values._smtpFull || '');
  const sv = splitHost(values.sieve || values._sieveFull || '');

  // Give every card a unique number for its heading and remove button.
  const id = ++mappingCount;
  const div = document.createElement('div');
  div.className = 'mapping';
  div.dataset.id = id;

  // The template below is the complete markup for one card. Every input has
  // a data-field name; collect() reads those names later. Each error <div>
  // has a matching data-err name so validation messages can be placed there.
  // User-provided values are passed through htmlEsc() before insertion.
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

  // The first card must stay, so refresh which remove buttons are enabled.
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

// True when any mapping card has at least one non-empty field. Used to decide
// whether loading a pasted config or starting over would throw work away.
function hasMappingEntries() {
  return [...mappingsEl.querySelectorAll('.mapping')].some(mapping =>
    ['domains', 'imapHost', 'imapPort', 'smtpHost', 'smtpPort', 'sieveHost', 'sievePort']
      .some(field => mapping.querySelector(`[data-field="${field}"]`).value.trim())
  );
}

// True when the form contains anything the user could lose: mapping values,
// pasted config text, or a non-default log level (2 is the default).
function hasUnsavedChanges() {
  return hasMappingEntries() || pasteEl.value.trim() !== '' || document.getElementById('loglevel').value !== '2';
}

// Read every mapping card, validate it, and return plain objects ready for
// generate(). The return value also tells the caller whether errors exist.
function collect() {
  const maps = [];
  let warning = '';
  let hasError = false;

  mappingsEl.querySelectorAll('.mapping').forEach(m => {
    // Small shortcut for finding a field by its data-field name.
    const g = (f) => m.querySelector(`[data-field="${f}"]`);

    // Read and trim every input in this card.
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

    // Clear errors from the previous validation run.
    m.querySelectorAll('.err').forEach(e => e.textContent = '');
    m.querySelectorAll('input,select,textarea').forEach(i => i.classList.remove('invalid'));

    // ManageSieve is optional: it only counts as provided if either the host
    // or the port was filled in.
    const sieveProvided = !!(sieveHost || sievePort);

    // Ignore a completely empty card (for example the initial blank one).
    if (!domainsRaw && !imapHost && !imapPort && !smtpHost && !smtpPort && !sieveHost && !sievePort) return;

    // Run each validation rule. An empty string means "no error".
    const dErr = validateDomains(domainsRaw);
    const iErr = validateHost(imapProto, imapHost, imapPort);
    const sErr = validateHost(smtpProto, smtpHost, smtpPort);
    const vErr = sieveProvided ? validateHost(sieveProto, sieveHost, sievePort) : '';

    // Show each message next to its group and highlight the field that is
    // most likely the problem (the first empty one, otherwise the protocol).
    if (dErr) { m.querySelector('[data-err="domains"]').textContent = dErr; g('domains').classList.add('invalid'); hasError = true; }
    if (iErr) { m.querySelector('[data-err="imap"]').textContent = iErr; if (!imapHost) g('imapHost').classList.add('invalid'); else if (!imapPort) g('imapPort').classList.add('invalid'); else g('imapProto').classList.add('invalid'); hasError = true; }
    if (sErr) { m.querySelector('[data-err="smtp"]').textContent = sErr; if (!smtpHost) g('smtpHost').classList.add('invalid'); else if (!smtpPort) g('smtpPort').classList.add('invalid'); else g('smtpProto').classList.add('invalid'); hasError = true; }
    if (vErr) { m.querySelector('[data-err="sieve"]').textContent = vErr; if (!sieveHost) g('sieveHost').classList.add('invalid'); else if (!sievePort) g('sievePort').classList.add('invalid'); else g('sieveProto').classList.add('invalid'); hasError = true; }

    // Skip this card if it failed validation.
    if (hasError) return;

    // Turn the textarea into an array of domains and rebuild the full host
    // URLs that the PHP config expects.
    const domains = domainsRaw.split(/[\n,]/).map(d => d.trim()).filter(Boolean);
    const entry = { domains, imap: imapProto + imapHost + ':' + imapPort, smtp: smtpProto + smtpHost + ':' + smtpPort };
    if (sieveProvided) entry.sieve = sieveProto + sieveHost + ':' + sievePort;
    maps.push(entry);
  });

  // Any failed card makes the whole form invalid; the caller shows this text.
  if (hasError) warning = 'Please fix the highlighted fields.';
  return { maps, warning, hasError };
}

// Build the PHP snippet shown in the output box.
function generate() {
  const { maps, warning, hasError } = collect();
  const warnEl = document.getElementById('warning');

  // Stop early and show the validation warning if anything is wrong.
  if (hasError) { warnEl.textContent = warning; warnEl.hidden = false; return; }
  warnEl.hidden = true;

  const out = document.getElementById('output');

  // No valid mappings means there is nothing useful to generate.
  if (maps.length === 0) {
    out.textContent = '// Add at least one mapping first.';
    out.classList.add('is-placeholder');
    return;
  }

  // Real output is no longer a placeholder, so make it look normal.
  out.classList.remove('is-placeholder');
  const level = document.getElementById('loglevel').value;

  // Build the PHP one line at a time; this is easier to read than one huge
  // template string. Values are escaped for single-quoted PHP strings.
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

    // Only include ManageSieve when the user provided it.
    if (m.sieve) lines.push("            'managesieve_host' => '" + esc(m.sieve) + "',");
    lines.push('        ],');
  });
  lines.push('    ],');
  lines.push('];');
  lines.push('');

  // textContent is used instead of innerHTML so the PHP is displayed as-is.
  out.textContent = lines.join('\n');
}


/* ---------- Parse pasted config (PHP or JSON) ---------- */

/*
 * The browser cannot execute PHP, so this section contains a tiny parser for
 * the subset used by config.inc.php: arrays, strings, numbers, `=>` arrows
 * and comments. That is enough to read a normal Email Domains Mapper config
 * without pulling in a PHP runtime or a big parser library.
 *
 * The parser works in two stages:
 *   1. tokenizePhp() turns the raw text into tokens (strings, numbers, etc.).
 *   2. parsePhpArray()/parsePhpValue() turn those tokens into JavaScript
 *      values.
 */

// Turn PHP source text into a flat list of tokens.
function tokenizePhp(text) {
  const tokens = [];
  for (let i = 0; i < text.length;) {
    const c = text[i];

    // Whitespace is not significant for our parser.
    if (/\s/.test(c)) { i++; continue; }

    // Skip the PHP opening and closing tags.
    if (text.startsWith('<?php', i)) { i += 5; continue; }
    if (text.startsWith('?>', i)) { i += 2; continue; }

    // The array key/value arrow is its own token.
    if (text.startsWith('=>', i)) { tokens.push({ type: 'symbol', value: '=>' }); i += 2; continue; }

    // Line comments: // ... or # ... up to the end of the line.
    if (text.startsWith('//', i) || c === '#') {
      while (i < text.length && text[i] !== '\n') i++;
      continue;
    }

    // Block comments: /* ... */. An unclosed block comment is a parse error.
    if (text.startsWith('/*', i)) {
      const end = text.indexOf('*/', i + 2);
      if (end < 0) throw new Error('Unclosed PHP comment');
      i = end + 2;
      continue;
    }

    // Quoted strings. PHP uses backslash escapes; inside single quotes only
    // \' and \\ are special, so other backslashes are kept as written.
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

    // Numbers are only used for things like the log level.
    if (/[0-9]/.test(c)) {
      let end = i + 1;
      while (end < text.length && /[0-9]/.test(text[end])) end++;
      tokens.push({ type: 'number', value: Number(text.slice(i, end)) });
      i = end;
      continue;
    }

    // Identifiers cover things like $config and true/false. The parser only
    // needs to recognise $config, but keeping the full word is simpler.
    if (/[a-zA-Z_$]/.test(c)) {
      let end = i + 1;
      while (end < text.length && /[a-zA-Z0-9_$]/.test(text[end])) end++;
      tokens.push({ type: 'identifier', value: text.slice(i, end) });
      i = end;
      continue;
    }

    // Everything else is a symbol we care about, such as [ ] , or ( ).
    tokens.push({ type: 'symbol', value: c });
    i++;
  }
  return tokens;
}

// Parse a PHP array starting at tokens[state.i]. `state.i` is advanced past
// the closing bracket. Arrays with keys become objects; plain lists stay
// arrays. The caller reuses the same state object for nested arrays.
function parsePhpArray(tokens, state) {
  if (tokens[state.i]?.value !== '[') throw new Error('Expected PHP array');
  state.i++;
  const entries = [];
  while (state.i < tokens.length && tokens[state.i].value !== ']') {
    const first = parsePhpValue(tokens, state);

    // If the next token is =>, `first` was a key and the next value belongs
    // to it. Otherwise this is a list item with no explicit key.
    if (tokens[state.i]?.value === '=>') {
      state.i++;
      entries.push([first, parsePhpValue(tokens, state)]);
    } else entries.push([null, first]);

    // Entries are separated by commas. A missing comma is a syntax error.
    if (tokens[state.i]?.value === ',') state.i++;
    else if (tokens[state.i]?.value !== ']') throw new Error('Expected comma in PHP array');
  }
  if (tokens[state.i]?.value !== ']') throw new Error('Unclosed PHP array');
  state.i++;

  // No keys anywhere means this was a simple list, so return a real array.
  if (entries.every(([key]) => key === null)) return entries.map(([, value]) => value);

  // Mixed/keyed arrays become plain objects. Unkeyed entries get their index
  // as a string key, matching how PHP would number them.
  const out = Object.create(null);
  entries.forEach(([key, value], index) => { out[key === null ? String(index) : String(key)] = value; });
  return out;
}

// Parse one value: either a nested array or a scalar string/number.
function parsePhpValue(tokens, state) {
  const token = tokens[state.i++];
  if (!token) throw new Error('Expected PHP value');
  if (token.value === '[') { state.i--; return parsePhpArray(tokens, state); }
  if (token.type === 'string' || token.type === 'number') return token.value;
  throw new Error('Unsupported PHP value');
}

// Look for the assignment `$config['email_domains_mapper'] = ...` and return
// the value on the right-hand side. Returns null when the assignment is not
// present, so the caller can fall back to trying JSON.
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

// Validate one mapping object from a parsed config and convert it into the
// shape addMapping() expects. Returns null for anything unusable, which the
// caller counts as a skipped entry.
function normalizeMapping(m) {
  if (!m || typeof m !== 'object' || Array.isArray(m)) return null;

  // A mapping must have a domains array containing only strings.
  if (!Array.isArray(m.domains) || m.domains.some(d => typeof d !== 'string')) return null;
  const domains = m.domains;
  const normalizedDomains = domains.map(d => d.trim()).filter(Boolean);
  const domainText = normalizedDomains.join('\n');

  // IMAP and SMTP are required, so both full URLs must parse correctly.
  const ih = splitHost(m.imap);
  const sh = splitHost(m.smtp);
  if (validateDomains(domainText) || !ih.host || !sh.host) return null;

  // ManageSieve is optional. Keep it only if its URL parses.
  const vh = splitHost(m.managesieve_host);
  return {
    domains: domainText,
    imap: ih.host ? m.imap : '', smtp: sh.host ? m.smtp : '',
    sieve: vh.host ? m.managesieve_host : ''
  };
}

// Read either a JSON config or a PHP config and return a normalized result.
// The result is always an object with logLevel, maps and skipped fields.
function parseConfig(text) {
  text = text.trim();
  let cfg;

  // JSON is easy: if the text starts like an object/array, try JSON.parse().
  if (text.startsWith('{') || text.startsWith('[')) {
    try { cfg = JSON.parse(text); } catch (e) { cfg = null; }
  }

  // If JSON did not work, try the small PHP parser above.
  if (!cfg) {
    try { cfg = readPhpConfig(text); } catch (e) { return { logLevel: null, maps: [], skipped: 0 }; }
  }
  if (!cfg) return { logLevel: null, maps: [], skipped: 0 };

  // Accept either { email_domains_mapper: {...} } or the inner config itself.
  if (cfg.email_domains_mapper && typeof cfg.email_domains_mapper === 'object') cfg = cfg.email_domains_mapper;

  // mappings may be an array or an object keyed by domain. Normalize both.
  const rawMaps = Array.isArray(cfg) ? cfg : (Array.isArray(cfg.mappings) ? cfg.mappings :
    (cfg.mappings && typeof cfg.mappings === 'object' ? Object.values(cfg.mappings) : []));
  const maps = [];
  let skipped = 0;
  rawMaps.forEach(m => {
    const normalized = normalizeMapping(m);
    if (normalized) maps.push(normalized);
    else skipped++;
  });

  // Only accept a whole-number log level in the supported 0-4 range.
  const level = cfg.log_level;
  const logLevel = Number.isInteger(level) && level >= 0 && level <= 4 ? level : null;
  return { logLevel, maps, skipped };
}


/* ---------- Wire up the UI ---------- */

// Elements used by the paste/import flow.
const pasteEl = document.getElementById('paste');
const loadBtn = document.getElementById('loadPasted');

// "Load into form" is only useful when there is something to load, so keep
// it disabled while the textarea is empty.
function updateLoadBtn() { loadBtn.disabled = !pasteEl.value.trim(); }
pasteEl.addEventListener('input', updateLoadBtn);
updateLoadBtn();

// Import a pasted config into the form.
document.getElementById('loadPasted').addEventListener('click', () => {
  const text = document.getElementById('paste').value;
  const status = document.getElementById('loadStatus');
  const parsed = parseConfig(text);

  // Nothing usable was found: tell the user and leave the form untouched.
  if (!parsed || (!parsed.maps.length && parsed.logLevel == null)) {
    status.textContent = 'Could not parse any mappings.';
    status.className = 'note warn';
    return;
  }

  // Loading replaces the current mappings, so ask first if the user has
  // already typed something into the form.
  const hasCurrentEntries = hasMappingEntries();
  if (hasCurrentEntries && !window.confirm('Loading this config will replace the mapping values currently in the form. Continue?')) return;

  // Apply the log level (if the pasted config had one) and replace all cards.
  if (parsed.logLevel != null) document.getElementById('loglevel').value = parsed.logLevel;
  mappingsEl.innerHTML = '';
  mappingCount = 0;
  parsed.maps.forEach(m => addMapping(m));

  // Keep the form usable even when the config contained only a log level.
  if (parsed.maps.length === 0) addMapping();
  refreshRemoveButtons();

  // Report how many mappings were loaded and how many invalid ones were skipped.
  status.textContent = `Loaded ${parsed.maps.length} mapping(s).${parsed.skipped ? ` Skipped ${parsed.skipped} invalid entr${parsed.skipped === 1 ? 'y' : 'ies'}.` : ''}`;
  status.className = 'note ok';
});

// "Add mapping" appends a fresh, empty card.
document.getElementById('addMapping').addEventListener('click', () => addMapping());

// "Generate config" reads the form and fills the output box.
document.getElementById('generate').addEventListener('click', generate);

// "Start over" clears the form after confirming if there is work to lose.
document.getElementById('reset').addEventListener('click', () => {
  if (hasUnsavedChanges() && !window.confirm('Starting over will discard all changes you made in the form. Continue?')) return;

  // Remove all cards and start again with one blank mapping.
  mappingsEl.innerHTML = '';
  mappingCount = 0;
  addMapping();

  // Put the output box back into its initial placeholder state.
  const out = document.getElementById('output');
  out.textContent = '// Click "Generate config" to produce config.inc.php contents.';
  out.classList.add('is-placeholder');
  document.getElementById('warning').hidden = true;

  // Also clear the import textarea and its status message.
  document.getElementById('paste').value = '';
  document.getElementById('loadStatus').textContent = '';
});

// Remove buttons are created dynamically, so listen for clicks on the
// container and figure out which card's button was clicked.
mappingsEl.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-remove]');
  if (!btn || btn.disabled) return;
  const mapping = btn.closest('.mapping');
  if (mapping) mapping.remove();
  refreshRemoveButtons();
});

// Copy the generated PHP to the clipboard and briefly change the button label.
document.getElementById('copy').addEventListener('click', () => {
  const text = document.getElementById('output').textContent;
  navigator.clipboard.writeText(text).then(() => {
    const btn = document.getElementById('copy');
    const prev = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = prev, 1500);
  });
});

// Fill the form with a small, valid example so new users can see the format.
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

/* ---------- Back to top button ---------- */

// Show the fixed button after scrolling down, and smooth-scroll on click.
const toTopBtn = document.getElementById('toTop');
window.addEventListener('scroll', () => {
  toTopBtn.classList.toggle('visible', window.scrollY > 300);
}, { passive: true });
toTopBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

// Start with one empty mapping so the form is never completely empty.
addMapping();
