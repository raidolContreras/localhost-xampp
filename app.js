const API_URL = "api.php";

const state = {
  projects: [],
  trash: [],
  totalSizeBytes: 0,
  phpVersion: "-",
  ini: {},
  extensions: [],
  currentTab: "projects",
  currentFolderView: { folder: "", fromTrash: false },
  currentPasswordsFolder: "",
  csrfToken: "",
  authMode: "local",
  requiresAuth: false,
  authenticated: false,
  selectedProjects: [],
  selectedTrash: [],
};

const sortKey = "folderSortPreference";
let extTooltipEl = null;
const dialogState = {
  resolver: null,
  type: "alert",
};

const EXTENSION_INFO = {
  core: "Nucleo base de PHP. Siempre esta presente.",
  ctype: "Funciones para validar tipos de caracteres (alfanumerico, digitos, etc.).",
  curl: "Cliente HTTP/FTP para consumir APIs y servicios externos.",
  date: "Manejo de fechas, zonas horarias y formatos de tiempo.",
  dom: "Manipulacion de documentos XML/HTML con modelo DOM.",
  exif: "Lectura de metadatos EXIF de imagenes (camara, orientacion, etc.).",
  fileinfo: "Deteccion de tipo MIME y propiedades de archivos.",
  filter: "Filtros para sanitizar y validar datos de entrada.",
  ftp: "Funciones para conexion y transferencia por FTP.",
  gd: "Procesamiento de imagenes: redimensionar, recortar, crear miniaturas.",
  gettext: "Soporte de internacionalizacion y traducciones (i18n).",
  hash: "Algoritmos hash como sha256, sha512, etc.",
  iconv: "Conversion entre codificaciones de caracteres.",
  json: "Codificacion y decodificacion de datos JSON.",
  libxml: "Base para procesamiento XML usado por varias extensiones.",
  mbstring: "Funciones de texto multibyte (UTF-8 y otros encodings).",
  mysqli: "Driver MySQL mejorado para consultas a MariaDB/MySQL.",
  mysqlnd: "Driver nativo de MySQL para PHP.",
  openssl: "Cifrado, certificados y conexiones seguras TLS/SSL.",
  pdo: "Capa de acceso a bases de datos orientada a objetos.",
  pdo_mysql: "Controlador PDO para MySQL/MariaDB.",
  pdo_sqlite: "Controlador PDO para SQLite.",
  session: "Gestion de sesiones de usuario en servidor.",
  simplexml: "Lectura y escritura simple de XML.",
  sodium: "Criptografia moderna de alto nivel.",
  spl: "Estructuras de datos y utilidades estandar de PHP.",
  sqlite3: "Extension nativa para bases de datos SQLite.",
  tokenizer: "Tokenizacion de codigo PHP (analisis lexicografico).",
  xml: "Soporte base de parsing XML.",
  xmlreader: "Lector XML en streaming, eficiente en memoria.",
  xmlwriter: "Escritura de XML de forma programatica.",
  zip: "Compresion y descompresion de archivos ZIP.",
  zlib: "Compresion de datos y soporte gzip/deflate.",
};

document.addEventListener("DOMContentLoaded", () => {
  bindBaseEvents();
  bootstrapAuth();
});

function bindBaseEvents() {
  const searchInput = document.getElementById("searchInput");
  const sortSelect = document.getElementById("sortSelect");

  searchInput.addEventListener("input", () => renderActiveGrid());

  const savedSort = localStorage.getItem(sortKey) || "name_asc";
  sortSelect.value = savedSort;
  sortSelect.addEventListener("change", () => {
    localStorage.setItem(sortKey, sortSelect.value);
    renderActiveGrid();
  });

  document.getElementById("newProjectBtn").addEventListener("click", onCreateProject);
  document.getElementById("refreshMetricsBtn").addEventListener("click", onRefreshMetrics);
  document.getElementById("editPhpIniBtn").addEventListener("click", onOpenPhpIni);
  document.getElementById("savePhpIniBtn").addEventListener("click", onSavePhpIni);
  document.getElementById("phpConfigBtn").addEventListener("click", () => openModal("phpConfigModal"));
  document.getElementById("securitySettingsBtn").addEventListener("click", onOpenSmtpSettings);
  document.getElementById("logoutBtn").addEventListener("click", onLogout);
  document.getElementById("saveSmtpBtn").addEventListener("click", onSaveSmtpSettings);
  document.getElementById("selectAllProjectsBtn").addEventListener("click", () => selectVisibleItems("projects"));
  document.getElementById("clearProjectsSelectionBtn").addEventListener("click", () => clearSelection("projects"));
  document.getElementById("bulkMoveTrashBtn").addEventListener("click", onBulkMoveToTrash);
  document.getElementById("selectAllTrashBtn").addEventListener("click", () => selectVisibleItems("trash"));
  document.getElementById("clearTrashSelectionBtn").addEventListener("click", () => clearSelection("trash"));
  document.getElementById("bulkRestoreBtn").addEventListener("click", onBulkRestore);
  document.getElementById("bulkDeleteForeverBtn").addEventListener("click", onBulkDeleteForever);

  document.querySelectorAll(".tab-btn").forEach((btn) => {
    btn.addEventListener("click", () => switchTab(btn.dataset.tab || "projects"));
  });

  document.querySelectorAll("[data-close]").forEach((btn) => {
    btn.addEventListener("click", () => closeModal(btn.dataset.close));
  });

  document.getElementById("overlay").addEventListener("click", () => closeAllModals());
  bindDialogEvents();

  ["folderModal", "passwordsModal", "phpIniModal", "phpConfigModal", "smtpModal"].forEach((id) => {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.addEventListener("click", (event) => {
      if (event.target === modal) {
        closeModal(id);
      }
    });
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeAllModals();
    }
  });

  const filesTabBtn = document.getElementById("filesTabBtn");
  const readmeTabBtn = document.getElementById("readmeTabBtn");
  filesTabBtn.addEventListener("click", () => toggleFolderTabs("files"));
  readmeTabBtn.addEventListener("click", () => toggleFolderTabs("readme"));

  bindAuthEvents();
}

function bindAuthEvents() {
  document.querySelectorAll('input[name="securityMode"]').forEach((radio) => {
    radio.addEventListener("change", syncSetupModeFields);
  });

  const setupBtn = document.getElementById("setupSubmitBtn");
  const loginBtn = document.getElementById("loginSubmitBtn");
  const goResetBtn = document.getElementById("goResetPanelBtn");
  const backLoginBtn = document.getElementById("backToLoginBtn");
  const sendCodeBtn = document.getElementById("sendResetCodeBtn");
  const resetBtn = document.getElementById("resetSubmitBtn");

  setupBtn?.addEventListener("click", onAuthSetup);
  loginBtn?.addEventListener("click", onAuthLogin);
  goResetBtn?.addEventListener("click", () => showAuthPanel("reset"));
  backLoginBtn?.addEventListener("click", () => showAuthPanel("login"));
  sendCodeBtn?.addEventListener("click", onSendResetCode);
  resetBtn?.addEventListener("click", onResetPassword);

  syncSetupModeFields();
}

async function bootstrapAuth() {
  try {
    const status = await apiGet("auth_status", { skipAuthRedirect: true });
    if (!status.success) throw new Error(status.message || "No se pudo validar seguridad");

    state.authMode = status.mode || "local";
    state.requiresAuth = !!status.require_auth;
    state.authenticated = !!status.authenticated;
    state.csrfToken = status.csrf_token || "";
    renderAuthUi();

    if (status.needs_setup) {
      showAuthPanel("setup");
      return;
    }

    if (status.require_auth) {
      showAuthPanel("login");
      return;
    }

    hideAuthModal();
    await init();
  } catch (err) {
    await uiAlert(`Error de seguridad: ${err?.message || String(err)}`, "Seguridad");
    showAuthPanel("login");
  }
}

async function init(force = false) {
  const projectsGrid = document.getElementById("projectsGrid");
  const trashGrid = document.getElementById("trashGrid");
  projectsGrid.innerHTML = loaderHtml("Cargando proyectos...");
  trashGrid.innerHTML = loaderHtml("Cargando papeleria...");

  try {
    const data = await apiGet(`init${force ? "&force=1" : ""}`);
    if (!data.success) throw new Error(data.message || "No se pudo cargar");

    state.projects = data.projects || [];
    state.trash = data.trash || [];
    state.totalSizeBytes = Number(data.total_size_bytes || 0);
    state.phpVersion = data.php_version || "-";
    state.ini = data.ini || {};
    state.extensions = data.extensions || [];
    pruneSelections();

    renderHeader();
    renderPhpConfig();
    renderActiveGrid();
  } catch (err) {
    const msg = err?.message || String(err);
    projectsGrid.innerHTML = errorHtml(msg);
    trashGrid.innerHTML = errorHtml(msg);
  }
}

function showAuthPanel(panel) {
  const authModal = document.getElementById("authModal");
  const appShell = document.getElementById("appShell");
  const title = document.getElementById("authTitle");
  const setup = document.getElementById("authSetupPanel");
  const login = document.getElementById("authLoginPanel");
  const reset = document.getElementById("authResetPanel");

  appShell.classList.add("hidden");
  authModal.style.display = "flex";

  setup.classList.toggle("hidden", panel !== "setup");
  login.classList.toggle("hidden", panel !== "login");
  reset.classList.toggle("hidden", panel !== "reset");

  if (panel === "setup") title.textContent = "Configurar acceso";
  if (panel === "login") title.textContent = "Iniciar sesion";
  if (panel === "reset") title.textContent = "Recuperar contrasena";
}

function renderAuthUi() {
  const badge = document.getElementById("authModeBadge");
  const logoutBtn = document.getElementById("logoutBtn");
  const label = state.authMode === "network" ? "Modo: Red" : "Modo: Local";
  badge.textContent = label;

  if (state.authMode === "network") {
    logoutBtn.classList.remove("hidden");
  } else {
    logoutBtn.classList.add("hidden");
  }
}

function hideAuthModal() {
  const authModal = document.getElementById("authModal");
  const appShell = document.getElementById("appShell");

  authModal.style.display = "none";
  appShell.classList.remove("hidden");
}

function syncSetupModeFields() {
  const selected = document.querySelector('input[name="securityMode"]:checked')?.value || "local";
  const networkFields = document.getElementById("networkSetupFields");
  networkFields.classList.toggle("hidden", selected !== "network");
}

async function onAuthSetup() {
  try {
    const mode = document.querySelector('input[name="securityMode"]:checked')?.value || "local";
    const payload = { action: "auth_setup", mode };

    if (mode === "network") {
      const username = (document.getElementById("setupUsername").value || "").trim();
      const password = document.getElementById("setupPassword").value || "";
      const email = (document.getElementById("setupEmail").value || "").trim();

      if (!username || !password || !email) {
        await uiAlert("Para modo red debes capturar usuario, contrasena y correo.", "Configurar seguridad");
        return;
      }

      payload.username = username;
      payload.password = password;
      payload.recovery_email = email;

      const smtpHost = (document.getElementById("setupSmtpHost").value || "").trim();
      const smtpPort = Number(document.getElementById("setupSmtpPort").value || 0);
      const smtpEncryption = document.getElementById("setupSmtpEncryption").value || "tls";
      const smtpUser = (document.getElementById("setupSmtpUser").value || "").trim();
      const smtpPass = document.getElementById("setupSmtpPass").value || "";
      const smtpFromEmail = (document.getElementById("setupSmtpFromEmail").value || "").trim();
      const smtpFromName = (document.getElementById("setupSmtpFromName").value || "").trim();

      if (!smtpHost || !smtpPort || !smtpUser || !smtpPass || !smtpFromEmail) {
        await uiAlert("Para modo red tambien debes capturar toda la configuracion SMTP.", "Configurar seguridad");
        return;
      }

      payload.smtp = {
        host: smtpHost,
        port: smtpPort,
        encryption: smtpEncryption,
        user: smtpUser,
        pass: smtpPass,
        from_email: smtpFromEmail,
        from_name: smtpFromName || "Dashboard XAMPP",
      };
    }

    const res = await apiPost(payload, { skipAuthRedirect: true, skipCsrf: true });
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "No se pudo guardar la configuracion"}`, "Seguridad");
      return;
    }

    state.authMode = mode;
    state.authenticated = true;
    state.requiresAuth = false;
    state.csrfToken = res.csrf_token || "";
    renderAuthUi();
    hideAuthModal();
    await init();
  } catch (err) {
    await uiAlert(`Error: ${err?.message || String(err)}`, "Seguridad");
  }
}

async function onLogout() {
  const ok = await uiConfirm("Cerrar sesion ahora?", "Cerrar sesion");
  if (!ok) return;

  const res = await apiPost({ action: "auth_logout" }, { skipAuthRedirect: true, skipCsrf: true });
  if (!res.success) {
    await uiAlert(`Error: ${res.message || "No se pudo cerrar sesion"}`, "Seguridad");
    return;
  }

  state.csrfToken = "";
  await bootstrapAuth();
}

async function onOpenSmtpSettings() {
  try {
    const res = await apiGet("auth_get_security");
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "No se pudo cargar seguridad"}`, "Seguridad");
      return;
    }

    const smtp = res.smtp || {};
    document.getElementById("smtpHost").value = smtp.host || "";
    document.getElementById("smtpPort").value = smtp.port || "";
    document.getElementById("smtpEncryption").value = smtp.encryption || "tls";
    document.getElementById("smtpUser").value = smtp.user || "";
    document.getElementById("smtpPass").value = "";
    document.getElementById("smtpFromEmail").value = smtp.from_email || "";
    document.getElementById("smtpFromName").value = smtp.from_name || "";

    openModal("smtpModal");
  } catch (err) {
    await uiAlert(`Error: ${err?.message || String(err)}`, "Seguridad");
  }
}

async function onSaveSmtpSettings() {
  const smtp = {
    host: (document.getElementById("smtpHost").value || "").trim(),
    port: Number(document.getElementById("smtpPort").value || 0),
    encryption: document.getElementById("smtpEncryption").value || "tls",
    user: (document.getElementById("smtpUser").value || "").trim(),
    pass: document.getElementById("smtpPass").value || "",
    from_email: (document.getElementById("smtpFromEmail").value || "").trim(),
    from_name: (document.getElementById("smtpFromName").value || "").trim(),
  };

  if (!smtp.host || !smtp.port || !smtp.user || !smtp.from_email) {
    await uiAlert("Completa host, puerto, usuario y correo remitente.", "SMTP");
    return;
  }

  const res = await apiPost({ action: "auth_update_smtp", smtp });
  if (!res.success) {
    await uiAlert(`Error: ${res.message || "No se pudo guardar SMTP"}`, "SMTP");
    return;
  }

  await uiAlert("Configuracion SMTP guardada.", "SMTP");
  closeModal("smtpModal");
}

async function onAuthLogin() {
  try {
    const username = (document.getElementById("loginUsername").value || "").trim();
    const password = document.getElementById("loginPassword").value || "";

    if (!username || !password) {
      await uiAlert("Captura usuario y contrasena.", "Iniciar sesion");
      return;
    }

    const res = await apiPost({ action: "auth_login", username, password }, { skipAuthRedirect: true, skipCsrf: true });
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "Credenciales invalidas"}`, "Iniciar sesion");
      return;
    }

    state.authMode = "network";
    state.authenticated = true;
    state.requiresAuth = false;
    state.csrfToken = res.csrf_token || "";
    renderAuthUi();
    hideAuthModal();
    await init();
  } catch (err) {
    await uiAlert(`Error: ${err?.message || String(err)}`, "Iniciar sesion");
  }
}

async function onSendResetCode() {
  const username = (document.getElementById("resetUsername").value || "").trim();
  const email = (document.getElementById("resetEmail").value || "").trim();

  if (!username || !email) {
    await uiAlert("Captura usuario y correo.", "Recuperacion");
    return;
  }

  const res = await apiPost({ action: "auth_request_reset", username, email }, { skipAuthRedirect: true, skipCsrf: true });
  if (!res.success) {
    await uiAlert(`Error: ${res.message || "No se pudo enviar el codigo"}`, "Recuperacion");
    return;
  }

  await uiAlert("Se envio un codigo de recuperacion a tu correo.", "Recuperacion");
}

async function onResetPassword() {
  const username = (document.getElementById("resetUsername").value || "").trim();
  const code = (document.getElementById("resetCode").value || "").trim();
  const newPassword = document.getElementById("resetNewPassword").value || "";

  if (!username || !code || !newPassword) {
    await uiAlert("Completa usuario, codigo y nueva contrasena.", "Recuperacion");
    return;
  }

  const res = await apiPost(
    { action: "auth_reset_password", username, code, new_password: newPassword },
    { skipAuthRedirect: true, skipCsrf: true }
  );

  if (!res.success) {
    await uiAlert(`Error: ${res.message || "No se pudo cambiar la contrasena"}`, "Recuperacion");
    return;
  }

  await uiAlert("Contrasena actualizada. Ahora inicia sesion.", "Recuperacion");
  showAuthPanel("login");
}

function renderHeader() {
  document.getElementById("totalSizeLabel").textContent = humanBytes(state.totalSizeBytes);
  document.getElementById("phpVersionBtn").textContent = state.phpVersion;
  document.getElementById("trashCountLabel").textContent = String((state.trash || []).length);
}

function renderPhpConfig() {
  const phpContainer = document.getElementById("phpDropdownMenu");
  const iniEntries = Object.entries(state.ini || {}).sort((a, b) => a[0].localeCompare(b[0]));

  const iniHtml = iniEntries
    .map(([key, value]) => `
      <div class="rounded-lg bg-slate-50 p-3 text-sm">
        <p class="font-semibold text-slate-700">${escapeHtml(key)}</p>
        <p class="mt-1 text-slate-600 break-all">${escapeHtml(String(value))}</p>
      </div>
    `)
    .join("");

  const extHtml = (state.extensions || [])
    .map((ext) => {
      const info = extensionDescription(ext);
      return `
        <button
          type="button"
          class="inline-flex cursor-help rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-800 transition hover:bg-emerald-100 focus:outline-none focus:ring-2 focus:ring-emerald-300"
          data-ext-tooltip="${escapeAttr(info)}"
          data-ext-name="${escapeAttr(ext)}"
          aria-label="${escapeAttr(`${ext}: ${info}`)}"
        >${escapeHtml(ext)}</button>
      `;
    })
    .join("");

  phpContainer.innerHTML = `
    <div class="rounded-2xl border border-slate-200 p-4 md:col-span-2">
      <p class="text-sm font-semibold text-slate-800">Directivas de PHP</p>
      <div class="mt-3 grid max-h-[320px] gap-2 overflow-auto pr-1 md:grid-cols-2">${iniHtml}</div>
    </div>
    <div class="rounded-2xl border border-slate-200 p-4 md:col-span-2">
      <p class="text-sm font-semibold text-slate-800">Extensiones cargadas</p>
      <p class="mt-1 text-xs text-slate-500">Pasa el mouse sobre una extension para ver que hace.</p>
      <div class="mt-3 flex max-h-[220px] flex-wrap gap-2 overflow-auto pr-1">${extHtml}</div>
    </div>
  `;

  bindExtensionTooltips();
}

function extensionDescription(name) {
  const key = String(name || "").toLowerCase();
  return EXTENSION_INFO[key] || "Extension de PHP disponible en tu entorno local.";
}

function ensureExtTooltip() {
  if (extTooltipEl) return extTooltipEl;

  const el = document.createElement("div");
  el.id = "extTooltip";
  el.style.position = "fixed";
  el.style.zIndex = "120";
  el.style.maxWidth = "320px";
  el.style.padding = "8px 10px";
  el.style.borderRadius = "10px";
  el.style.border = "1px solid #cbd5e1";
  el.style.background = "#0f172a";
  el.style.color = "#f8fafc";
  el.style.fontSize = "12px";
  el.style.lineHeight = "1.35";
  el.style.boxShadow = "0 10px 28px rgba(15, 23, 42, 0.35)";
  el.style.pointerEvents = "none";
  el.style.opacity = "0";
  el.style.transform = "translateY(4px)";
  el.style.transition = "opacity 120ms ease, transform 120ms ease";

  document.body.appendChild(el);
  extTooltipEl = el;
  return el;
}

function showExtTooltip(text, clientX, clientY) {
  const el = ensureExtTooltip();
  el.textContent = text;
  el.style.opacity = "1";
  el.style.transform = "translateY(0)";
  positionExtTooltip(clientX, clientY);
}

function hideExtTooltip() {
  if (!extTooltipEl) return;
  extTooltipEl.style.opacity = "0";
  extTooltipEl.style.transform = "translateY(4px)";
}

function positionExtTooltip(clientX, clientY) {
  if (!extTooltipEl) return;

  const pad = 12;
  const gap = 14;
  const rect = extTooltipEl.getBoundingClientRect();
  const vw = window.innerWidth;
  const vh = window.innerHeight;

  let left = clientX + gap;
  let top = clientY + gap;

  if (left + rect.width + pad > vw) {
    left = Math.max(pad, clientX - rect.width - gap);
  }

  if (top + rect.height + pad > vh) {
    top = Math.max(pad, clientY - rect.height - gap);
  }

  extTooltipEl.style.left = `${left}px`;
  extTooltipEl.style.top = `${top}px`;
}

function bindExtensionTooltips() {
  const chips = document.querySelectorAll("[data-ext-tooltip]");

  chips.forEach((chip) => {
    chip.addEventListener("mouseenter", (event) => {
      const text = chip.getAttribute("data-ext-tooltip") || "";
      showExtTooltip(text, event.clientX, event.clientY);
    });

    chip.addEventListener("mousemove", (event) => {
      positionExtTooltip(event.clientX, event.clientY);
    });

    chip.addEventListener("mouseleave", () => {
      hideExtTooltip();
    });

    chip.addEventListener("focus", () => {
      const text = chip.getAttribute("data-ext-tooltip") || "";
      const rect = chip.getBoundingClientRect();
      showExtTooltip(text, rect.left + rect.width / 2, rect.top);
    });

    chip.addEventListener("blur", () => {
      hideExtTooltip();
    });
  });
}

function switchTab(tab) {
  state.currentTab = tab === "trash" ? "trash" : "projects";

  const projectsSection = document.getElementById("projectsSection");
  const trashSection = document.getElementById("trashSection");

  if (state.currentTab === "projects") {
    projectsSection.classList.remove("hidden");
    trashSection.classList.add("hidden");
  } else {
    projectsSection.classList.add("hidden");
    trashSection.classList.remove("hidden");
  }

  document.querySelectorAll(".tab-btn").forEach((btn) => {
    const active = btn.dataset.tab === state.currentTab;
    btn.classList.toggle("bg-mint", active);
    btn.classList.toggle("text-white", active);
    btn.classList.toggle("text-slate-700", !active);
  });

  syncBulkBars();
  renderActiveGrid();
}

function renderActiveGrid() {
  if (state.currentTab === "projects") {
    renderProjects();
  } else {
    renderTrash();
  }
}

function renderProjects() {
  const grid = document.getElementById("projectsGrid");
  const filtered = filterAndSort(state.projects);

  if (filtered.length === 0) {
    syncBulkBars();
    grid.innerHTML = emptyStateHtml("No hay proyectos", "Crea uno nuevo o ajusta la busqueda.");
    return;
  }

  grid.innerHTML = filtered.map((p, i) => projectCardHtml(p, i)).join("");

  grid.querySelectorAll("[data-select-project]").forEach((input) => {
    input.addEventListener("change", () => {
      toggleSelection("projects", input.dataset.selectProject || "", input.checked);
    });
  });

  grid.querySelectorAll("[data-open-folder]").forEach((btn) => {
    btn.addEventListener("click", () => openFolderDetails(btn.dataset.openFolder || "", false));
  });

  grid.querySelectorAll("[data-open-passwords]").forEach((btn) => {
    btn.addEventListener("click", () => openPasswordsModal(btn.dataset.openPasswords || ""));
  });

  grid.querySelectorAll("[data-move-trash]").forEach((btn) => {
    btn.addEventListener("click", () => moveToTrash(btn.dataset.moveTrash || ""));
  });

  grid.querySelectorAll("[data-open-vscode]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const path = btn.dataset.openVscode || "";
      if (path) window.open(`vscode://file/${encodeURI(path)}`, "_blank");
    });
  });

  syncBulkBars();
}

function renderTrash() {
  const grid = document.getElementById("trashGrid");
  const filtered = filterAndSort(state.trash);

  if (filtered.length === 0) {
    syncBulkBars();
    grid.innerHTML = emptyStateHtml("Papeleria vacia", "Los proyectos eliminados apareceran aqui.");
    return;
  }

  grid.innerHTML = filtered.map((p, i) => trashCardHtml(p, i)).join("");

  grid.querySelectorAll("[data-select-trash]").forEach((input) => {
    input.addEventListener("change", () => {
      toggleSelection("trash", input.dataset.selectTrash || "", input.checked);
    });
  });

  grid.querySelectorAll("[data-open-trash-folder]").forEach((btn) => {
    btn.addEventListener("click", () => openFolderDetails(btn.dataset.openTrashFolder || "", true));
  });

  grid.querySelectorAll("[data-restore]").forEach((btn) => {
    btn.addEventListener("click", () => restoreProject(btn.dataset.restore || ""));
  });

  grid.querySelectorAll("[data-delete-forever]").forEach((btn) => {
    btn.addEventListener("click", () => deletePermanently(btn.dataset.deleteForever || ""));
  });

  syncBulkBars();
}

function filterAndSort(arr) {
  const term = (document.getElementById("searchInput").value || "").toLowerCase().trim();
  const sortType = document.getElementById("sortSelect").value || "name_asc";
  const [field, order] = sortType.split("_");

  const filtered = (arr || []).filter((item) => item.name.toLowerCase().includes(term));

  filtered.sort((a, b) => {
    if (field === "size") return order === "asc" ? a.size_bytes - b.size_bytes : b.size_bytes - a.size_bytes;
    if (field === "created") return order === "asc" ? a.created - b.created : b.created - a.created;
    return order === "asc" ? a.name.localeCompare(b.name) : b.name.localeCompare(a.name);
  });

  return filtered;
}

function projectCardHtml(p, index) {
  const total = Number(state.totalSizeBytes || 0);
  const percent = total > 0 ? (Number(p.size_bytes || 0) / total) * 100 : 0;
  const cacheTag = p.cached ? "Desde cache" : "Recalculado";

  return `
    <article class="animate-fadeInUp rounded-2xl bg-white p-4 shadow ${isSelected("projects", p.name) ? "ring-2 ring-emerald-300" : ""}" style="animation-delay:${Math.min(index * 0.03, 0.35)}s">
      <div class="flex items-start justify-between gap-3">
        <div>
          <h3 class="font-heading text-lg">${escapeHtml(p.name)}</h3>
          <p class="text-xs text-slate-500">${escapeHtml(formatDate(p.created))}</p>
        </div>
        <div class="flex items-center gap-2">
          <input type="checkbox" data-select-project="${escapeAttr(p.name)}" class="h-4 w-4 rounded border-slate-300 text-mint focus:ring-mint" ${isSelected("projects", p.name) ? "checked" : ""} />
          <span class="rounded-full px-2 py-1 text-[11px] font-medium ${p.cached ? "bg-emerald-100 text-emerald-700" : "bg-amber-100 text-amber-700"}">${cacheTag}</span>
        </div>
      </div>

      <p class="mt-3 text-sm text-slate-700">${escapeHtml(p.size_human)} · ${Number(p.files_count || 0)} archivos</p>
      <div class="mt-2 h-2 w-full overflow-hidden rounded bg-slate-100">
        <div class="h-full bg-mint" style="width:${percent.toFixed(2)}%"></div>
      </div>

      <div class="mt-4 grid grid-cols-2 gap-2">
        <button data-open-folder="${escapeAttr(p.name)}" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold hover:bg-slate-50">Ver contenido</button>
        <button data-open-vscode="${escapeAttr(p.path || "")}" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold hover:bg-slate-50">Abrir VS Code</button>
        <button data-open-passwords="${escapeAttr(p.name)}" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold hover:bg-slate-50">Contrasenas</button>
        <button data-move-trash="${escapeAttr(p.name)}" class="rounded-xl bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-700">Enviar a papeleria</button>
      </div>
    </article>
  `;
}

function trashCardHtml(p, index) {
  return `
    <article class="animate-fadeInUp rounded-2xl bg-white p-4 shadow ${isSelected("trash", p.name) ? "ring-2 ring-slate-300" : ""}" style="animation-delay:${Math.min(index * 0.03, 0.35)}s">
      <div class="flex items-start justify-between gap-3">
        <h3 class="font-heading text-lg">${escapeHtml(p.name)}</h3>
        <input type="checkbox" data-select-trash="${escapeAttr(p.name)}" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-700 focus:ring-slate-500" ${isSelected("trash", p.name) ? "checked" : ""} />
      </div>
      <p class="mt-1 text-xs text-slate-500">${escapeHtml(p.size_human)} · ${Number(p.files_count || 0)} archivos</p>
      <p class="mt-1 text-xs text-slate-500">${escapeHtml(formatDate(p.created))}</p>

      <div class="mt-4 grid grid-cols-1 gap-2">
        <button data-open-trash-folder="${escapeAttr(p.name)}" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold hover:bg-slate-50">Ver contenido</button>
        <button data-restore="${escapeAttr(p.name)}" class="rounded-xl bg-mint px-3 py-2 text-xs font-semibold text-white hover:opacity-95">Restaurar</button>
        <button data-delete-forever="${escapeAttr(p.name)}" class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:opacity-95">Borrar definitivamente</button>
      </div>
    </article>
  `;
}

async function onCreateProject() {
  const name = await uiPrompt("Nombre del nuevo proyecto:", "", "Nuevo proyecto");
  if (!name || !name.trim()) return;

  try {
    const res = await apiPost({ action: "create_project", name: name.trim() });
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "No se pudo crear"}`, "Error");
      return;
    }

    await init();
    showToast(`Proyecto "${name.trim()}" creado.`, "success");
  } catch (err) {
    await uiAlert(`Error: ${err?.message || String(err)}`, "Error");
  }
}

async function moveToTrash(folder) {
  if (!folder) return;
  const ok = await uiConfirm(`Enviar "${folder}" a papeleria?`, "Confirmar accion");
  if (!ok) return;

  try {
    const res = await apiPost({ action: "move", folder });
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "No se pudo mover"}`, "Error");
      return;
    }

    await init();
    showToast(`"${folder}" enviado a papeleria.`, "success");
  } catch (err) {
    await uiAlert(`Error: ${err?.message || String(err)}`, "Error");
  }
}

async function restoreProject(folder) {
  if (!folder) return;

  const customName = (await uiPrompt("Restaurar con este nombre (deja vacio para usar el mismo):", folder, "Restaurar proyecto")) || "";

  try {
    const payload = { action: "restore_project", folder };
    if (customName.trim() && customName.trim() !== folder) {
      payload.new_name = customName.trim();
    }

    const res = await apiPost(payload);
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "No se pudo restaurar"}`, "Error");
      return;
    }

    await init();
    switchTab("projects");
    showToast(`"${folder}" restaurado.`, "success");
  } catch (err) {
    await uiAlert(`Error: ${err?.message || String(err)}`, "Error");
  }
}

async function deletePermanently(folder) {
  if (!folder) return;
  const ok = await uiConfirm(`Esto eliminara "${folder}" de forma definitiva. Esta accion no se puede deshacer.\n\nContinuar?`, "Borrado definitivo");
  if (!ok) return;

  try {
    const res = await apiPost({ action: "delete_permanently", folder });
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "No se pudo eliminar"}`, "Error");
      return;
    }

    await init();
    switchTab("trash");
    showToast(`"${folder}" eliminado definitivamente.`, "success");
  } catch (err) {
    await uiAlert(`Error: ${err?.message || String(err)}`, "Error");
  }
}

async function onRefreshMetrics() {
  const btn = document.getElementById("refreshMetricsBtn");
  const original = btn.textContent;
  btn.disabled = true;
  btn.textContent = "Actualizando...";

  try {
    const res = await apiPost({ action: "refresh_metrics" });
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "No se pudo actualizar"}`, "Error");
      return;
    }

    await init(true);
    showToast("Metricas actualizadas.", "success");
  } catch (err) {
    await uiAlert(`Error: ${err?.message || String(err)}`, "Error");
  } finally {
    btn.disabled = false;
    btn.textContent = original;
  }
}

async function openFolderDetails(folder, fromTrash) {
  if (!folder) return;

  state.currentFolderView = { folder, fromTrash };
  document.getElementById("folderModalLabel").textContent = fromTrash ? `Papeleria: ${folder}` : folder;
  document.getElementById("filesPane").innerHTML = loaderHtml("Cargando contenido...");
  document.getElementById("readmePane").innerHTML = "";
  toggleFolderTabs("files");
  openModal("folderModal");

  try {
    const data = await apiPost({ action: "list_files", folder, fromTrash });
    if (!data.success) throw new Error(data.message || "No se pudo listar");

    const folders = (data.items || []).filter((x) => x.type === "folder");
    const files = (data.items || []).filter((x) => x.type === "file");

    let html = "";
    if (folders.length) {
      html += `<div class="rounded-xl border border-slate-200 p-3"><p class="mb-2 text-sm font-semibold text-slate-700">Carpetas</p><ul class="space-y-2">${folders
        .map((f) => `<li class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm"><span>${escapeHtml(f.name)}</span><span class="text-slate-500">${escapeHtml(f.size)}</span></li>`)
        .join("")}</ul></div>`;
    }

    if (files.length) {
      html += `<div class="mt-3 rounded-xl border border-slate-200 p-3"><p class="mb-2 text-sm font-semibold text-slate-700">Archivos</p><ul class="space-y-2">${files
        .map((f) => `<li class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm"><span>${escapeHtml(f.name)}</span><span class="text-slate-500">${escapeHtml(f.size)}</span></li>`)
        .join("")}</ul></div>`;
    }

    if (!html) {
      html = emptyStateHtml("Carpeta vacia", "No hay elementos para mostrar.");
    }

    document.getElementById("filesPane").innerHTML = html;

    const readmePane = document.getElementById("readmePane");
    const readmeBtn = document.getElementById("readmeTabBtn");
    if (data.readme && String(data.readme).trim()) {
      readmePane.innerHTML = `<div class="rounded-xl border border-slate-200 p-4">${data.readme}</div>`;
      readmeBtn.disabled = false;
      readmeBtn.classList.remove("opacity-50", "cursor-not-allowed");
    } else {
      readmePane.innerHTML = `<div class="rounded-xl border border-dashed border-slate-200 p-4 text-sm text-slate-500">Este proyecto no tiene README.md.</div>`;
      readmeBtn.disabled = true;
      readmeBtn.classList.add("opacity-50", "cursor-not-allowed");
    }
  } catch (err) {
    document.getElementById("filesPane").innerHTML = errorHtml(err?.message || String(err));
  }
}

function toggleFolderTabs(tab) {
  const filesBtn = document.getElementById("filesTabBtn");
  const readmeBtn = document.getElementById("readmeTabBtn");
  const filesPane = document.getElementById("filesPane");
  const readmePane = document.getElementById("readmePane");

  const showFiles = tab !== "readme";

  filesBtn.classList.toggle("bg-slate-900", showFiles);
  filesBtn.classList.toggle("text-white", showFiles);
  filesBtn.classList.toggle("text-slate-700", !showFiles);

  readmeBtn.classList.toggle("bg-slate-900", !showFiles);
  readmeBtn.classList.toggle("text-white", !showFiles);
  readmeBtn.classList.toggle("text-slate-700", showFiles);

  filesPane.classList.toggle("hidden", !showFiles);
  readmePane.classList.toggle("hidden", showFiles);
}

async function openPasswordsModal(folder) {
  if (!folder) return;
  state.currentPasswordsFolder = folder;
  document.getElementById("passwordsModalLabel").textContent = `Contrasenas: ${folder}`;
  document.getElementById("newUserName").value = "";
  document.getElementById("newPassValue").value = "";
  document.getElementById("passwordsModalBody").innerHTML = loaderHtml("Cargando contrasenas...");
  openModal("passwordsModal");

  await loadFolderPasswords(folder);

  const addBtn = document.getElementById("addPasswordBtn");
  addBtn.onclick = async () => {
    const name = document.getElementById("newUserName").value.trim();
    const password = document.getElementById("newPassValue").value.trim();

    if (!name || !password) {
      await uiAlert("Completa nombre y contrasena.", "Datos incompletos");
      return;
    }

    const res = await apiPost({ action: "save_passwords", folder, name, password });
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "No se pudo guardar"}`, "Error");
      return;
    }

    document.getElementById("newPassValue").value = "";
    await loadFolderPasswords(folder);
    showToast("Credencial guardada.", "success");
  };
}

async function loadFolderPasswords(folder) {
  const modalBody = document.getElementById("passwordsModalBody");

  try {
    const data = await apiPost({ action: "list_passwords", folder });
    if (!data.success) throw new Error(data.message || "No se pudo cargar");

    const passwords = data.passwords || [];
    if (!passwords.length) {
      modalBody.innerHTML = emptyStateHtml("Sin contrasenas", "Agrega el primer acceso desde el panel lateral.");
      return;
    }

    modalBody.innerHTML = `
      <div class="space-y-2">
        ${passwords
          .map((p) => `
            <div class="flex flex-col gap-2 rounded-xl border border-slate-200 p-3 md:flex-row md:items-center md:justify-between">
              <div>
                <p class="font-semibold text-slate-800">${escapeHtml(p.name || "")}</p>
                <p class="font-mono text-sm text-slate-600">${escapeHtml(p.password || "")}</p>
              </div>
              <div class="flex gap-2">
                <button data-copy-pass="${escapeAttr(p.password || "")}" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50">Copiar</button>
                <button data-update-pass="${escapeAttr(p.name || "")}" data-current-pass="${escapeAttr(p.password || "")}" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50">Actualizar</button>
                <button data-delete-pass="${escapeAttr(p.name || "")}" class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">Eliminar</button>
              </div>
            </div>
          `)
          .join("")}
      </div>
    `;

    bindPasswordRowActions(folder);
  } catch (err) {
    modalBody.innerHTML = errorHtml(err?.message || String(err));
  }
}

function bindPasswordRowActions(folder) {
  document.querySelectorAll("[data-copy-pass]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const val = btn.dataset.copyPass || "";
      try {
        await navigator.clipboard.writeText(val);
        btn.textContent = "Copiado";
        setTimeout(() => {
          btn.textContent = "Copiar";
        }, 1200);
      } catch (_) {
        await uiAlert("No se pudo copiar.", "Portapapeles");
      }
    });
  });

  document.querySelectorAll("[data-update-pass]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const name = btn.dataset.updatePass || "";
      const current = btn.dataset.currentPass || "";
      const updated = await uiPrompt(`Nueva contrasena para ${name}:`, current, "Actualizar contrasena");
      if (!updated || !updated.trim()) return;

      const res = await apiPost({ action: "update_password", folder, name, password: updated.trim() });
      if (!res.success) {
        await uiAlert(`Error: ${res.message || "No se pudo actualizar"}`, "Error");
        return;
      }

      await loadFolderPasswords(folder);
      showToast("Contrasena actualizada.", "success");
    });
  });

  document.querySelectorAll("[data-delete-pass]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const name = btn.dataset.deletePass || "";
      if (!(await uiConfirm(`Eliminar credencial "${name}"?`, "Confirmar eliminacion"))) return;

      const res = await apiPost({ action: "delete_password", folder, name });
      if (!res.success) {
        await uiAlert(`Error: ${res.message || "No se pudo eliminar"}`, "Error");
        return;
      }

      await loadFolderPasswords(folder);
      showToast("Credencial eliminada.", "success");
    });
  });
}

async function onOpenPhpIni() {
  try {
    const res = await apiGet("get_php_ini");
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "No se pudo leer php.ini"}`, "Error");
      return;
    }

    document.getElementById("phpIniEditor").value = res.content || "";
    openModal("phpIniModal");
  } catch (err) {
    await uiAlert(`Error: ${err?.message || String(err)}`, "Error");
  }
}

async function onSavePhpIni() {
  const ok = await uiConfirm("Guardar cambios en php.ini?", "Confirmar cambios");
  if (!ok) return;

  try {
    const content = document.getElementById("phpIniEditor").value;
    const res = await apiPost({ action: "save_php_ini", content });
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "No se pudo guardar"}`, "Error");
      return;
    }

    showToast("php.ini guardado. Reinicia Apache para aplicar cambios.", "info", 4200);
    closeModal("phpIniModal");
  } catch (err) {
    await uiAlert(`Error: ${err?.message || String(err)}`, "Error");
  }
}

function selectionList(kind) {
  return kind === "trash" ? state.selectedTrash : state.selectedProjects;
}

function toggleSelection(kind, folder, checked) {
  if (!folder) return;
  const current = new Set(selectionList(kind));
  if (checked) {
    current.add(folder);
  } else {
    current.delete(folder);
  }

  if (kind === "trash") {
    state.selectedTrash = Array.from(current);
  } else {
    state.selectedProjects = Array.from(current);
  }

  syncBulkBars();
}

function isSelected(kind, folder) {
  return selectionList(kind).includes(folder);
}

function visibleItems(kind) {
  return filterAndSort(kind === "trash" ? state.trash : state.projects).map((x) => x.name);
}

function selectVisibleItems(kind) {
  const visible = visibleItems(kind);
  if (kind === "trash") {
    state.selectedTrash = Array.from(new Set([...state.selectedTrash, ...visible]));
    if (state.currentTab === "trash") renderTrash();
  } else {
    state.selectedProjects = Array.from(new Set([...state.selectedProjects, ...visible]));
    if (state.currentTab === "projects") renderProjects();
  }
  syncBulkBars();
}

function clearSelection(kind) {
  if (kind === "trash") {
    state.selectedTrash = [];
    if (state.currentTab === "trash") renderTrash();
  } else {
    state.selectedProjects = [];
    if (state.currentTab === "projects") renderProjects();
  }
  syncBulkBars();
}

function pruneSelections() {
  const projectsSet = new Set((state.projects || []).map((x) => x.name));
  const trashSet = new Set((state.trash || []).map((x) => x.name));

  state.selectedProjects = (state.selectedProjects || []).filter((x) => projectsSet.has(x));
  state.selectedTrash = (state.selectedTrash || []).filter((x) => trashSet.has(x));
}

function syncBulkBars() {
  const projectsBar = document.getElementById("projectsBulkBar");
  const trashBar = document.getElementById("trashBulkBar");
  const pCount = document.getElementById("projectsSelectionCount");
  const tCount = document.getElementById("trashSelectionCount");

  const projectsSelected = state.selectedProjects.length;
  const trashSelected = state.selectedTrash.length;

  pCount.textContent = `${projectsSelected} seleccionados`;
  tCount.textContent = `${trashSelected} seleccionados`;

  projectsBar.classList.toggle("hidden", state.currentTab !== "projects");
  projectsBar.classList.toggle("flex", state.currentTab === "projects");

  trashBar.classList.toggle("hidden", state.currentTab !== "trash");
  trashBar.classList.toggle("flex", state.currentTab === "trash");
}

function resultCount(value) {
  if (Array.isArray(value)) return value.length;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

async function onBulkMoveToTrash() {
  const folders = state.selectedProjects.slice();
  if (!folders.length) {
    showToast("Selecciona al menos un proyecto.", "info");
    return;
  }

  const ok = await uiConfirm(`Enviar ${folders.length} proyecto(s) a papeleria?`, "Accion masiva");
  if (!ok) return;

  try {
    const res = await apiPost({ action: "bulk_move", folders });
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "No se pudo ejecutar la accion masiva"}`, "Error");
      return;
    }

    const moved = resultCount(res.moved);
    const failed = Array.isArray(res.failed) ? res.failed.length : 0;
    showToast(`Masivo completado: ${moved} enviados, ${failed} con error.`, failed ? "info" : "success", 4200);

    state.selectedProjects = [];
    await init(true);
  } catch (err) {
    await uiAlert(`Error: ${err?.message || String(err)}`, "Error");
  }
}

async function onBulkRestore() {
  const folders = state.selectedTrash.slice();
  if (!folders.length) {
    showToast("Selecciona al menos un elemento en papeleria.", "info");
    return;
  }

  const ok = await uiConfirm(`Restaurar ${folders.length} proyecto(s) seleccionados?`, "Accion masiva");
  if (!ok) return;

  try {
    const res = await apiPost({ action: "bulk_restore", folders });
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "No se pudo ejecutar la accion masiva"}`, "Error");
      return;
    }

    const restored = resultCount(res.restored);
    const failed = Array.isArray(res.failed) ? res.failed.length : 0;
    showToast(`Masivo completado: ${restored} restaurados, ${failed} con error.`, failed ? "info" : "success", 4200);

    state.selectedTrash = [];
    await init(true);
    switchTab("projects");
  } catch (err) {
    await uiAlert(`Error: ${err?.message || String(err)}`, "Error");
  }
}

async function onBulkDeleteForever() {
  const folders = state.selectedTrash.slice();
  if (!folders.length) {
    showToast("Selecciona al menos un elemento en papeleria.", "info");
    return;
  }

  const ok = await uiConfirm(
    `Borrar definitivamente ${folders.length} proyecto(s)? Esta accion no se puede deshacer.`,
    "Borrado masivo"
  );
  if (!ok) return;

  try {
    const res = await apiPost({ action: "bulk_delete_permanently", folders });
    if (!res.success) {
      await uiAlert(`Error: ${res.message || "No se pudo ejecutar la accion masiva"}`, "Error");
      return;
    }

    const deleted = resultCount(res.deleted);
    const failed = Array.isArray(res.failed) ? res.failed.length : 0;
    showToast(`Masivo completado: ${deleted} eliminados, ${failed} con error.`, failed ? "info" : "success", 4200);

    state.selectedTrash = [];
    await init(true);
    switchTab("trash");
  } catch (err) {
    await uiAlert(`Error: ${err?.message || String(err)}`, "Error");
  }
}

function openModal(id) {
  document.getElementById("overlay").style.display = "block";
  document.getElementById(id).style.display = "flex";
  document.body.style.overflow = "hidden";
}

function closeModal(id) {
  const modal = document.getElementById(id);
  if (modal) modal.style.display = "none";

  if (id === "dialogModal" && dialogState.resolver) {
    const resolver = dialogState.resolver;
    dialogState.resolver = null;
    resolver(dialogCancelValue());
  }

  const anyOpen = ["folderModal", "passwordsModal", "phpIniModal", "phpConfigModal", "smtpModal", "dialogModal"]
    .some((mid) => {
      const node = document.getElementById(mid);
      return node && node.style.display !== "none" && node.style.display !== "";
    });

  document.getElementById("overlay").style.display = anyOpen ? "block" : "none";
  document.body.style.overflow = anyOpen ? "hidden" : "";
}

function closeAllModals() {
  ["folderModal", "passwordsModal", "phpIniModal", "phpConfigModal", "smtpModal", "dialogModal"].forEach((id) => {
    closeModal(id);
  });
}

function bindDialogEvents() {
  const confirmBtn = document.getElementById("dialogConfirmBtn");
  const cancelBtn = document.getElementById("dialogCancelBtn");
  const input = document.getElementById("dialogInput");

  confirmBtn.addEventListener("click", () => {
    resolveDialog(true);
  });

  cancelBtn.addEventListener("click", () => {
    resolveDialog(false);
  });

  input.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      resolveDialog(true);
    }
  });
}

function uiAlert(message, title = "Aviso") {
  const text = title ? `${title}: ${message}` : message;
  const raw = String(message || "").toLowerCase();
  const isError = raw.startsWith("error") || raw.includes("no se pudo") || raw.includes("inval");
  showToast(text, isError ? "error" : "info", 4200);
  return Promise.resolve(true);
}

function uiConfirm(message, title = "Confirmar") {
  return openDialog({ type: "confirm", title, message, confirmText: "Aceptar", cancelText: "Cancelar" });
}

function uiPrompt(message, defaultValue = "", title = "Ingresar dato") {
  return openDialog({
    type: "prompt",
    title,
    message,
    input: true,
    defaultValue,
    confirmText: "Guardar",
    cancelText: "Cancelar",
  });
}

function openDialog(options) {
  return new Promise((resolve) => {
    dialogState.type = options.type || "alert";
    dialogState.resolver = resolve;

    const titleEl = document.getElementById("dialogTitle");
    const messageEl = document.getElementById("dialogMessage");
    const inputWrap = document.getElementById("dialogInputWrap");
    const inputEl = document.getElementById("dialogInput");
    const confirmBtn = document.getElementById("dialogConfirmBtn");
    const cancelBtn = document.getElementById("dialogCancelBtn");

    titleEl.textContent = options.title || "Aviso";
    messageEl.textContent = options.message || "";

    confirmBtn.textContent = options.confirmText || "Aceptar";
    cancelBtn.textContent = options.cancelText || "Cancelar";

    const showInput = !!options.input;
    inputWrap.classList.toggle("hidden", !showInput);
    inputEl.value = options.defaultValue || "";

    const showCancel = dialogState.type !== "alert";
    cancelBtn.style.display = showCancel ? "inline-block" : "none";

    openModal("dialogModal");

    if (showInput) {
      setTimeout(() => {
        inputEl.focus();
        inputEl.select();
      }, 0);
    } else {
      setTimeout(() => confirmBtn.focus(), 0);
    }
  });
}

function resolveDialog(confirmed) {
  if (!dialogState.resolver) return;

  const resolver = dialogState.resolver;
  dialogState.resolver = null;

  let value;
  if (dialogState.type === "confirm") {
    value = !!confirmed;
  } else if (dialogState.type === "prompt") {
    value = confirmed ? document.getElementById("dialogInput").value : null;
  } else {
    value = true;
  }

  const modal = document.getElementById("dialogModal");
  if (modal) modal.style.display = "none";

  const anyOpen = ["folderModal", "passwordsModal", "phpIniModal", "phpConfigModal", "smtpModal", "dialogModal"]
    .some((mid) => {
      const node = document.getElementById(mid);
      return node && node.style.display !== "none" && node.style.display !== "";
    });

  document.getElementById("overlay").style.display = anyOpen ? "block" : "none";
  document.body.style.overflow = anyOpen ? "hidden" : "";

  resolver(value);
}

function dialogCancelValue() {
  if (dialogState.type === "confirm") return false;
  if (dialogState.type === "prompt") return null;
  return true;
}

function showToast(message, type = "info", timeout = 2800) {
  const container = document.getElementById("toastContainer");
  if (!container) return;

  const toast = document.createElement("div");
  const safeType = ["success", "error", "info"].includes(type) ? type : "info";
  toast.className = `toast-item toast-${safeType}`;
  toast.textContent = String(message || "");
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = "0";
    toast.style.transform = "translateY(6px)";
    toast.style.transition = "opacity 140ms ease, transform 140ms ease";
    setTimeout(() => toast.remove(), 160);
  }, Math.max(900, Number(timeout || 0)));
}

async function apiGet(actionAndQuery, options = {}) {
  const response = await fetch(`${API_URL}?action=${actionAndQuery}`, {
    credentials: "same-origin",
  });

  const raw = await response.text();
  let data;
  try {
    data = JSON.parse(raw);
  } catch (_) {
    const snippet = raw.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim().slice(0, 220);
    throw new Error(`Respuesta invalida del servidor: ${snippet || "sin contenido"}`);
  }

  if (!options.skipAuthRedirect && data && data.auth_required) {
    showAuthPanel("login");
    throw new Error(data.message || "Autenticacion requerida");
  }
  return data;
}

async function apiPost(payload, options = {}) {
  const headers = { "Content-Type": "application/json" };
  if (!options.skipCsrf && state.csrfToken) {
    headers["X-CSRF-Token"] = state.csrfToken;
  }

  const response = await fetch(API_URL, {
    method: "POST",
    headers,
    credentials: "same-origin",
    body: JSON.stringify(payload),
  });

  const raw = await response.text();
  let data;
  try {
    data = JSON.parse(raw);
  } catch (_) {
    const snippet = raw.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim().slice(0, 220);
    throw new Error(`Respuesta invalida del servidor: ${snippet || "sin contenido"}`);
  }

  if (!options.skipAuthRedirect && data && data.auth_required) {
    showAuthPanel("login");
    throw new Error(data.message || "Autenticacion requerida");
  }
  return data;
}

function loaderHtml(text = "Cargando...") {
  return `
    <div class="flex min-h-[140px] items-center justify-center rounded-xl border border-dashed border-slate-200 p-4 text-sm text-slate-500">
      ${escapeHtml(text)}
    </div>
  `;
}

function errorHtml(text) {
  return `
    <div class="rounded-xl border border-rose-100 bg-rose-50 p-4 text-sm text-rose-700">
      Error: ${escapeHtml(text)}
    </div>
  `;
}

function emptyStateHtml(title, subtitle) {
  return `
    <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center">
      <p class="font-heading text-lg">${escapeHtml(title)}</p>
      <p class="mt-1 text-sm text-slate-500">${escapeHtml(subtitle)}</p>
    </div>
  `;
}

function humanBytes(bytes) {
  const b = Number(bytes || 0);
  if (b <= 0) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.min(Math.floor(Math.log(b) / Math.log(1024)), units.length - 1);
  const value = b / (1024 ** i);
  return `${value.toFixed(2)} ${units[i]}`;
}

function formatDate(ts) {
  const n = Number(ts || 0);
  if (!n) return "Sin fecha";
  try {
    return new Date(n * 1000).toLocaleString("es-MX");
  } catch (_) {
    return "Sin fecha";
  }
}

function escapeHtml(str) {
  return (str ?? "")
    .toString()
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function escapeAttr(str) {
  return escapeHtml(str).replaceAll("`", "&#96;");
}
