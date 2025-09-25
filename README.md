# Dashboard de Proyectos para XAMPP (htdocs)

**Objetivo:** Este mini‑dashboard te permite **administrar carpetas de proyectos** ubicadas en el mismo directorio donde coloques estos archivos (por lo general, `C:\xampp\htdocs\`). Desde una interfaz web puedes:

- Listar proyectos con tamaño total, porcentaje ocupado y conteo de archivos.
- **Crear** nuevas carpetas de proyecto.
- **Mover a papelera** un proyecto completo (`_PAPELERIA/`).
- Abrir proyectos localmente en **VS Code** (`vscode://file/...`).
- Ver el **contenido (archivos/carpetas)** y el **README.md renderizado** de cada proyecto.
- Gestionar un **banco de contraseñas por proyecto** (`pass/<proyecto>.json`): agregar, actualizar y eliminar.
- Consultar **versión de PHP, directivas de `php.ini` y extensiones cargadas**.
- **Editar `php.ini`** desde el modal básico del dashboard o usando un **editor avanzado** (`editini.php`).
- **Limpiar caché del navegador por proyecto** (CacheStorage, ServiceWorkers, IndexedDB, storages y cookies) con un clic.

> ⚠️ **Seguridad**: Está pensado para **entornos locales** (desarrollo). No lo expongas en internet sin autenticación y sin limitar permisos del usuario del sistema. La API tiene operaciones de **lectura/escritura de disco**.

---

## 1) Requisitos

- **XAMPP** (Apache + PHP 8.x). En Windows, suele estar en `C:\xampp\`.
- **VS Code** (opcional) con manejador de URLs `vscode://` habilitado (se activa al instalar VS Code).
- Permisos de escritura sobre:
  - El directorio donde está el dashboard (para crear/mover carpetas).
  - El archivo `php.ini` **si vas a editarlo desde la web**.

---

## 2) Instalación (Windows con XAMPP)

1. Copia todos los archivos de este repositorio **en el directorio que quieras gestionar**. Para gestionar todos los proyectos de `htdocs`, colócalos **directamente en** `C:\xampp\htdocs\`.
2. Crea la carpeta de imágenes y mueve el ícono:
   ```text
   C:\xampp\htdocs\img\folder.svg
   ```
   (Si el archivo `folder.svg` lo tienes en la raíz del repo, solo crea `img\` y muévelo ahí).
3. Abre **Apache** en el Panel de Control de XAMPP.
4. En tu navegador, entra a: `http://localhost/` (o a la ruta donde colocaste el dashboard si no lo pusiste en la raíz).
5. (Opcional) Crea manualmente `_PAPELERIA/` y `pass/`, aunque el sistema los genera al usarlos.

> **Nota:** La API **excluye** de la lista de proyectos las carpetas: `dashboard`, `xampp`, `webalizer`, `img`, `_PAPELERIA`, `pass`. (Puedes modificar el arreglo `$noMostrar` en `api.php`).

---

## 3) Estructura del proyecto

```
/ (misma carpeta donde está el dashboard)
├─ index.php           # UI principal (Bootstrap + modales)
├─ api.php             # API JSON (listar/crear/mover carpetas, php.ini, archivos, README, contraseñas)
├─ app.js              # Lado cliente: fetch/UX, búsqueda/orden, modales, limpiar caché
├─ bitnami.css         # Estilos iOS‑like para tarjetas/botones
├─ editini.php         # Editor avanzado de php.ini (Tailwind, AJAX)
├─ Parsedown.php       # Parser Markdown para renderizar README.md de cada proyecto
├─ img/
│  └─ folder.svg       # Ícono de carpeta (inyectado en cada tarjeta)
├─ _PAPELERIA/         # (se crea al usar “mover”) papelera de proyectos
└─ pass/               # (se crea al guardar contraseñas) JSONs por proyecto
```

---

## 4) Uso rápido del dashboard

- **Buscar**: usa el cuadro “Buscar proyectos…”. Filtra por nombre.
- **Ordenar**: por **Nombre**, **Tamaño** o **Fecha** (persistente en `localStorage`).
- **Nuevo proyecto**: botón **“Nuevo proyecto”** → crea carpeta con ese nombre.
- **Ver archivos / README**: botón **carpeta** abre un modal con:
  - **Carpetas** y **Archivos** del proyecto (solo primer nivel).
  - **README.md** (si existe) renderizado (usa `Parsedown.php` si está presente).
- **Abrir en VS Code**: botón **“</>”** (abre `vscode://file/<ruta>`).
- **Contraseñas**: botón **llave** abre gestor por proyecto:
  - **Agregar**: nombre + contraseña.
  - **Actualizar**: edita contraseña de una entrada existente.
  - **Eliminar**: borra una entrada.
  - Se guardan en `pass/<proyecto>.json` (texto plano; **no usar en producción**).
- **Mover a papelera**: botón **basura** → mueve carpeta a `_PAPELERIA/<proyecto>/`.
- **Limpiar caché (por proyecto)**: botón **escoba**. Intenta:
  - Borrar **CacheStorage** de URLs bajo `/{proyecto}/`.
  - **Desregistrar Service Workers** con *scope* en `/{proyecto}/`.
  - Borrar **IndexedDB** cuyo nombre contenga el proyecto.
  - Quitar **localStorage**, **sessionStorage** y **cookies** relacionadas.
  - Luego, recarga con **Ctrl+F5** el sitio del proyecto.

---

## 5) Editor de `php.ini`

### 5.1 Modal básico (desde el dashboard)
- **Ver**: versión de PHP, **directivas** (`ini_get_all`) y **extensiones** cargadas.
- **Editar**: botón **“Editar php.ini”** abre un modal con el **contenido del archivo** para editarlo directo y **guardar** (si el archivo es escribible).

> Después de guardar cambios en `php.ini`, **reinicia Apache** desde XAMPP para aplicarlos.

### 5.2 Editor avanzado (`editini.php`)
- UI en Tailwind con diseño iOS. Permite:
  - Cambiar **directivas** comunes (memory_limit, upload_max_filesize, post_max_size, max_execution_time, max_input_vars, display_errors, error_reporting, date.timezone) con **tooltips**.
  - **Activar/Desactivar** líneas `extension=` y `zend_extension=` desde un listado con búsqueda.
  - **Agregar** nuevas extensiones por nombre o archivo (p. ej., `gd`, `intl`, `php_gd.dll`, `oci8_19`).
- Endpoints:
  - `GET editini.php?action=state[&file=/ruta/php.ini]` → estado actual: ruta, directivas y extensiones (con índice de línea).
  - `POST editini.php?action=save` (JSON):
    ```json
    {{
      "kv":      {{ "memory_limit": "512M", "display_errors": "On" }},
      "ext_on":  [12, 58],      // índices de línea a habilitar ("extension=")
      "zext_on": [3],           // índices de línea a habilitar ("zend_extension=")
      "new_ext": "intl"         // opcional, agrega una línea
    }}
    ```
- Hace **backup automático** del `php.ini`: `php.ini.bak.YYYYMMDD_HHMMSS`.

> Si no pasas `file=`, usará el `php.ini` cargado por PHP (`php_ini_loaded_file()`).

---

## 6) API del dashboard (`api.php`)

Todas las respuestas son **JSON**; cuando falla, incluye `success: false` y `message`.
Base URL: `api.php`.

### 6.1 Inicialización
`GET api.php?action=init` →
```json
{{
  "success": true,
  "php_version": "8.x",
  "ini": {{ "memory_limit": "...", "upload_max_filesize": "..." }},
  "extensions": ["curl","mbstring", "..."],
  "total_size_bytes": 123456789,
  "total_size_human": "117.7 MB",
  "projects": [
    {{
      "name": "mi-app",
      "path": "C:\\xampp\\htdocs\\mi-app",
      "size_bytes": 1234,
      "size_human": "1.2 KB",
      "files_count": 7,
      "created": 1727040000
    }}
  ]
}}
```

### 6.2 `php.ini`
- `GET api.php?action=get_php_ini` → `{ success, path, content }`.
- `POST api.php` body `{ "action":"save_php_ini", "content":"..." }` → `{ success }`.

### 6.3 Proyectos (carpetas)
- `POST api.php` body `{ "action":"create_project", "name":"carpeta" }` → crea directorio.
- `POST api.php` body `{ "action":"move", "folder":"carpeta" }` → mueve a `_PAPELERIA/`.
- `POST api.php` body `{ "action":"list_files", "folder":"carpeta" }` → retorna:
  ```json
  { "success": true, "items": [ { "type":"folder|file", "name":"...", "size":"...", "created": 0 } ], "readme": "<html>...</html>" }
  ```
  > El **README** se toma de `carpeta/README.md`; se renderiza con `Parsedown.php` si está disponible (si no, texto plano).

### 6.4 Contraseñas por proyecto
- **Archivo**: `pass/<carpeta>.json` (se crea al guardar). Estructura: `[ { "name":"admin", "password":"..." } ]`.
- Endpoints:
  - `POST` `{ "action":"list_passwords", "folder":"carpeta" }`
  - `POST` `{ "action":"save_passwords", "folder":"carpeta", "name":"...", "password":"..." }`
  - `POST` `{ "action":"update_password", "folder":"carpeta", "name":"...", "password":"..." }`
  - `POST` `{ "action":"delete_password", "folder":"carpeta", "name":"..." }`

---

## 7) Buenas prácticas y seguridad

- **Local only**: Usa el dashboard en **localhost** o detrás de autenticación HTTP (Basic/Digest) si lo publicas en una red.
- **Permisos mínimos**: Da permisos de escritura **solo** al directorio que gestiones y **temporalmente** a `php.ini` cuando edites.
- **Respaldo**: `editini.php` crea backups de `php.ini`. Aun así, respalda manualmente antes de cambios críticos.
- **Contraseñas**: Los JSON de `pass/` están en **texto plano**. Úsalos solo para pruebas internas.
- **XSS/CSRF**: Todas las acciones claves piden confirmación desde el UI; al ser entorno local y misma‑origen, el riesgo es bajo, pero si publicas el panel añade autenticación y tokens CSRF.
- **Rutas**: El botón VS Code abrirá rutas locales. Funciona si el navegador/OS reconoce `vscode://file/...`.

---

## 8) Solución de problemas

- **“php.ini no es escribible”**: ejecuta el editor como **Administrador** o cambia temporalmente permisos del archivo (`C:\xampp\php\php.ini`). Reinicia Apache tras guardar.
- **No veo mi README**: asegúrate de poner `README.md` en la raíz del proyecto (misma carpeta que se lista).
- **No se mueve a `_PAPELERIA`**: verifica que no exista ya una carpeta con **el mismo nombre** dentro de `_PAPELERIA/` y que no haya archivos bloqueados por otro proceso.
- **El conteo de archivos es bajo**: el contador muestra **solo primer nivel**; el tamaño sí es **recursivo**.
- **Limpieza de caché no funciona**: algunos navegadores no permiten listar todas las bases IndexedDB o cookies por dominio; úsalo como **mejor esfuerzo** y refresca con **Ctrl+F5**.
- **Extensión no carga tras activarla**: revisa `extension_dir` en `php.ini` y que el archivo exista (p. ej. `C:\xampp\php\ext\php_intl.dll` en Windows).

---

## 9) Personalización

- Edita `$noMostrar` en `api.php` para incluir/excluir carpetas del listado.
- Agrega más **directivas** al arreglo `$editableKeys` en `editini.php` para mostrarlas en el editor avanzado.
- Cambia el look & feel en `bitnami.css` (paleta iOS‑like, tarjetas con `box-shadow`, botones redondeados).
- Cambia el SVG de `img/folder.svg` si quieres otro ícono.

---

## 10) Créditos

- **Bootstrap 5** + **Font Awesome** en `index.php`.
- **Tailwind** en `editini.php`.
- **Parsedown** para renderizar Markdown de README por proyecto.
- Ícono de carpeta: `img/folder.svg` (incluido).

---

## 11) Roadmap sugerido

- Autenticación (roles) y bitácora de acciones (create/move/edit‑ini).
- Paginación y métricas (tamaño por subcarpeta, evolución histórica).
- Editor de README.md desde el propio modal.
- Acciones masivas (mover/borrar múltiples proyectos).
- Exportar/Importar contraseñas cifradas por proyecto.
