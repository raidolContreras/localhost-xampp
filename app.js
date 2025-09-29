// app.js — Lado cliente (index.html), usa API JSON en api.php
const API_URL = "api.php";

/**
 * Inserta el SVG animado de la carpeta en cada tarjeta de proyecto.
 * El SVG se carga vía fetch y se inserta en el div .folder-icon.
 */
let folderSvg = null;
async function loadFolderSvg() {
  if (folderSvg) return folderSvg;
  const res = await fetch("img/folder.svg");
  folderSvg = await res.text();
  return folderSvg;
}

// Sobrescribe renderProjects para incluir el SVG en cada .folder-icon
const origRenderProjects = renderProjects;
renderProjects = async function (sortType = "name_asc") {
  origRenderProjects(sortType);
  const svg = await loadFolderSvg();
  document.querySelectorAll(".folder-icon").forEach((el) => {
    el.innerHTML = svg;
  });
};

let state = {
  projects: [],
  totalSizeBytes: 0,
};

// --- Favoritos (persistencia en localStorage) ---
const FAV_KEY = "favProjects";
function getFavSet() {
  try { return new Set(JSON.parse(localStorage.getItem(FAV_KEY) || "[]")); }
  catch { return new Set(); }
}
function saveFavSet(set) {
  localStorage.setItem(FAV_KEY, JSON.stringify([...set]));
}
function isFav(name) {
  return getFavSet().has(name);
}
function currentSort() {
  return localStorage.getItem("folderSortPreference") || "name_asc";
}

document.addEventListener("DOMContentLoaded", () => {
  init();

  // Búsqueda
  document.getElementById("searchInput").addEventListener("input", onSearch);

  // Orden
  document.querySelectorAll(".dropdown-item[data-sort]").forEach((el) => {
    el.addEventListener("click", (e) => {
      e.preventDefault();
      const sortType = el.getAttribute("data-sort");
      localStorage.setItem("folderSortPreference", sortType);
      document.getElementById("sortDropdown").innerHTML = el.innerHTML;
      renderProjects(sortType);
    });
  });

  // Nuevo proyecto
  document
    .getElementById("newProjectBtn")
    .addEventListener("click", async () => {
      const name = prompt("Ingrese el nombre del nuevo proyecto:");
      if (!name || !name.trim()) return;

      try {
        const res = await apiPost({
          action: "create_project",
          name: name.trim(),
        });
        if (!res.success) return alert("Error: " + (res.message || ""));
        await init(); // refresca lista
      } catch (err) {
        alert("Error: " + err.message);
      }
    });

  // php.ini (abrir y guardar)
  document
    .getElementById("editPhpIniBtn")
    .addEventListener("click", async () => {
      try {
        const res = await apiGet("get_php_ini");
        if (!res.success) return alert("Error: " + (res.message || ""));
        document.getElementById("phpIniEditor").value = res.content || "";
        new bootstrap.Modal(document.getElementById("phpIniModal")).show();
      } catch (err) {
        alert("Error: " + err.message);
      }
    });

  document
    .getElementById("savePhpIniBtn")
    .addEventListener("click", async () => {
      if (!confirm("¿Guardar cambios en php.ini?")) return;
      const content = document.getElementById("phpIniEditor").value;
      try {
        const res = await apiPost({ action: "save_php_ini", content });
        if (res.success) {
          alert("php.ini guardado. Reinicie Apache si es necesario.");
          bootstrap.Modal.getInstance(
            document.getElementById("phpIniModal")
          ).hide();
        } else {
          alert("Error: " + (res.message || ""));
        }
      } catch (err) {
        alert("Error: " + err.message);
      }
    });
});

// ------------------- API helpers -------------------
async function apiGet(action) {
  const r = await fetch(`${API_URL}?action=${encodeURIComponent(action)}`, {
    credentials: "same-origin",
  });
  return r.json();
}
async function apiPost(payload) {
  const r = await fetch(API_URL, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify(payload),
  });
  return r.json();
}

// ------------------- Init + Render -------------------
async function init() {
  const container = document.getElementById("foldersContainer");
  container.innerHTML = loaderHtml("Cargando proyectos...");

  try {
    const data = await apiGet("init");
    if (!data.success) throw new Error(data.message || "Respuesta inválida");

    state.projects = data.projects || [];
    state.totalSizeBytes = Number(data.total_size_bytes || 0);

    // Quitar favoritos que ya no existen en la lista de proyectos
    (() => {
      const favs = getFavSet();
      const existing = new Set((state.projects || []).map(p => p.name));
      let changed = false;
      for (const n of [...favs]) {
        if (!existing.has(n)) { favs.delete(n); changed = true; }
      }
      if (changed) saveFavSet(favs);
    })();

    // Header totals + PHP info
    document.getElementById("totalSizeLabel").textContent =
      data.total_size_human || "—";
    document.getElementById("phpVersionBtn").textContent =
      data.php_version || "—";
    renderPhpDropdown(data.ini || {}, data.extensions || []);

    // Orden inicial (si hay preferencia)
    const savedSort =
      localStorage.getItem("folderSortPreference") || "name_asc";
    const sel = document.querySelector(
      `.dropdown-item[data-sort="${savedSort}"]`
    );
    if (sel) document.getElementById("sortDropdown").innerHTML = sel.innerHTML;

    renderProjects(savedSort);
  } catch (err) {
    container.innerHTML = errorHtml(err.message || String(err));
  }
}

function renderPhpDropdown(iniObj, extensionsArr) {
  const el = document.getElementById("phpDropdownMenu");
  let html = `<h6 class="dropdown-header">Directivas de php.ini</h6>`;
  const keys = Object.keys(iniObj).sort((a, b) => a.localeCompare(b));
  keys.forEach((k) => {
    const v = iniObj[k];
    html += `<div class="px-2"><strong>${escapeHtml(k)}</strong>: ${escapeHtml(
      String(v)
    )}</div>`;
  });
  html += `<div class="dropdown-divider"></div>`;
  html += `<h6 class="dropdown-header">Extensiones cargadas</h6>`;
  html += `<div class="px-2">${extensionsArr
    .map((e) => escapeHtml(e))
    .join(", ")}</div>`;
  el.innerHTML = html;
}

function renderProjects(sortType = "name_asc") {
  const container = document.getElementById("foldersContainer");
  if (!Array.isArray(state.projects) || state.projects.length === 0) {
    container.innerHTML = `<div class="text-muted p-4">No hay proyectos.</div>`;
    return;
  }

  // 1) Orden base según preferencia
  let arr = [...state.projects];
  const [field, order] = String(sortType).split("_");
  arr.sort((a, b) => {
    if (field === "name") {
      return order === "asc" ? a.name.localeCompare(b.name) : b.name.localeCompare(a.name);
    }
    if (field === "size") {
      return order === "asc" ? (a.size_bytes - b.size_bytes) : (b.size_bytes - a.size_bytes);
    }
    if (field === "created") {
      return order === "asc" ? (a.created - b.created) : (b.created - a.created);
    }
    return 0;
  });

  // 2) Particionar por favoritos => favoritos siempre van primero (manteniendo el orden dentro de su grupo)
  const favsSet = getFavSet();
  const favs = arr.filter(p => favsSet.has(p.name));
  const normal = arr.filter(p => !favsSet.has(p.name));
  const ordered = [...favs, ...normal];

  // 3) Render
  const total = Number(state.totalSizeBytes || 0);
  container.innerHTML = ordered.map((p) => {
    const percent = total > 0 ? (p.size_bytes / total) * 100 : 0;
    const files = Number(p.files_count || 0);
    const filesLabel = files > 0
      ? `${String(files).padStart(2, "0")} ${files === 1 ? "Archivo" : "Archivos"}`
      : "Vacio";
    const vsPath = encodeURI(p.path || "");
    const favActive = favsSet.has(p.name);

    return `
      <div class="col-sm-6 col-md-4 col-lg-3 folder"
           data-name="${escapeAttr(p.name.toLowerCase())}"
           data-size="${escapeAttr(String(p.size_bytes))}"
           data-created="${escapeAttr(String(p.created))}">
        <a href="${p.name}" class="no-underline">
          <div class="folder-box">
            <!-- Botón estrella (favorito) -->
            <button class="favorite-btn ${favActive ? "active" : ""}"
                    data-folder="${escapeAttr(p.name)}"
                    aria-label="${favActive ? "Quitar de favoritos" : "Marcar como favorito"}"
                    aria-pressed="${favActive ? "true" : "false"}"
                    title="${favActive ? "Quitar de favoritos" : "Agregar a favoritos"}">
              <i class="${favActive ? "fas" : "far"} fa-star"></i>
            </button>

            <div class="folder-actions btn-group">
              <button class="btn btn-sm btn-primary view-btn" data-folder="${escapeAttr(p.name)}" title="Ver archivos">
                <i class="fas fa-folder-open"></i>
              </button>
              <button class="btn btn-sm btn-secondary vscode-btn" data-path="${vsPath}" title="Abrir en VSCode">
                <i class="fas fa-code-merge"></i>
              </button>
              <button class="btn btn-sm btn-info view-pass-btn" data-folder="${escapeAttr(p.name)}" title="Administrar contraseñas de administradores">
                <i class="fas fa-key"></i>
              </button>
              <button class="btn btn-sm btn-danger delete-btn" data-folder="${escapeAttr(p.name)}" title="Mover a papelería">
                <i class="fas fa-trash"></i>
              </button>
              <button class="btn btn-sm btn-warning cache-btn" data-folder="${escapeAttr(p.name)}" title="Limpiar caché de este proyecto">
                <i class="fas fa-broom"></i>
              </button>
            </div>

            <div class="folder-icon"></div>

            <div class="folder-name mt-2">${escapeHtml(p.name)}</div>
            <div class="folder-size">${escapeHtml(p.size_human)} (${percent.toFixed(1)}%)</div>

            <div class="progress mb-2" style="height:6px;">
              <div class="progress-bar" role="progressbar"
                   style="width:${percent.toFixed(2)}%;"
                   aria-valuenow="${percent.toFixed(0)}" aria-valuemin="0" aria-valuemax="100"></div>
            </div>

            <div class="file-count">${filesLabel}</div>
          </div>
        </a>
      </div>
    `;
  }).join("");

  // 4) Bind de acciones existentes
  container.querySelectorAll(".delete-btn").forEach((btn) => {
    btn.addEventListener("click", async (e) => {
      e.preventDefault();
      const folder = btn.dataset.folder;
      if (!confirm(`¿Está seguro que desea eliminar la carpeta "${folder}"?`)) return;
      try {
        const res = await apiPost({ action: "move", folder });
        if (!res.success) return alert("Error: " + (res.message || ""));
        await init();
      } catch (err) { alert("Error: " + err.message); }
    });
  });

  container.querySelectorAll(".view-btn").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      const modalEl = document.getElementById("folderModal");
      const modal = new bootstrap.Modal(modalEl);
      modal.show();
      loadFolderFiles(btn.dataset.folder);
    });
  });

  container.querySelectorAll(".view-pass-btn").forEach((btn) => {
    btn.addEventListener("click", async (e) => {
      e.preventDefault();
      await loadFolderPasswords(btn.dataset.folder);
      new bootstrap.Modal(document.getElementById("passwordsModal")).show();
    });
  });

  container.querySelectorAll(".vscode-btn").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      const p = btn.dataset.path || "";
      if (p) window.open(`vscode://file/${p}`, "_blank");
    });
  });

  container.querySelectorAll(".cache-btn").forEach((btn) => {
    btn.addEventListener("click", async (e) => {
      e.preventDefault();
      const folder = btn.dataset.folder;
      if (!folder) return;

      const originalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

      try {
        const report = await clearCacheFor(folder);
        alert(
          `Caché limpiada para /${folder}/\n\n` +
          `CacheStorage eliminadas: ${report.cacheDeleted} entradas\n` +
          `ServiceWorkers desregistrados: ${report.swUnregistered}\n` +
          `IndexedDB eliminadas: ${report.idbDeleted}\n` +
          `localStorage removidos: ${report.lsKeys}\n` +
          `sessionStorage removidos: ${report.ssKeys}\n` +
          `Cookies limpiadas (mejor esfuerzo): ${report.cookies}\n\n` +
          `Sugerencia: recarga ${location.origin}/${folder}/ con Ctrl+F5.`
        );
      } catch (err) {
        alert("No se pudo limpiar la caché: " + (err?.message || String(err)));
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
      }
    });
  });

  // 5) Bind del botón favorito
  container.querySelectorAll(".favorite-btn").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      // Evitar abrir el enlace de la tarjeta
      e.preventDefault();
      e.stopPropagation();
      const folder = btn.dataset.folder;
      if (!folder) return;

      const set = getFavSet();
      if (set.has(folder)) set.delete(folder); else set.add(folder);
      saveFavSet(set);

      // Re-render con el mismo sort para que se reposicione arriba/abajo
      renderProjects(currentSort());
      // Volver a inyectar el SVG de carpeta (render wrapper lo hace)
    });
  });
}


// ------------------- Search -------------------
function onSearch(e) {
  const term = e.target.value.toLowerCase().trim();
  const container = document.getElementById("foldersContainer");
  Array.from(container.children).forEach((card) => {
    const name = (card.getAttribute("data-name") || "").toLowerCase();
    card.style.display = name.includes(term) ? "" : "none";
  });
}

// ------------------- Modales: Archivos + README -------------------
async function loadFolderFiles(folder) {
  // Título del modal
  const titleEl = document.getElementById("folderModalLabel");
  if (titleEl) titleEl.textContent = folder;

  // Referencias a panes y tab del README (NO tocar folderModalBody)
  const filesPane = document.getElementById("files");
  const readmePane = document.getElementById("readme");
  const readmeTabLi = document.getElementById("readme-tab-li");

  // Si por algún motivo faltan nodos, salir con log
  if (!filesPane || !readmePane || !readmeTabLi) {
    console.error(
      "Estructura del modal no encontrada (#files/#readme/#readme-tab-li)."
    );
    return;
  }

  // Estado inicial: loader en Files, README oculto
  filesPane.innerHTML = loaderHtml("Cargando contenido...");
  readmePane.innerHTML = "";
  readmeTabLi.style.display = "none";

  try {
    const data = await apiPost({ action: "list_files", folder });
    if (!data.success) throw new Error(data.message || "No se pudo listar");

    const folders = (data.items || []).filter((i) => i.type === "folder");
    const files = (data.items || []).filter((i) => i.type === "file");

    let html = "";
    if (folders.length > 0) {
      html += `
        <div class="mb-3">
          <div class="fw-bold mb-2"><i class="fas fa-folder"></i> Carpetas</div>
          <ul class="list-group list-group-flush">
            ${folders
          .map(
            (f) => `
              <li class="list-group-item d-flex align-items-center">
                <i class="fas fa-folder text-warning me-2"></i>
                <span class="flex-grow-1">${escapeHtml(f.name)}</span>
                <span class="badge bg-secondary ms-2">${escapeHtml(
              f.size
            )}</span>
              </li>
            `
          )
          .join("")}
          </ul>
        </div>`;
    }
    if (files.length > 0) {
      html += `
        <div>
          <div class="fw-bold mb-2"><i class="fas fa-file"></i> Archivos</div>
          <ul class="list-group list-group-flush">
            ${files
          .map(
            (f) => `
              <li class="list-group-item d-flex align-items-center">
                <i class="fas fa-file text-muted me-2"></i>
                <span class="flex-grow-1">${escapeHtml(f.name)}</span>
                <span class="badge bg-secondary ms-2">${escapeHtml(
              f.size
            )}</span>
              </li>
            `
          )
          .join("")}
          </ul>
        </div>`;
    }
    if (!html) {
      html = `<div class="text-center text-muted py-4">No hay archivos ni carpetas en este proyecto.</div>`;
    }
    filesPane.innerHTML = html;

    // README si viene con contenido (evitamos activar tab si está vacío)
    const hasReadme = !!(data.readme && String(data.readme).trim().length);
    if (hasReadme) {
      readmePane.innerHTML = `<div class="readme-content">${data.readme}</div>`;
      readmeTabLi.style.display = "block";
      // Opcional: si quieres auto-cambiar al tab README cuando exista:
      // new bootstrap.Tab(document.getElementById('readme-tab')).show();
    } else {
      readmePane.innerHTML = "";
      readmeTabLi.style.display = "none";
    }
  } catch (err) {
    filesPane.innerHTML = errorHtml(err.message || String(err));
    readmePane.innerHTML = "";
    readmeTabLi.style.display = "none";
  }
}

// ------------------- Modales: Contraseñas -------------------
async function loadFolderPasswords(folder) {
  const modalBody = document.getElementById("passwordsModalBody");
  modalBody.innerHTML = loaderHtml("Cargando contraseñas...");

  try {
    const data = await apiPost({ action: "list_passwords", folder });
    if (!data.success) throw new Error(data.message || "No se pudo cargar");

    modalBody.innerHTML = renderPasswordsList(data.passwords || [], folder);
    bindPasswordEvents(folder);
  } catch (err) {
    modalBody.innerHTML = errorHtml(err.message || String(err));
  }
}

function renderPasswordsList(passwords, folder) {
  if (!Array.isArray(passwords) || passwords.length === 0) {
    return `<div class="p-4 text-center text-muted">No hay contraseñas guardadas.</div>`;
  }
  return `
    <div class="p-4">
      ${passwords
      .map(
        (p) => `
        <div class="list-group-item d-flex align-items-center justify-content-between gap-3">
          <div class="d-flex align-items-center gap-3">
            <div class="fw-bold text-primary"><i class="fas fa-user me-1"></i> ${escapeHtml(
          p.name || ""
        )}</div>
            <span class="badge bg-light text-dark border px-3 py-2" style="font-family:monospace;">${escapeHtml(
          p.password || ""
        )}</span>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary btn-sm copy-password-btn" data-password="${escapeAttr(
          p.password || ""
        )}" title="Copiar contraseña">
              <i class="fas fa-copy"></i>
            </button>
            <button class="btn btn-danger btn-sm delete-password-btn" data-name="${escapeAttr(
          p.name || ""
        )}" data-folder="${escapeAttr(folder)}" title="Eliminar">
              <i class="fas fa-trash"></i>
            </button>
            <button class="btn btn-primary btn-sm update-password-btn" data-name="${escapeAttr(
          p.name || ""
        )}" data-password="${escapeAttr(
          p.password || ""
        )}" data-folder="${escapeAttr(folder)}" title="Actualizar">
              <i class="fas fa-sync-alt"></i>
            </button>
          </div>
        </div>
      `
      )
      .join("")}
    </div>
  `;
}

function bindPasswordEvents(folder) {
  // copiar
  document.querySelectorAll(".copy-password-btn").forEach((btn) => {
    btn.onclick = async () => {
      try {
        await navigator.clipboard.writeText(btn.dataset.password || "");
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i>';
        setTimeout(() => (btn.innerHTML = original), 1500);
      } catch (err) {
        alert("No se pudo copiar: " + err.message);
      }
    };
  });

  // agregar
  const addBtn = document.getElementById("addPasswordBtn");
  if (addBtn) {
    addBtn.onclick = async () => {
      const name = document.getElementById("newUserName").value.trim();
      const password = document.getElementById("newPassValue").value.trim();
      if (!name || !password) return alert("Completa ambos campos");

      const res = await apiPost({
        action: "save_passwords",
        folder,
        name,
        password,
      });
      if (!res.success) return alert("Error: " + (res.message || ""));
      await loadFolderPasswords(folder);
    };
  }

  // eliminar
  document.querySelectorAll(".delete-password-btn").forEach((btn) => {
    btn.onclick = async () => {
      const name = btn.dataset.name;
      if (!confirm(`¿Eliminar la contraseña "${name}"?`)) return;
      const res = await apiPost({ action: "delete_password", folder, name });
      if (!res.success) return alert("Error: " + (res.message || ""));
      await loadFolderPasswords(folder);
    };
  });

  // actualizar
  document.querySelectorAll(".update-password-btn").forEach((btn) => {
    btn.onclick = async () => {
      const name = btn.dataset.name;
      const oldPass = btn.dataset.password || "";
      const newPass = prompt(`Nueva contraseña para "${name}":`, oldPass);
      if (!newPass || !newPass.trim()) return;
      const res = await apiPost({
        action: "update_password",
        folder,
        name,
        password: newPass.trim(),
      });
      if (!res.success) return alert("Error: " + (res.message || ""));
      await loadFolderPasswords(folder);
    };
  });
}

// ------------------- Utils UI -------------------
function loaderHtml(text = "Cargando...") {
  return `
    <div class="d-flex flex-column align-items-center justify-content-center" style="min-height:180px;">
      <div class="spinner-border text-info mb-3"></div>
      <div class="text-muted">${escapeHtml(text)}</div>
    </div>
  `;
}
function errorHtml(text) {
  return `<div class="text-danger p-4">Error: ${escapeHtml(text)}</div>`;
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

async function clearCacheFor(folder) {
  const scopePath = `/${String(folder).replace(/^\/|\/$/g, "")}/`;
  const baseUrl = location.origin + scopePath;

  const result = {
    cacheDeleted: 0,
    swUnregistered: 0,
    idbDeleted: 0,
    lsKeys: 0,
    ssKeys: 0,
    cookies: 0
  };

  // 1) Cache Storage (solo requests bajo /folder/)
  if (("caches" in window) && caches.keys) {
    const names = await caches.keys();
    for (const name of names) {
      const cache = await caches.open(name);
      const requests = await cache.keys();
      for (const req of requests) {
        if (req.url.startsWith(baseUrl)) {
          const ok = await cache.delete(req);
          if (ok) result.cacheDeleted++;
        }
      }
    }
  }

  // 2) Service Workers (solo SW con scope en /folder/)
  if ("serviceWorker" in navigator && navigator.serviceWorker.getRegistrations) {
    const regs = await navigator.serviceWorker.getRegistrations();
    for (const reg of regs) {
      try {
        if (reg.scope && reg.scope.includes(scopePath)) {
          const ok = await reg.unregister();
          if (ok) result.swUnregistered++;
        }
      } catch (_) { }
    }
  }

  // 3) IndexedDB (mejor esfuerzo, si el navegador permite enumerarlas)
  if (window.indexedDB && indexedDB.databases) {
    try {
      const dbs = await indexedDB.databases();
      for (const db of dbs) {
        const name = db?.name || "";
        if (name && (name.includes(folder) || name.includes(scopePath))) {
          await new Promise((resolve) => {
            const req = indexedDB.deleteDatabase(name);
            req.onsuccess = req.onerror = req.onblocked = () => resolve();
          });
          result.idbDeleted++;
        }
      }
    } catch (_) { }
  }

  // 4) localStorage/sessionStorage (mejor esfuerzo por coincidencia)
  try {
    for (let i = localStorage.length - 1; i >= 0; i--) {
      const k = localStorage.key(i);
      const v = localStorage.getItem(k) || "";
      if ((k && (k.includes(folder) || k.includes(scopePath))) || v.includes(scopePath)) {
        localStorage.removeItem(k);
        result.lsKeys++;
      }
    }
  } catch (_) { }
  try {
    for (let i = sessionStorage.length - 1; i >= 0; i--) {
      const k = sessionStorage.key(i);
      const v = sessionStorage.getItem(k) || "";
      if ((k && (k.includes(folder) || k.includes(scopePath))) || v.includes(scopePath)) {
        sessionStorage.removeItem(k);
        result.ssKeys++;
      }
    }
  } catch (_) { }

  // 5) Cookies (limitado: solo nombres visibles en este path)
  try {
    const cookies = document.cookie ? document.cookie.split("; ") : [];
    for (const c of cookies) {
      const [rawName] = c.split("=");
      const name = decodeURIComponent(rawName);
      // Intento en el path del proyecto
      document.cookie = `${encodeURIComponent(name)}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=${scopePath}`;
      // Intento en la raíz (por si se guardó en '/')
      document.cookie = `${encodeURIComponent(name)}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
      result.cookies++;
    }
  } catch (_) { }

  return result;
}
