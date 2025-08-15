<?php
require_once __DIR__ . '/functions.php';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Mis Carpetas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <link rel="stylesheet" href="bitnami.css">
</head>

<body>
    <div class="container">
        <!-- Tamaño total -->
        <div class="mb-4 row align-items-center">
            <div class="col">
                <h5 class="fw-bold mb-0">
                    Tamaño general: <?= humanFileSize($totalSize) ?>
                </h5>
            </div>
            <div class="col-auto">
                <a href="/phpmyadmin/" target="_blank" class="btn btn-link p-0" title="Abrir phpMyAdmin">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/4/4f/PhpMyAdmin_logo.svg/3890px-PhpMyAdmin_logo.svg.png"
                        width="60px" alt="phpMyAdmin Logo">
                </a>
            </div>
            <div class="col-auto">
                <div class="dropdown">
                    <button class="btn btn-light dropdown-toggle" type="button" id="phpConfigDropdown"
                        data-bs-toggle="dropdown" aria-expanded="false">
                        PHP <?= PHP_VERSION ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end p-3" aria-labelledby="phpConfigDropdown"
                        style="max-height: 400px; overflow-y: auto; min-width: 350px;">
                        <!-- PHP INI Settings -->
                        <h6 class="dropdown-header">Directivas de php.ini</h6>
                        <?php
                        $ini = ini_get_all(null, false);
                        foreach ($ini as $key => $value): ?>
                            <div class="px-2">
                                <strong><?= htmlspecialchars($key) ?></strong>:
                                <?= htmlspecialchars($value) ?>
                            </div>
                        <?php endforeach ?>

                        <div class="dropdown-divider"></div>

                        <!-- PHP Extensions -->
                        <h6 class="dropdown-header">Extensiones cargadas</h6>
                        <div class="px-2">
                            <?= implode(', ', get_loaded_extensions()) ?>
                        </div>
                    </div>

                    <!-- Botón para editar php.ini -->
                    <div class="col-auto">
                        <button class="btn btn-secondary" id="editPhpIniBtn"><i class="fas fa-edit me-1"></i>Editar
                            php.ini</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Búsqueda + Dropdown de orden -->
        <div class="d-flex align-items-center mb-3 gap-2">
            <div class="search-bar d-flex align-items-center gap-2">
                <i class="fas fa-search text-muted"></i>
                <input type="text" id="searchInput" placeholder="Buscar proyectos..." class="form-control border-0">
            </div>

            <div class="dropdown">
                <button class="btn btn-light dropdown-toggle" type="button" id="sortDropdown" data-bs-toggle="dropdown"
                    aria-expanded="false">
                    <i class="fas fa-sort me-1 text-muted"></i> Ordenar
                </button>
                <ul class="dropdown-menu" aria-labelledby="sortDropdown">
                    <li>
                        <a class="dropdown-item" href="#" data-sort="name_asc">
                            <i class="fas fa-chevron-up me-1"></i> Nombre
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="#" data-sort="name_desc">
                            <i class="fas fa-chevron-down me-1"></i> Nombre
                        </a>
                    </li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li>
                        <a class="dropdown-item" href="#" data-sort="size_asc">
                            <i class="fas fa-chevron-up me-1"></i> Tamaño
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="#" data-sort="size_desc">
                            <i class="fas fa-chevron-down me-1"></i> Tamaño
                        </a>
                    </li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li>
                        <a class="dropdown-item" href="#" data-sort="created_asc">
                            <i class="fas fa-chevron-up me-1"></i> Fecha
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="#" data-sort="created_desc">
                            <i class="fas fa-chevron-down me-1"></i> Fecha
                        </a>
                    </li>
                </ul>
            </div>
            <button class="btn btn-success" id="newProjectBtn">
                <i class="fas fa-plus me-1"></i> Nuevo proyecto
            </button>

            <script>
                document.getElementById('newProjectBtn').addEventListener('click', function () {
                    const name = prompt('Ingrese el nombre del nuevo proyecto:');
                    if (name && name.trim()) {
                        fetch('functions.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'create_project', name: name.trim() })
                        })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) location.reload();
                                else alert('Error: ' + data.message);
                            })
                            .catch(err => alert('Error: ' + err));
                    }
                });
            </script>
        </div>

        <h4 class="mb-3 fw-bold">Proyectos</h4>
        <div class="row g-4" id="foldersContainer">
            <?php foreach ($folderData as $carpeta => $data):
                $ruta = $directorioActual . DIRECTORY_SEPARATOR . $carpeta;
                $size = $data['size'];
                $created = $data['created'];
                $percent = $totalSize > 0 ? ($size / $totalSize) * 100 : 0;
                $files = count(glob("$ruta/*"));
                $carpetaMin = strtolower(htmlspecialchars($carpeta));
                ?>
                <div class="col-sm-6 col-md-4 col-lg-3 folder" data-name="<?= $carpeta ?>" data-size="<?= $size ?>"
                    data-created="<?= $created ?>">
                    <a target="_blank" href="<?= urlencode($carpeta) ?>" class="text-decoration-none">
                        <div class="folder-box">
                            <!-- Botón de mover a papelería -->
                            <button class="btn btn-sm btn-danger delete-btn" data-folder="<?= $carpetaMin ?>"
                                title="Mover a papelería">
                                <i class="fas fa-trash"></i>
                            </button>
                            <!-- Botón para ver archivos del proyecto -->
                            <button class="btn btn-sm btn-primary view-btn" data-folder="<?= $carpetaMin ?>"
                                title="Ver archivos">
                                <i class="fas fa-folder-open"></i>
                            </button>
                            <!-- Botón para administrar contraseñas de administradores -->
                            <button class="btn btn-sm btn-info view-pass-btn" data-folder="<?= $carpetaMin ?>"
                                title="Administrar contraseñas de administradores">
                                <i class="fas fa-key"></i>
                            </button>
                            <!-- Botón para abrir el proyecto en vscode -->
                            <button class="btn btn-sm btn-secondary vscode-btn" title="Abrir en VSCode"
                                onclick="window.open('vscode://file/<?= urlencode($ruta) ?>', '_blank')">
                                <i class="fas fa-code-merge"></i>
                            </button>
                            <div class="folder-icon">
                                <?php include 'img/folder.svg'; ?>
                            </div>
                            <div class="folder-name mt-2"><?= $carpeta ?></div>
                            <div class="folder-size">
                                <?= humanFileSize($size) ?> (<?= round($percent, 1) ?>%)
                            </div>
                            <div class="progress mb-2" style="height: 6px;">
                                <div class="progress-bar" role="progressbar" style="width: <?= $percent ?>%;"
                                    aria-valuenow="<?= round($percent) ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <div class="file-count">
                                <?= $files > 0
                                    ? str_pad($files, 2, '0', STR_PAD_LEFT) . ' ' . ($files === 1 ? 'Archivo' : 'Archivos')
                                    : 'Vacio' ?>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Modal para editar php.ini -->
    <div class="modal fade" id="phpIniModal" tabindex="-1" aria-labelledby="phpIniModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="phpIniModalLabel">Editar php.ini</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <textarea id="phpIniEditor" class="form-control"
                        style="font-family: monospace; min-height:500px;"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="savePhpIniBtn">Guardar cambios</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para editar y administrar las contraseñas -->
    <div class="modal fade" id="passwordsModal" tabindex="-1" aria-labelledby="passwordsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title" id="passwordsModalLabel">
                        <i class="fas fa-key me-2"></i> Administrar contraseñas de administradores
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row">
                    <div class="p-4 col-4 border-end">
                        <h6 class="mb-3">Agregar contraseña</h6>
                        <div class="mb-2">
                            <input type="text" class="form-control mb-2" id="newUserName" placeholder="Nombre">
                            <input type="text" class="form-control mb-2" id="newPassValue" placeholder="Contraseña">
                            <button class="btn btn-success w-100" id="addPasswordBtn" type="button">
                                <i class="fas fa-plus me-1"></i> Agregar
                            </button>
                        </div>
                    </div>
                    <div class="p-4 col-8" id="passwordsModalBody">
                        <div class="d-flex flex-column align-items-center justify-content-center"
                            style="min-height:180px;">
                            <div class="spinner-border text-info mb-3"></div>
                            <div class="text-muted">Cargando contraseñas...</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Editar php.ini
            document.getElementById('editPhpIniBtn').addEventListener('click', function () {
                fetch(window.location.pathname + '?action=get_php_ini')
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('phpIniEditor').value = data.content;
                            new bootstrap.Modal(document.getElementById('phpIniModal')).show();
                        } else alert('Error: ' + data.message);
                    }).catch(err => alert('Error: ' + err));
            });

            document.getElementById('savePhpIniBtn').addEventListener('click', function () {
                if (!confirm('¿Guardar cambios en php.ini?')) return;
                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save_php_ini', content: document.getElementById('phpIniEditor').value })
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            alert('php.ini guardado. Reinicie el servidor si es necesario.');
                            location.reload();
                        } else alert('Error: ' + data.message);
                        bootstrap.Modal.getInstance(document.getElementById('phpIniModal')).hide();
                    }).catch(err => alert('Error: ' + err));
            });

            // Elementos del DOM
            const searchInput = document.getElementById('searchInput');
            const folderContainer = document.getElementById('foldersContainer');
            const sortItems = document.querySelectorAll('.dropdown-item[data-sort]');
            const sortDropdown = document.getElementById('sortDropdown');

            // Filtrar carpetas por nombre
            searchInput.addEventListener('input', function () {
                const term = this.value.toLowerCase().trim();
                Array.from(folderContainer.children).forEach(folder => {
                    folder.style.display = folder.dataset.name.includes(term) ? '' : 'none';
                });
            });

            // Aplicar preferencia guardada
            const savedSort = localStorage.getItem('folderSortPreference');
            if (savedSort) {
                applySorting(savedSort);
                const sel = document.querySelector(`.dropdown-item[data-sort="${savedSort}"]`);
                if (sel) sortDropdown.innerHTML = sel.innerHTML;
            }

            // Escuchar clicks en opciones de orden
            sortItems.forEach(item => {
                item.addEventListener('click', function (e) {
                    e.preventDefault();
                    const sortType = this.getAttribute('data-sort');
                    localStorage.setItem('folderSortPreference', sortType);
                    sortDropdown.innerHTML = this.innerHTML;
                    applySorting(sortType);
                });
            });

            // Función de ordenamiento
            function applySorting(sortType) {
                const [field, order] = sortType.split('_');
                const rows = Array.from(folderContainer.children);

                rows.sort((a, b) => {
                    if (field === 'name') {
                        return order === 'asc'
                            ? a.dataset.name.localeCompare(b.dataset.name)
                            : b.dataset.name.localeCompare(a.dataset.name);
                    } else {
                        const aVal = Number(a.dataset[field]);
                        const bVal = Number(b.dataset[field]);
                        return order === 'asc' ? aVal - bVal : bVal - aVal;
                    }
                });

                rows.forEach(row => folderContainer.appendChild(row));
            }

            // Botones de mover a papelería
            document.querySelectorAll('.delete-btn').forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    const folder = this.dataset.folder;
                    if (!confirm(`¿Está seguro que desea eliminar la carpeta "${folder}"?`)) return;
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'move', folder })
                    })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) location.reload();
                            else alert('Error: ' + res.message);
                        })
                        .catch(err => alert('Error: ' + err));
                });
            });
        });

        // Modal para ver archivos del proyecto (diseño renovado)
        const folderModalHtml = `
            <div class="modal fade" id="folderModal" tabindex="-1" aria-labelledby="folderModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow">
                <div class="modal-header bg-gradient bg-info text-white">
                    <h5 class="modal-title" id="folderModalLabel">
                    <i class="fas fa-folder-open me-2"></i> Archivos y carpetas del proyecto
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" id="folderModalBody">
                    <div class="d-flex flex-column align-items-center justify-content-center" style="min-height:180px;">
                    <div class="spinner-border text-info mb-3"></div>
                    <div class="text-muted">Cargando contenido...</div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
                </div>
            </div>
            </div>
        `;
        if (!document.getElementById('folderModal')) {
            document.body.insertAdjacentHTML('beforeend', folderModalHtml);
        }
        const folderModal = new bootstrap.Modal(document.getElementById('folderModal'));

        // Mostrar carpetas primero, luego archivos
        function loadFolderFiles(folder) {
            const modalBody = document.getElementById('folderModalBody');
            modalBody.innerHTML = `
            <div class="d-flex flex-column align-items-center justify-content-center" style="min-height:180px;">
                <div class="spinner-border text-info mb-3"></div>
                <div class="text-muted">Cargando contenido...</div>
            </div>
            `;
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'list_files', folder })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        let html = `
                            <div class="bg-info bg-opacity-10 px-4 py-3 border-bottom">
                                <span class="fw-bold fs-5"><i class="fas fa-folder me-2"></i>${folder}</span>
                            </div>
                            <div class="p-4">
                            `;
                        if (data.items.length === 0) {
                            html += '<div class="text-center text-muted py-4">No hay archivos ni carpetas en este proyecto.</div>';
                        } else {
                            // Separar carpetas y archivos
                            const folders = data.items.filter(f => f.type === 'folder');
                            const files = data.items.filter(f => f.type === 'file');

                            if (folders.length > 0) {
                                html += `
                    <div class="mb-3">
                        <div class="fw-bold text-info mb-2"><i class="fas fa-folder"></i> Carpetas</div>
                        <ul class="list-group list-group-flush">
                    `;
                                folders.forEach(f => {
                                    html += `
                        <li class="list-group-item d-flex align-items-center">
                        <i class="fas fa-folder text-warning me-2"></i>
                        <span class="flex-grow-1">${f.name}</span>
                        <span class="badge bg-secondary ms-2">${f.size}</span>
                        </li>
                    `;
                                });
                                html += `</ul></div>`;
                            }

                            if (files.length > 0) {
                                html += `
                    <div>
                        <div class="fw-bold text-info mb-2"><i class="fas fa-file"></i> Archivos</div>
                        <ul class="list-group list-group-flush">
                    `;
                                files.forEach(f => {
                                    html += `
                        <li class="list-group-item d-flex align-items-center">
                        <i class="fas fa-file text-muted me-2"></i>
                        <span class="flex-grow-1">${f.name}</span>
                        <span class="badge bg-secondary ms-2">${f.size}</span>
                        </li>
                    `;
                                });
                                html += `</ul></div>`;
                            }
                        }
                        html += `</div>`;
                        modalBody.innerHTML = html;
                    } else {
                        modalBody.innerHTML = '<div class="text-danger p-4">' + data.message + '</div>';
                    }
                })
                .catch(err => {
                    modalBody.innerHTML = '<div class="text-danger p-4">Error: ' + err + '</div>';
                });
        }

        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                loadFolderFiles(this.dataset.folder);
                folderModal.show();
            });
        });

        document.querySelectorAll('.view-pass-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const folder = this.dataset.folder;
                loadFolderPasswords(folder);
                new bootstrap.Modal(document.getElementById('passwordsModal')).show();
            });
        });

        async function loadFolderPasswords(folder) {
            const modalBody = document.getElementById('passwordsModalBody');
            modalBody.innerHTML = `
        <div class="d-flex flex-column align-items-center justify-content-center" style="min-height:180px;">
            <div class="spinner-border text-info mb-3"></div>
            <div class="text-muted">Cargando contraseñas...</div>
        </div>
    `;

            try {
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list_passwords', folder })
                });

                const data = await response.json();
                if (!data.success) throw new Error(data.message);

                modalBody.innerHTML = renderPasswordsList(data.passwords, folder);
                bindPasswordEvents(folder);

            } catch (err) {
                modalBody.innerHTML = `<div class="text-danger p-4">Error: ${err.message}</div>`;
            }
        }

        function renderPasswordsList(passwords, folder) {
            if (!Array.isArray(passwords) || passwords.length === 0) {
                return `<div class="p-4 text-center text-muted">No hay contraseñas guardadas.</div>`;
            }

            return `
        <div class="p-4">
            ${passwords.map(p => `
                <div class="list-group-item d-flex align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="fw-bold text-primary"><i class="fas fa-user me-1"></i> ${p.name}</div>
                        <span class="badge bg-light text-dark border px-3 py-2" style="font-family:monospace;">${p.password}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-secondary btn-sm copy-password-btn" data-password="${p.password}" title="Copiar contraseña">
                            <i class="fas fa-copy"></i>
                        </button>
                        <button class="btn btn-danger btn-sm delete-password-btn" data-name="${p.name}" data-folder="${folder}" title="Eliminar">
                            <i class="fas fa-trash"></i>
                        </button>
                        <button class="btn btn-primary btn-sm update-password-btn" data-name="${p.name}" data-password="${p.password}" data-folder="${folder}" title="Actualizar">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
            `).join('')}
        </div>
    `;
        }

        function bindPasswordEvents(folder) {
            // Botón de agregar
            const addBtn = document.getElementById('addPasswordBtn');
            if (addBtn) {
                addBtn.onclick = async () => {
                    const name = document.getElementById('newUserName').value.trim();
                    const password = document.getElementById('newPassValue').value.trim();
                    if (!name || !password) return alert('Por favor, complete ambos campos.');

                    try {
                        const res = await sendPasswordAction('save_passwords', { name, password, folder });
                        loadFolderPasswords(folder);
                    } catch (err) {
                        alert('Error: ' + err.message);
                    }
                };
            }

            // Botón de eliminar
            document.querySelectorAll('.delete-password-btn').forEach(btn => {
                btn.onclick = async () => {
                    const { name } = btn.dataset;
                    if (!confirm(`¿Está seguro que desea eliminar la contraseña "${name}"?`)) return;

                    try {
                        await sendPasswordAction('delete_password', { name, folder });
                        loadFolderPasswords(folder);
                    } catch (err) {
                        alert('Error: ' + err.message);
                    }
                };
            });

            // Botón de actualizar
            document.querySelectorAll('.update-password-btn').forEach(btn => {
                btn.onclick = async () => {
                    const { name, password: oldPass } = btn.dataset;
                    const newPass = prompt(`Nueva contraseña para "${name}":`, oldPass);
                    if (!newPass || !newPass.trim()) return;
                    try {
                        // Nota: aquí usamos la acción update_password
                        await sendPasswordAction('update_password', { name, password: newPass.trim(), folder });
                        loadFolderPasswords(folder);
                    } catch (err) {
                        alert('Error: ' + err.message);
                    }
                };
            });
        }

        async function sendPasswordAction(action, payload) {
            const res = await fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, ...payload })
            });

            const data = await res.json();
            if (!data.success) throw new Error(data.message);
            return data;
        }

        // Botones de copiar contraseña
        document.body.addEventListener('click', function (e) {
            if (e.target.closest('.copy-password-btn')) {
                const btn = e.target.closest('.copy-password-btn');
                const password = btn.dataset.password;
                navigator.clipboard.writeText(password).then(() => {
                    const originalIcon = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-check"></i>';
                    setTimeout(() => { btn.innerHTML = originalIcon; }, 2000);
                }).catch(err => alert('Error al copiar: ' + err));
            }
        });

    </script>
</body>

</html>