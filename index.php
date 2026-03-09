<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Control de Proyectos</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            heading: ['Space Grotesk', 'sans-serif'],
            body: ['DM Sans', 'sans-serif']
          },
          colors: {
            ink: '#10231A',
            mint: '#2A8C67',
            amberx: '#D9822B'
          },
          keyframes: {
            fadeInUp: {
              '0%': { opacity: '0', transform: 'translateY(10px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' }
            }
          },
          animation: {
            fadeInUp: 'fadeInUp 0.35s ease-out forwards'
          }
        }
      }
    };
  </script>
  <style>
    body {
      font-family: 'DM Sans', sans-serif;
      background:
        radial-gradient(circle at 10% 20%, rgba(65, 198, 153, 0.18), transparent 36%),
        radial-gradient(circle at 90% 0%, rgba(245, 174, 104, 0.2), transparent 42%),
        linear-gradient(160deg, #f6faf7 0%, #e9f2ec 45%, #f8f3ea 100%);
      min-height: 100vh;
    }
    .glass {
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(6px);
    }
    .modal-panel {
      max-height: 88vh;
      overflow: auto;
    }
    .hidden-modal {
      display: none;
    }
    #toastContainer {
      position: fixed;
      right: 1rem;
      bottom: 1rem;
      z-index: 130;
      display: flex;
      flex-direction: column;
      gap: 0.6rem;
      pointer-events: none;
      max-width: min(92vw, 360px);
    }
    .toast-item {
      pointer-events: auto;
      border-radius: 0.9rem;
      padding: 0.7rem 0.85rem;
      font-size: 0.85rem;
      box-shadow: 0 10px 28px rgba(15, 23, 42, 0.2);
      border: 1px solid transparent;
      animation: toastIn 180ms ease-out;
    }
    .toast-success {
      background: #ecfdf5;
      border-color: #a7f3d0;
      color: #065f46;
    }
    .toast-error {
      background: #fef2f2;
      border-color: #fecaca;
      color: #991b1b;
    }
    .toast-info {
      background: #eff6ff;
      border-color: #bfdbfe;
      color: #1e3a8a;
    }
    @keyframes toastIn {
      from {
        opacity: 0;
        transform: translateY(8px) scale(0.98);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }
  </style>
</head>
<body class="text-ink font-body">
  <main id="appShell" class="mx-auto hidden max-w-7xl px-4 py-6 md:px-8 md:py-10">
    <section class="glass rounded-3xl border border-white/70 p-5 shadow-xl md:p-8">
      <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Panel local de XAMPP</p>
          <h1 class="font-heading text-3xl font-bold md:text-4xl">Control de proyectos</h1>
          <p class="mt-1 text-sm text-slate-600">Carga rapida con cache inteligente y papeleria recuperable.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <span id="authModeBadge" class="rounded-xl bg-white px-3 py-2 text-xs font-semibold text-slate-600 shadow">Modo: -</span>
          <a href="/phpmyadmin/" target="_blank" class="rounded-xl bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow hover:bg-slate-50">
            phpMyAdmin
          </a>
          <button id="securitySettingsBtn" class="rounded-xl bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow hover:bg-slate-50">
            Seguridad / SMTP
          </button>
          <button id="logoutBtn" class="hidden rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow hover:opacity-95">
            Cerrar sesion
          </button>
          <button id="refreshMetricsBtn" class="rounded-xl bg-amberx px-4 py-2 text-sm font-semibold text-white shadow hover:opacity-95">
            Refrescar metricas
          </button>
          <button id="newProjectBtn" class="rounded-xl bg-mint px-4 py-2 text-sm font-semibold text-white shadow hover:opacity-95">
            Nuevo proyecto
          </button>
        </div>
      </div>

      <div class="mt-5 grid gap-3 md:grid-cols-3">
        <div class="rounded-2xl bg-white p-4 shadow">
          <p class="text-xs uppercase tracking-widest text-slate-500">Tamano total</p>
          <p class="mt-1 text-xl font-semibold" id="totalSizeLabel">-</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow">
          <p class="text-xs uppercase tracking-widest text-slate-500">Version PHP</p>
          <p class="mt-1 text-xl font-semibold" id="phpVersionBtn">-</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow">
          <p class="text-xs uppercase tracking-widest text-slate-500">Elementos en papeleria</p>
          <p class="mt-1 text-xl font-semibold" id="trashCountLabel">0</p>
        </div>
      </div>

      <div class="mt-5 grid gap-3 md:grid-cols-[1fr_auto_auto_auto]">
        <label class="flex items-center gap-2 rounded-2xl bg-white px-4 py-3 shadow">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.9 14.32a8 8 0 111.414-1.414l3.387 3.387a1 1 0 01-1.414 1.414l-3.387-3.387zM14 8a6 6 0 11-12 0 6 6 0 0112 0z" clip-rule="evenodd"/></svg>
          <input id="searchInput" type="text" placeholder="Buscar proyectos..." class="w-full border-0 bg-transparent text-sm outline-none" />
        </label>
        <select id="sortSelect" class="rounded-2xl bg-white px-4 py-3 text-sm shadow outline-none">
          <option value="name_asc">Nombre A-Z</option>
          <option value="name_desc">Nombre Z-A</option>
          <option value="size_desc">Tamano mayor</option>
          <option value="size_asc">Tamano menor</option>
          <option value="created_desc">Mas reciente</option>
          <option value="created_asc">Mas antiguo</option>
        </select>
        <button id="phpConfigBtn" class="rounded-2xl bg-white px-4 py-3 text-sm font-medium shadow hover:bg-slate-50">Config PHP</button>
        <button id="editPhpIniBtn" class="rounded-2xl bg-white px-4 py-3 text-sm font-medium shadow hover:bg-slate-50">Editar php.ini</button>
      </div>

      <div class="mt-6 flex gap-2 rounded-2xl bg-white p-2 shadow">
        <button data-tab="projects" class="tab-btn flex-1 rounded-xl bg-mint px-4 py-2 text-sm font-semibold text-white">Proyectos</button>
        <button data-tab="trash" class="tab-btn flex-1 rounded-xl px-4 py-2 text-sm font-semibold text-slate-700">Papeleria</button>
      </div>

      <section id="projectsSection" class="mt-5">
        <div id="projectsBulkBar" class="mb-3 hidden items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3">
          <p id="projectsSelectionCount" class="text-sm font-semibold text-emerald-800">0 seleccionados</p>
          <div class="flex flex-wrap gap-2">
            <button id="selectAllProjectsBtn" class="rounded-xl border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">Seleccionar visibles</button>
            <button id="clearProjectsSelectionBtn" class="rounded-xl border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">Limpiar</button>
            <button id="bulkMoveTrashBtn" class="rounded-xl bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">Enviar a papeleria</button>
          </div>
        </div>
        <div id="projectsGrid" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"></div>
      </section>

      <section id="trashSection" class="mt-5 hidden">
        <div id="trashBulkBar" class="mb-3 hidden items-center justify-between gap-3 rounded-2xl border border-slate-300 bg-white px-4 py-3">
          <p id="trashSelectionCount" class="text-sm font-semibold text-slate-700">0 seleccionados</p>
          <div class="flex flex-wrap gap-2">
            <button id="selectAllTrashBtn" class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">Seleccionar visibles</button>
            <button id="clearTrashSelectionBtn" class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">Limpiar</button>
            <button id="bulkRestoreBtn" class="rounded-xl bg-mint px-3 py-1.5 text-xs font-semibold text-white hover:opacity-95">Restaurar</button>
            <button id="bulkDeleteForeverBtn" class="rounded-xl bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:opacity-95">Borrar definitivo</button>
          </div>
        </div>
        <div id="trashGrid" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"></div>
      </section>
    </section>
  </main>

  <div id="toastContainer" aria-live="polite" aria-atomic="true"></div>

  <section id="authModal" class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-900/55 p-4">
    <div class="w-full max-w-lg rounded-3xl bg-white shadow-2xl">
      <div class="border-b px-5 py-4">
        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Seguridad del sistema</p>
        <h2 id="authTitle" class="font-heading text-2xl">Configurar acceso</h2>
      </div>

      <div id="authSetupPanel" class="px-5 py-4">
        <p class="text-sm text-slate-600">Primera vez: elige si este panel sera solo local o tambien para red.</p>
        <div class="mt-3 space-y-2">
          <label class="flex items-start gap-2 rounded-xl border border-slate-200 p-3">
            <input type="radio" name="securityMode" value="local" checked class="mt-1" />
            <span class="text-sm"><strong>Solo local</strong><br>Sin password. Se asume que nadie externo entrara.</span>
          </label>
          <label class="flex items-start gap-2 rounded-xl border border-slate-200 p-3">
            <input type="radio" name="securityMode" value="network" class="mt-1" />
            <span class="text-sm"><strong>En red</strong><br>Se exige login y recuperacion por correo.</span>
          </label>
        </div>

        <div id="networkSetupFields" class="mt-3 hidden space-y-2">
          <input id="setupUsername" type="text" placeholder="Usuario administrador" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
          <input id="setupPassword" type="password" placeholder="Contrasena" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
          <input id="setupEmail" type="email" placeholder="Correo de recuperacion" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />

          <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <p class="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Datos SMTP</p>
            <div class="grid gap-2 md:grid-cols-2">
              <input id="setupSmtpHost" type="text" placeholder="Host SMTP (ej. smtp.gmail.com)" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
              <input id="setupSmtpPort" type="number" placeholder="Puerto (587/465)" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
              <select id="setupSmtpEncryption" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint">
                <option value="tls">TLS (STARTTLS)</option>
                <option value="ssl">SSL</option>
                <option value="none">Sin cifrado</option>
              </select>
              <input id="setupSmtpUser" type="text" placeholder="Usuario SMTP" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
              <input id="setupSmtpPass" type="password" placeholder="Password SMTP" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint md:col-span-2" />
              <input id="setupSmtpFromEmail" type="email" placeholder="Correo remitente" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
              <input id="setupSmtpFromName" type="text" placeholder="Nombre remitente" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
            </div>
          </div>
        </div>

        <button id="setupSubmitBtn" class="mt-4 w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Guardar configuracion</button>
      </div>

      <div id="authLoginPanel" class="hidden px-5 py-4">
        <p class="text-sm text-slate-600">Este panel esta protegido. Inicia sesion para continuar.</p>
        <div class="mt-3 space-y-2">
          <input id="loginUsername" type="text" placeholder="Usuario" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
          <input id="loginPassword" type="password" placeholder="Contrasena" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
        </div>
        <button id="loginSubmitBtn" class="mt-4 w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Entrar</button>
        <button id="goResetPanelBtn" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-2 text-sm">Olvide mi contrasena</button>
      </div>

      <div id="authResetPanel" class="hidden px-5 py-4">
        <p class="text-sm text-slate-600">Recupera acceso con codigo enviado al correo configurado.</p>
        <div class="mt-3 space-y-2">
          <input id="resetUsername" type="text" placeholder="Usuario" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
          <input id="resetEmail" type="email" placeholder="Correo de recuperacion" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
          <button id="sendResetCodeBtn" class="w-full rounded-xl border border-slate-200 px-4 py-2 text-sm">Enviar codigo por correo</button>
          <input id="resetCode" type="text" placeholder="Codigo recibido" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
          <input id="resetNewPassword" type="password" placeholder="Nueva contrasena" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
        </div>
        <button id="resetSubmitBtn" class="mt-4 w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Cambiar contrasena</button>
        <button id="backToLoginBtn" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-2 text-sm">Volver al login</button>
      </div>
    </div>
  </section>

  <section id="smtpModal" class="hidden-modal fixed inset-0 z-50 items-center justify-center p-4">
    <div class="modal-panel w-full max-w-2xl rounded-3xl bg-white shadow-2xl">
      <div class="flex items-center justify-between border-b px-5 py-4">
        <h3 class="font-heading text-xl">Configuracion SMTP</h3>
        <button data-close="smtpModal" class="rounded-lg px-3 py-1 text-slate-500 hover:bg-slate-100">Cerrar</button>
      </div>
      <div class="grid gap-2 p-5 md:grid-cols-2">
        <input id="smtpHost" type="text" placeholder="Host SMTP" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
        <input id="smtpPort" type="number" placeholder="Puerto" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
        <select id="smtpEncryption" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint">
          <option value="tls">TLS (STARTTLS)</option>
          <option value="ssl">SSL</option>
          <option value="none">Sin cifrado</option>
        </select>
        <input id="smtpUser" type="text" placeholder="Usuario SMTP" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
        <input id="smtpPass" type="password" placeholder="Password SMTP (dejar vacio para no cambiar)" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint md:col-span-2" />
        <input id="smtpFromEmail" type="email" placeholder="Correo remitente" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
        <input id="smtpFromName" type="text" placeholder="Nombre remitente" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
      </div>
      <div class="flex justify-end gap-2 border-t bg-slate-50 px-5 py-3">
        <button id="saveSmtpBtn" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Guardar SMTP</button>
      </div>
    </div>
  </section>

  <div id="overlay" class="hidden-modal fixed inset-0 z-40 bg-slate-900/45"></div>

  <section id="dialogModal" class="hidden-modal fixed inset-0 z-[120] items-center justify-center p-4">
    <div class="w-full max-w-md rounded-3xl bg-white shadow-2xl">
      <div class="border-b px-5 py-4">
        <h3 id="dialogTitle" class="font-heading text-xl">Aviso</h3>
      </div>
      <div class="px-5 py-4">
        <p id="dialogMessage" class="whitespace-pre-line text-sm text-slate-700"></p>
        <div id="dialogInputWrap" class="mt-3 hidden">
          <input id="dialogInput" type="text" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
        </div>
      </div>
      <div class="flex justify-end gap-2 border-t bg-slate-50 px-5 py-3">
        <button id="dialogCancelBtn" class="rounded-xl border border-slate-200 px-4 py-2 text-sm">Cancelar</button>
        <button id="dialogConfirmBtn" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Aceptar</button>
      </div>
    </div>
  </section>

  <section id="folderModal" class="hidden-modal fixed inset-0 z-50 items-center justify-center p-4">
    <div class="modal-panel w-full max-w-4xl rounded-3xl bg-white shadow-2xl">
      <div class="flex items-center justify-between border-b px-5 py-4">
        <h3 class="font-heading text-xl" id="folderModalLabel">Proyecto</h3>
        <button data-close="folderModal" class="rounded-lg px-3 py-1 text-slate-500 hover:bg-slate-100">Cerrar</button>
      </div>
      <div class="px-5 py-4">
        <div class="mb-3 flex gap-2">
          <button id="filesTabBtn" class="rounded-lg bg-slate-900 px-3 py-1.5 text-sm font-medium text-white">Archivos</button>
          <button id="readmeTabBtn" class="rounded-lg px-3 py-1.5 text-sm font-medium text-slate-700">README</button>
        </div>
        <div id="filesPane"></div>
        <div id="readmePane" class="hidden prose max-w-none"></div>
      </div>
    </div>
  </section>

  <section id="passwordsModal" class="hidden-modal fixed inset-0 z-50 items-center justify-center p-4">
    <div class="modal-panel w-full max-w-5xl rounded-3xl bg-white shadow-2xl">
      <div class="flex items-center justify-between border-b px-5 py-4">
        <h3 class="font-heading text-xl" id="passwordsModalLabel">Contrasenas del proyecto</h3>
        <button data-close="passwordsModal" class="rounded-lg px-3 py-1 text-slate-500 hover:bg-slate-100">Cerrar</button>
      </div>
      <div class="grid gap-4 p-5 md:grid-cols-[320px_1fr]">
        <aside class="rounded-2xl bg-slate-50 p-4">
          <h4 class="font-heading text-lg">Agregar acceso</h4>
          <input id="newUserName" type="text" placeholder="Nombre" class="mt-3 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
          <input id="newPassValue" type="text" placeholder="Contrasena" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-mint" />
          <button id="addPasswordBtn" class="mt-3 w-full rounded-xl bg-mint px-3 py-2 text-sm font-semibold text-white">Guardar</button>
        </aside>
        <div id="passwordsModalBody" class="rounded-2xl border border-slate-200 p-4"></div>
      </div>
    </div>
  </section>

  <section id="phpIniModal" class="hidden-modal fixed inset-0 z-50 items-center justify-center p-4">
    <div class="modal-panel w-full max-w-6xl rounded-3xl bg-white shadow-2xl">
      <div class="flex items-center justify-between border-b px-5 py-4">
        <h3 class="font-heading text-xl">Editar php.ini</h3>
        <button data-close="phpIniModal" class="rounded-lg px-3 py-1 text-slate-500 hover:bg-slate-100">Cerrar</button>
      </div>
      <div class="p-5">
        <textarea id="phpIniEditor" class="h-[60vh] w-full rounded-2xl border border-slate-200 p-4 font-mono text-sm outline-none focus:border-mint"></textarea>
        <div class="mt-3 flex justify-end gap-2">
          <button data-close="phpIniModal" class="rounded-xl border border-slate-200 px-4 py-2 text-sm">Cancelar</button>
          <button id="savePhpIniBtn" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Guardar cambios</button>
        </div>
      </div>
    </div>
  </section>

  <section id="phpConfigModal" class="hidden-modal fixed inset-0 z-50 items-center justify-center p-4">
    <div class="modal-panel w-full max-w-5xl rounded-3xl bg-white shadow-2xl">
      <div class="flex items-center justify-between border-b px-5 py-4">
        <h3 class="font-heading text-xl">Configuracion de PHP</h3>
        <button data-close="phpConfigModal" class="rounded-lg px-3 py-1 text-slate-500 hover:bg-slate-100">Cerrar</button>
      </div>
      <div class="p-5">
        <div id="phpDropdownMenu" class="grid gap-4 md:grid-cols-2"></div>
      </div>
    </div>
  </section>

  <script src="app.js"></script>
</body>
</html>
