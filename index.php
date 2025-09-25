<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Proyectos en desarrollo</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Bootstrap + FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <!-- Tu estilo -->
  <link rel="stylesheet" href="bitnami.css">
</head>
<body>
  <div class="container">
    <!-- Header: Tamaño total + PHP dropdown + Editar php.ini -->
    <div class="mb-4 row align-items-center">
      <div class="col">
        <h5 class="fw-bold mb-0">
          Tamaño general: <span id="totalSizeLabel">—</span>
        </h5>
      </div>
      <div class="col-auto">
        <a href="/phpmyadmin/" target="_blank" class="btn btn-link p-0" title="Abrir phpMyAdmin">
          <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/4/4f/PhpMyAdmin_logo.svg/3890px-PhpMyAdmin_logo.svg.png"
               width="60" alt="phpMyAdmin Logo">
        </a>
      </div>
      <div class="col-auto">
        <div class="d-flex align-items-center gap-2">
          <div class="dropdown">
            <button class="btn btn-light dropdown-toggle" type="button" id="phpConfigDropdown" data-bs-toggle="dropdown" aria-expanded="false">
              PHP <span id="phpVersionBtn">—</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end p-3" aria-labelledby="phpConfigDropdown"
                 style="max-height: 400px; overflow-y: auto; min-width: 350px;" id="phpDropdownMenu">
            </div>
          </div>
          <button class="btn btn-secondary" id="editPhpIniBtn">
            <i class="fas fa-edit me-1"></i>Editar php.ini
          </button>
        </div>
      </div>
    </div>

    <!-- Búsqueda + Orden + Nuevo proyecto -->
    <div class="d-flex align-items-center mb-3 gap-2">
      <div class="search-bar d-flex align-items-center gap-2">
        <i class="fas fa-search text-muted"></i>
        <input type="text" id="searchInput" placeholder="Buscar proyectos..." class="form-control border-0">
      </div>
      <div class="dropdown">
        <button class="btn btn-light dropdown-toggle" type="button" id="sortDropdown" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="fas fa-sort me-1 text-muted"></i> Ordenar
        </button>
        <ul class="dropdown-menu" aria-labelledby="sortDropdown">
          <li><a class="dropdown-item" href="#" data-sort="name_asc"><i class="fas fa-chevron-up me-1"></i> Nombre</a></li>
          <li><a class="dropdown-item" href="#" data-sort="name_desc"><i class="fas fa-chevron-down me-1"></i> Nombre</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="#" data-sort="size_asc"><i class="fas fa-chevron-up me-1"></i> Tamaño</a></li>
          <li><a class="dropdown-item" href="#" data-sort="size_desc"><i class="fas fa-chevron-down me-1"></i> Tamaño</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="#" data-sort="created_asc"><i class="fas fa-chevron-up me-1"></i> Fecha</a></li>
          <li><a class="dropdown-item" href="#" data-sort="created_desc"><i class="fas fa-chevron-down me-1"></i> Fecha</a></li>
        </ul>
      </div>
      <button class="btn btn-success" id="newProjectBtn">
        <i class="fas fa-plus me-1"></i> Nuevo proyecto
      </button>
    </div>

    <h4 class="mb-3 fw-bold">Proyectos</h4>
    <div class="row g-4" id="foldersContainer">
      <!-- Se rellena por JS -->
    </div>
  </div>

  <!-- Modal php.ini -->
  <div class="modal fade" id="phpIniModal" tabindex="-1" aria-labelledby="phpIniModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="phpIniModalLabel">Editar php.ini</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <textarea id="phpIniEditor" class="form-control" style="font-family: monospace; min-height:500px;"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-primary" id="savePhpIniBtn">Guardar cambios</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal contraseñas -->
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
            <div class="d-flex flex-column align-items-center justify-content-center" style="min-height:180px;">
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

  <!-- Modal archivos + README -->
  <div class="modal fade" id="folderModal" tabindex="-1" aria-labelledby="folderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content border-0 shadow">
        <div class="modal-header">
          <h5 class="modal-title" id="folderModalLabel"></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-0" id="folderModalBody">
          <ul class="nav nav-tabs" id="folderTabs" role="tablist">
            <li class="nav-item">
              <button class="nav-link active" id="files-tab" data-bs-toggle="tab" data-bs-target="#files" type="button" role="tab">Archivos</button>
            </li>
            <li class="nav-item" id="readme-tab-li" style="display:none;">
              <button class="nav-link" id="readme-tab" data-bs-toggle="tab" data-bs-target="#readme" type="button" role="tab">README</button>
            </li>
          </ul>
          <div class="tab-content p-3">
            <div class="tab-pane fade show active" id="files" role="tabpanel"></div>
            <div class="tab-pane fade" id="readme" role="tabpanel"></div>
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="app.js"></script>
</body>
</html>
