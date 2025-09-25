<?php
// php-ini-editor.php — Tailwind iOS + API AJAX
declare(strict_types=1);

/* ------------------ CONFIG BÁSICA ------------------ */
$iniFile = $_GET['file'] ?? php_ini_loaded_file();
if (!$iniFile || !is_file($iniFile)) {
  if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'No se encontró php.ini. Usa ?file=/ruta/php.ini']); exit;
  }
  $iniFile = ''; // para que la página HTML muestre el aviso
}

/* Directivas expuestas en UI */
$editableKeys = [
  'memory_limit',
  'upload_max_filesize',
  'post_max_size',
  'max_execution_time',
  'max_input_vars',
  'display_errors',
  'error_reporting',
  'date.timezone',
];

/* Descripciones tooltips */
$dirHelp = [
  'memory_limit'        => 'Límite de memoria por script (ej. 512M, 1G).',
  'upload_max_filesize' => 'Tamaño máximo de un archivo subido (POST).',
  'post_max_size'       => 'Tamaño máximo del cuerpo POST (≥ upload_max_filesize).',
  'max_execution_time'  => 'Segundos máximos de ejecución por script.',
  'max_input_vars'      => 'Máximo de variables de entrada (GET/POST/COOKIE).',
  'display_errors'      => 'Muestra errores. ON solo en desarrollo.',
  'error_reporting'     => 'Nivel de reporte (constantes E_*).',
  'date.timezone'       => 'Zona horaria por defecto (ej. America/Mexico_City).',
];

/* Descripción de extensiones conocidas */
function extHelpText(string $raw, bool $isZend=false): string {
  $name = strtolower($raw);
  if (str_contains($name, '\\')) $name = str_replace('\\','/',$name);
  if (str_contains($name, '/'))  $name = basename($name);
  $base = preg_replace('/^php_/', '', $name);
  $base = preg_replace('/\.(so|dll)$/', '', $base);
  if (str_starts_with($base, 'oci8')) $base = 'oci8';

  $map = [
    'curl'=>'Cliente HTTP para APIs y descargas.',
    'ffi'=>'Llama funciones C desde PHP.',
    'ftp'=>'Soporte FTP/FTPS.',
    'fileinfo'=>'Detecta tipo MIME por contenido.',
    'gd'=>'Manipulación de imágenes.',
    'gettext'=>'Internacionalización (.po/.mo).',
    'gmp'=>'Aritmética de alta precisión.',
    'intl'=>'ICU: formateo, collation, transliteración.',
    'imap'=>'IMAP/POP3 de correo.',
    'mbstring'=>'Cadenas multibyte (UTF-8).',
    'exif'=>'Metadatos EXIF (depende de mbstring).',
    'mysqli'=>'Conector MySQL nativo.',
    'oci8'=>'Conector Oracle (Instant Client).',
    'odbc'=>'Acceso ODBC.',
    'ldap'=>'Conector LDAP.',
    'soap'=>'Clientes/servidores SOAP.',
    'zip'=>'Manejo de archivos ZIP.',
    'pdo_mysql'=>'PDO para MySQL.',
    'pdo_pgsql'=>'PDO para PostgreSQL.',
    'pdo_sqlsrv'=>'PDO para SQL Server.',
    'opcache'=>'Caché/optimizador (zend_extension).',
    'xdebug'=>'Depuración y profiler (zend_extension).',
  ];
  if ($isZend && isset($map[$base])) return $map[$base];
  return $map[$base] ?? ($isZend ? "Zend extension: {$raw}." : "Extensión PHP: {$raw}.");
}

/* ---------- util: leer archivo y obtener estado ---------- */
function read_state(string $iniFile, array $editableKeys, array $dirHelp): array {
  $orig = @file_get_contents($iniFile);
  if ($orig === false) throw new RuntimeException("No pude leer $iniFile");
  $lines = preg_split('/\R/', $orig);

  // Directivas (valores actuales con ini_get)
  $directives = [];
  foreach ($editableKeys as $k) {
    $directives[] = [
      'key'   => $k,
      'value' => (string)ini_get($k),
      'help'  => $dirHelp[$k] ?? 'Directiva PHP.',
    ];
  }

  // extension= y zend_extension= por LÍNEA (índice del archivo)
  $ext = []; $zext = [];
  foreach ($lines as $i => $line) {
    if (preg_match('/^\s*;?\s*extension\s*=\s*(.+?)\s*(?:;.*)?$/i', $line, $m)) {
      $raw = trim($m[1], "\"' ");
      $ext[] = ['index'=>$i,'raw'=>$raw,'enabled'=>!preg_match('/^\s*;/', $line),'help'=>extHelpText($raw,false)];
    }
    if (preg_match('/^\s*;?\s*zend_extension\s*=\s*(.+?)\s*(?:;.*)?$/i', $line, $m)) {
      $raw = trim($m[1], "\"' ");
      $zext[] = ['index'=>$i,'raw'=>$raw,'enabled'=>!preg_match('/^\s*;/', $line),'help'=>extHelpText($raw,true)];
    }
  }

  return [
    'iniFile'    => $iniFile,
    'directives' => $directives,
    'extensions' => $ext,
    'zend_exts'  => $zext,
  ];
}

/* ---------- util: guardar cambios ---------- */
function save_changes(string $iniFile, array $payload): array {
  $orig = @file_get_contents($iniFile);
  if ($orig === false) throw new RuntimeException("No pude leer $iniFile");

  $kv       = $payload['kv']       ?? [];                 // ['key'=>'value', ...]
  $extOn    = $payload['ext_on']   ?? [];                 // [lineIndex, ...]
  $zextOn   = $payload['zext_on']  ?? [];                 // [lineIndex, ...]
  $newExt   = trim((string)($payload['new_ext'] ?? ''));  // string

  $backup = $iniFile . '.bak.' . date('Ymd_His');
  @copy($iniFile, $backup);

  $updated = $orig; $changes = 0;

  // Reemplazar/crear directivas
  foreach ($kv as $key => $val) {
    if ($val === null || $val === '') continue;
    $writeVal = preg_match('/\s|;|#/', (string)$val) ? "\"$val\"" : (string)$val;
    $pattern = '/^[\h;]*' . preg_quote($key, '/') . '\h*=\h*.*$/mi';
    $replacement = $key . ' = ' . $writeVal;

    if (preg_match($pattern, $updated)) {
      $updated = preg_replace($pattern, $replacement, $updated, 1);
      $changes++;
    } else {
      if (preg_match('/^(\[PHP\]\h*(?:\R|$))/mi', $updated)) {
        $updated = preg_replace('/^(\[PHP\]\h*(?:\R|$))/mi', "$1$replacement\n", $updated, 1, $cnt);
        if (!$cnt) $updated .= "\n$replacement\n";
      } else {
        $updated .= "\n$replacement\n";
      }
      $changes++;
    }
  }

  // Toggle por línea (extension / zend_extension)
  $ulines = preg_split('/\R/', $updated);
  $extOnMap  = array_fill_keys(array_map('intval', (array)$extOn),  true);
  $zextOnMap = array_fill_keys(array_map('intval', (array)$zextOn), true);

  foreach ($ulines as $i => $line) {
    if (preg_match('/^\s*;?\s*extension\s*=\s*(.+?)\s*.*$/i', $line, $m)) {
      $raw = trim($m[1], "\"' ");
      $wantOn = isset($extOnMap[$i]);
      $isCommented = preg_match('/^\s*;/', $line);
      if ($wantOn && $isCommented) { // descomentar
        $new = preg_replace('/^\s*;(\s*)extension\s*=.*$/i', 'extension='.$raw, $line);
        if ($new === $line) $new = "extension={$raw}";
        $ulines[$i] = $new; $changes++;
      } elseif (!$wantOn && !$isCommented) { // comentar
        $ulines[$i] = ';' . $line; $changes++;
      }
    }
    if (preg_match('/^\s*;?\s*zend_extension\s*=\s*(.+?)\s*.*$/i', $line, $m)) {
      $raw = trim($m[1], "\"' ");
      $wantOn = isset($zextOnMap[$i]);
      $isCommented = preg_match('/^\s*;/', $line);
      if ($wantOn && $isCommented) {
        $new = preg_replace('/^\s*;(\s*)zend_extension\s*=.*$/i', 'zend_extension='.$raw, $line);
        if ($new === $line) $new = "zend_extension={$raw}";
        $ulines[$i] = $new; $changes++;
      } elseif (!$wantOn && !$isCommented) {
        $ulines[$i] = ';' . $line; $changes++;
      }
    }
  }

  // Agregar nueva extension
  if ($newExt !== '') {
    $ulines[] = "extension={$newExt}";
    $changes++;
  }

  if ($changes > 0) {
    $final = implode(PHP_EOL, $ulines);
    $ok = @file_put_contents($iniFile, $final);
    if ($ok === false) throw new RuntimeException("No pude escribir $iniFile. Backup: $backup");
  }

  return ['changes'=>$changes, 'backup'=>$backup];
}

/* ------------------ ROUTER API AJAX ------------------ */
$action = $_GET['action'] ?? '';
if ($action === 'state') {
  header('Content-Type: application/json');
  try {
    $state = read_state($iniFile, $editableKeys, $dirHelp);
    echo json_encode(['ok'=>true, 'state'=>$state], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $res = save_changes($iniFile, $payload);
    $state = read_state($iniFile, $editableKeys, $dirHelp);
    echo json_encode(['ok'=>true, 'message'=>"Guardado. Backup: {$res['backup']}", 'changes'=>$res['changes'], 'state'=>$state], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* ------------------ HTML (Frontend) ------------------ */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>php.ini Editor (AJAX, Tailwind iOS)</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<script src="https://cdn.tailwindcss.com"></script>
<style>
  /* Switch iOS */
  .ios-switch{ position:relative; display:inline-flex; width:44px; height:24px; border-radius:9999px; background:#d1d5db; transition:all .2s ease; }
  input[type="checkbox"].peer:checked + .ios-switch{ background:#3b82f6; }
  .ios-thumb{ position:absolute; top:2px; left:2px; width:20px; height:20px; background:#fff; border-radius:9999px; box-shadow:0 1px 2px rgba(0,0,0,.25); transition:transform .2s ease; transform:translateX(0); }
  input[type="checkbox"].peer:checked + .ios-switch .ios-thumb{ transform:translateX(20px); }
  /* Tooltip con group-hover */
  .tip .bubble{ display:none; }
  .tip:hover .bubble{ display:block; }
</style>
</head>
<body class="bg-slate-100 text-slate-800">
  <div class="max-w-5xl mx-auto p-4 md:p-8 space-y-6">
    <div class="flex items-center gap-3">
      <div class="h-10 w-10 rounded-2xl bg-white shadow flex items-center justify-center"><span class="text-xl">🛠️</span></div>
      <div>
        <h1 class="text-xl font-semibold">Editor local de <code>php.ini</code></h1>
        <div class="text-sm text-slate-500">Archivo: <span id="path" class="font-mono bg-slate-200/70 px-2 py-0.5 rounded"><?= $iniFile ? h($iniFile) : '— no detectado —' ?></span></div>
      </div>
    </div>

    <?php if (!$iniFile): ?>
      <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-900">
        No se encontró <code>php.ini</code>. Abre con <code>?file=/ruta/php.ini</code>.
      </div>
    <?php endif; ?>

    <div id="alert" class="hidden rounded-2xl border px-4 py-3"></div>

    <!-- Directivas -->
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
      <div class="px-5 py-4 border-b border-slate-100">
        <h2 class="font-medium">Directivas comunes</h2>
        <p class="text-sm text-slate-500">Todo se guarda vía AJAX.</p>
      </div>
      <div id="directives" class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4"></div>
    </div>

    <!-- Extensiones -->
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
      <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <h2 class="font-medium">Extensiones (<code>extension=</code>)</h2>
          <span class="tip relative inline-block">
            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-slate-200 text-slate-700 text-xs font-bold select-none">?</span>
            <span class="bubble absolute z-20 -top-2 left-6 min-w-[14rem] max-w-xs bg-slate-900 text-white text-xs px-3 py-2 rounded-xl shadow-lg">
              Activa o desactiva cada línea de extensión. Verifica <code>extension_dir</code>.
              <span class="absolute left-0 top-3 -ml-2 border-y-8 border-y-transparent border-r-8 border-r-slate-900"></span>
            </span>
          </span>
        </div>
        <div class="flex items-center gap-2">
          <input id="q-ext" type="text" placeholder="Buscar..." class="rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500 px-3 py-1.5 text-sm">
          <button type="button" id="ext-on"  class="rounded-xl bg-slate-200 px-3 py-1.5 text-sm">Activar todo</button>
          <button type="button" id="ext-off" class="rounded-xl bg-slate-200 px-3 py-1.5 text-sm">Desactivar todo</button>
        </div>
      </div>
      <div class="p-5">
        <ul id="ext-list" class="divide-y divide-slate-100"></ul>
        <div class="mt-4">
          <label class="flex items-center gap-1 text-sm font-semibold mb-1">
            <span>Agregar nueva extensión</span>
            <span class="tip relative inline-block">
              <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-slate-200 text-slate-700 text-xs font-bold select-none">?</span>
              <span class="bubble absolute z-20 -top-2 left-6 min-w-[14rem] max-w-xs bg-slate-900 text-white text-xs px-3 py-2 rounded-xl shadow-lg">
                Escribe el nombre o archivo: "gd", "intl", "oci8_19" o "php_gd.dll".
                <span class="absolute left-0 top-3 -ml-2 border-y-8 border-y-transparent border-r-8 border-r-slate-900"></span>
              </span>
            </span>
          </label>
          <input id="new-ext" placeholder='ej. "gd"' class="w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
        </div>
      </div>
    </div>

    <!-- Zend extensions -->
    <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
      <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <h2 class="font-medium">Zend extensions (<code>zend_extension=</code>)</h2>
          <span class="tip relative inline-block">
            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-slate-200 text-slate-700 text-xs font-bold select-none">?</span>
            <span class="bubble absolute z-20 -top-2 left-6 min-w-[14rem] max-w-xs bg-slate-900 text-white text-xs px-3 py-2 rounded-xl shadow-lg">
              Usado por xdebug u opcache. Activa/desactiva por línea.
              <span class="absolute left-0 top-3 -ml-2 border-y-8 border-y-transparent border-r-8 border-r-slate-900"></span>
            </span>
          </span>
        </div>
        <div class="flex items-center gap-2">
          <input id="q-zext" type="text" placeholder="Buscar..." class="rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500 px-3 py-1.5 text-sm">
          <button type="button" id="zext-on"  class="rounded-xl bg-slate-200 px-3 py-1.5 text-sm">Activar todo</button>
          <button type="button" id="zext-off" class="rounded-xl bg-slate-200 px-3 py-1.5 text-sm">Desactivar todo</button>
        </div>
      </div>
      <div class="p-5">
        <ul id="zext-list" class="divide-y divide-slate-100"></ul>
      </div>
    </div>

    <!-- Actions -->
    <div class="flex items-center gap-3">
      <button id="btn-save" class="rounded-2xl bg-blue-600 text-white px-5 py-2.5 font-semibold shadow hover:bg-blue-700 active:scale-[.99]">Guardar cambios</button>
      <button id="btn-reload" class="rounded-2xl bg-slate-200 text-slate-800 px-5 py-2.5 font-semibold hover:bg-slate-300" type="button">Recargar estado</button>
    </div>

    <p class="text-xs text-slate-500">
      Tras guardar, reinicia Apache/PHP-FPM/IIS para aplicar todo.
    </p>
  </div>

<script>
const $ = sel => document.querySelector(sel);
const $$ = sel => Array.from(document.querySelectorAll(sel));

function showAlert(msg, ok=true){
  const a = $('#alert');
  a.className = 'rounded-2xl px-4 py-3 ' + (ok
    ? 'border border-blue-200 bg-blue-50 text-blue-800'
    : 'border border-rose-200 bg-rose-50 text-rose-900');
  a.textContent = msg; a.classList.remove('hidden');
  setTimeout(()=>a.classList.add('hidden'), 5000);
}

// Tooltip badge (para elementos generados)
function badgeTip(text){
  const wrapper = document.createElement('span');
  wrapper.className = 'tip relative inline-block';
  wrapper.innerHTML = `
    <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-slate-200 text-slate-700 text-xs font-bold select-none">?</span>
    <span class="bubble absolute z-20 -top-2 left-6 min-w-[14rem] max-w-xs bg-slate-900 text-white text-xs px-3 py-2 rounded-xl shadow-lg">
      ${text.replace(/</g,'&lt;').replace(/>/g,'&gt;')}
      <span class="absolute left-0 top-3 -ml-2 border-y-8 border-y-transparent border-r-8 border-r-slate-900"></span>
    </span>`;
  return wrapper;
}

let STATE = null; // guardamos el último estado recibido

async function loadState(){
  const res = await fetch(`?action=state${location.search.replace(/^\?/, '&')}`);
  const data = await res.json();
  if(!data.ok) throw new Error(data.error || 'Error al cargar estado');
  STATE = data.state;
  $('#path').textContent = STATE.iniFile;

  // Render directivas
  const cont = $('#directives');
  cont.innerHTML = '';
  STATE.directives.forEach(d => {
    const wrap = document.createElement('div');
    const label = document.createElement('label');
    label.className = 'flex items-center gap-1 text-sm font-semibold mb-1';
    label.textContent = d.key;
    label.appendChild(badgeTip(d.help));
    wrap.appendChild(label);

    if (d.key === 'display_errors'){
      const sel = document.createElement('select');
      sel.className = 'w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500';
      ['On','Off','1','0'].forEach(opt=>{
        const o = document.createElement('option'); o.value = opt; o.textContent = opt;
        if(String(d.value).toLowerCase()===opt.toLowerCase()) o.selected = true;
        sel.appendChild(o);
      });
      sel.dataset.key = d.key;
      wrap.appendChild(sel);
    } else {
      const inp = document.createElement('input');
      inp.className = 'w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500';
      inp.value = d.value || '';
      inp.placeholder = d.value || '';
      inp.dataset.key = d.key;
      wrap.appendChild(inp);
    }
    cont.appendChild(wrap);
  });

  // Render extension lines
  const exul = $('#ext-list'); exul.innerHTML = '';
  STATE.extensions.forEach(e=>{
    const li = document.createElement('li');
    li.className = 'flex items-center justify-between py-3';
    li.dataset.name = String(e.raw).toLowerCase();

    const left = document.createElement('div');
    left.className = 'flex items-center gap-2 min-w-0';
    const code = document.createElement('div');
    code.className = 'font-mono text-sm truncate pr-3';
    code.textContent = e.raw;
    left.appendChild(code);
    left.appendChild(badgeTip(e.help));
    li.appendChild(left);

    const lab = document.createElement('label'); lab.className = 'inline-flex items-center gap-3';
    const meta = document.createElement('span'); meta.className='text-xs text-slate-500'; meta.textContent = e.enabled?'Activo':'Inactivo';
    const chk = document.createElement('input'); chk.type='checkbox'; chk.className='peer hidden ext-switch';
    chk.checked = !!e.enabled; chk.dataset.index = e.index;
    const sw = document.createElement('span'); sw.className='ios-switch';
    const th = document.createElement('span'); th.className='ios-thumb'; sw.appendChild(th);
    lab.appendChild(meta); lab.appendChild(chk); lab.appendChild(sw);
    li.appendChild(lab);

    // actualiza texto Activo/Inactivo en UI
    chk.addEventListener('change', ()=> meta.textContent = chk.checked?'Activo':'Inactivo');
    exul.appendChild(li);
  });

  // Render zend_extension lines
  const zul = $('#zext-list'); zul.innerHTML = '';
  STATE.zend_exts.forEach(e=>{
    const li = document.createElement('li');
    li.className = 'flex items-center justify-between py-3';
    li.dataset.name = String(e.raw).toLowerCase();

    const left = document.createElement('div');
    left.className = 'flex items-center gap-2 min-w-0';
    const code = document.createElement('div');
    code.className = 'font-mono text-sm truncate pr-3';
    code.textContent = e.raw;
    left.appendChild(code);
    left.appendChild(badgeTip(e.help));
    li.appendChild(left);

    const lab = document.createElement('label'); lab.className = 'inline-flex items-center gap-3';
    const meta = document.createElement('span'); meta.className='text-xs text-slate-500'; meta.textContent = e.enabled?'Activo':'Inactivo';
    const chk = document.createElement('input'); chk.type='checkbox'; chk.className='peer hidden zext-switch';
    chk.checked = !!e.enabled; chk.dataset.index = e.index;
    const sw = document.createElement('span'); sw.className='ios-switch';
    const th = document.createElement('span'); th.className='ios-thumb'; sw.appendChild(th);
    lab.appendChild(meta); lab.appendChild(chk); lab.appendChild(sw);
    li.appendChild(lab);

    chk.addEventListener('change', ()=> meta.textContent = chk.checked?'Activo':'Inactivo');
    zul.appendChild(li);
  });
}

// Filtros
function bindFilter(inputId, listId){
  const q = $(inputId), list = $(listId);
  if(!q || !list) return;
  q.addEventListener('input', ()=>{
    const term = q.value.trim().toLowerCase();
    list.querySelectorAll('li').forEach(li=>{
      const n = (li.getAttribute('data-name')||'').toLowerCase();
      li.style.display = (!term || n.includes(term)) ? '' : 'none';
    });
  });
}

// Masivo (UI)
function massToggle(btnId, selector, checked){
  const btn = $(btnId);
  if(!btn) return;
  btn.addEventListener('click', ()=>{
    document.querySelectorAll(selector).forEach(chk => chk.checked = checked);
    // también actualiza texto Activo/Inactivo
    document.querySelectorAll(selector).forEach(chk => {
      const meta = chk.parentElement.querySelector('span.text-xs');
      if (meta) meta.textContent = chk.checked ? 'Activo' : 'Inactivo';
    });
  });
}

// Guardar vía AJAX
async function saveAll(){
  const kv = {};
  $$('#directives [data-key]').forEach(el => kv[el.dataset.key] = el.value);

  const ext_on  = $$('.ext-switch').filter(chk=>chk.checked).map(chk=>parseInt(chk.dataset.index,10));
  const zext_on = $$('.zext-switch').filter(chk=>chk.checked).map(chk=>parseInt(chk.dataset.index,10));
  const new_ext = $('#new-ext').value.trim();

  const res = await fetch(`?action=save${location.search.replace(/^\?/, '&')}`, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ kv, ext_on, zext_on, new_ext })
  });
  const data = await res.json();
  if(!data.ok) throw new Error(data.error || 'Error al guardar');
  showAlert(data.message || 'Guardado', true);
  // refrescar estado con respuesta
  if (data.state){ STATE = data.state; await loadState(); }
  $('#new-ext').value = '';
}

// Init
document.addEventListener('DOMContentLoaded', async ()=>{
  try {
    await loadState();
    bindFilter('#q-ext','#ext-list');
    bindFilter('#q-zext','#zext-list');
    massToggle('#ext-on', '.ext-switch',  true);
    massToggle('#ext-off','.ext-switch',  false);
    massToggle('#zext-on', '.zext-switch', true);
    massToggle('#zext-off','.zext-switch', false);
  } catch (e) {
    showAlert(e.message || String(e), false);
  }

  $('#btn-save').addEventListener('click', async (e)=>{
    e.preventDefault();
    try { await saveAll(); }
    catch(err){ showAlert(err.message || String(err), false); }
  });
  $('#btn-reload').addEventListener('click', async ()=>{
    try { await loadState(); showAlert('Estado recargado.', true); }
    catch(err){ showAlert(err.message || String(err), false); }
  });
});
</script>
</body>
</html>
