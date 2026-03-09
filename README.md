# Dashboard XAMPP - Documentacion Actualizada

Panel web para administrar proyectos en htdocs con interfaz moderna (Tailwind), seguridad reforzada y flujos de mantenimiento para entorno local o red interna.

## 1. Que hace hoy el sistema

- Lista proyectos del directorio base con metricas de tamano y cantidad de archivos.
- Crea proyectos nuevos.
- Mueve proyectos a papelera y permite restaurarlos o eliminarlos de forma definitiva.
- Soporta acciones masivas en proyectos y papelera.
- Renderiza README.md por proyecto con sanitizacion defensiva de HTML.
- Gestiona credenciales por proyecto (guardado cifrado en disco).
- Muestra estado PHP, directivas y extensiones.
- Permite editar php.ini desde la interfaz.
- Incluye autenticacion con modo local o modo red.
- Incluye recuperacion de clave por correo SMTP.
- Usa cache inteligente para metricas, sin depender de limpieza manual del usuario.

## 2. Estructura del proyecto

```text
/
|- index.php           # UI principal (Tailwind + modales + auth)
|- app.js              # Logica cliente, renderizado, API, modales, toasts, bulk
|- api.php             # API JSON, auth, SMTP, cache, papelera, contrasenas, php.ini
|- editini.php         # Editor avanzado de php.ini
|- Parsedown.php       # Parser Markdown
|- bitnami.css         # Estilos legacy/complementarios
|- img/
|  |- folder.svg
|- _PAPELERIA/         # Se crea automaticamente
|- pass/               # Credenciales por proyecto
|- .dashboard_auth.json # Configuracion de seguridad y SMTP
|- .folder_cache.json   # Cache de metricas
|- .pass_key.bin        # Clave simetrica para cifrado de contrasenas
```

## 3. Flujo de seguridad y acceso

### 3.1 Primer arranque

En la primera ejecucion se solicita configuracion de seguridad:

- Modo local:
  - Sin login obligatorio.
  - Pensado para uso en localhost.
- Modo red:
  - Requiere usuario y clave.
  - Permite correo de recuperacion.
  - Requiere inicio de sesion para usar el panel.

### 3.2 Sesion y protecciones

- Login por sesion PHP.
- Token CSRF para operaciones sensibles via POST.
- Limite de intentos de login y bloqueo temporal.
- Cierre de sesion explicito desde la UI.

### 3.3 Recuperacion de password

- Flujo por codigo de 6 digitos enviado por SMTP.
- Codigo con expiracion.
- Permite establecer nueva clave del usuario administrador.

## 4. SMTP: como funciona ahora

Configuracion guardada en .dashboard_auth.json bajo la llave smtp:

- host
- port
- encryption: tls | ssl | none
- user
- pass
- from_email
- from_name

Durante envio:

- Se valida configuracion minima.
- Se abre conexion por socket al servidor SMTP.
- Se hace EHLO.
- Si encryption=tls se ejecuta STARTTLS y nueva negociacion.
- Se autentica con AUTH LOGIN.
- Se envia MAIL FROM, RCPT TO y DATA.

Mejoras aplicadas recientemente:

- Diagnostico de error de conexion mas completo (host, puerto, transporte, errno y detalle real).
- Normalizacion de host para evitar entradas invalidas con prefijos.
- Correccion automatica de combinaciones comunes mal configuradas:
  - puerto 587 con ssl se corrige a tls
  - puerto 465 con tls se corrige a ssl

## 5. Cache inteligente de metricas

El backend usa .folder_cache.json para evitar recalculo constante de tamano y conteo:

- Huella por carpeta (fingerprint) para detectar cambios.
- TTL suave para reutilizar cache reciente.
- TTL duro para forzar refresco periodico.
- Invalidacion automatica en operaciones mutantes:
  - crear proyecto
  - mover a papelera
  - restaurar
  - eliminar permanente
  - acciones masivas

Resultado: carga mas rapida y sin necesidad de boton manual de limpiar cache para uso normal.

## 6. Gestion de proyectos y papelera

### 6.1 Proyectos

- Crear proyecto.
- Abrir listado de archivos de primer nivel.
- Renderizar README.md del proyecto.
- Abrir proyecto en VS Code (vscode://file/...).
- Mover a papelera.

### 6.2 Papelera

- Tab dedicado de papelera.
- Restaurar proyecto.
- Eliminar proyecto definitivamente.

### 6.3 Acciones masivas

- En proyectos:
  - seleccionar visibles
  - limpiar seleccion
  - mover seleccionados a papelera
- En papelera:
  - seleccionar visibles
  - limpiar seleccion
  - restaurar seleccionados
  - eliminar definitivamente seleccionados

La UI informa resultados de acciones masivas con resumen de exitos y fallos.

## 7. Gestion de credenciales por proyecto

Cada proyecto puede tener entradas nombre/password en pass/<proyecto>.json.

Estado actual de seguridad:

- Passwords guardadas cifradas en reposo usando sodium secretbox.
- Clave de cifrado en .pass_key.bin.
- Compatibilidad con archivos antiguos en texto plano:
  - al leer, migra a formato cifrado cuando detecta esquema anterior.
- Desde UI:
  - agregar credencial
  - actualizar password
  - eliminar credencial
  - copiar password al portapapeles

## 8. Render de README seguro

El backend procesa README.md con Parsedown en modo seguro y aplica sanitizacion defensiva adicional de HTML renderizado.

Objetivo:

- reducir riesgo de inyeccion de etiquetas o atributos peligrosos
- mantener una vista legible dentro del modal de archivos

## 9. Carpetas protegidas

El backend bloquea operaciones sobre carpetas criticas (segun regla de proteccion), por ejemplo:

- img
- pass
- _PAPELERIA
- dashboard
- xampp
- webalizer

Tambien se restringen nombres peligrosos o no validos y ciertos sufijos sensibles en operaciones de gestion.

## 10. UI y UX actuales

- Interfaz redisenada con Tailwind.
- Sistema de modales unificado.
- Cierre de modales por ESC y clic fuera.
- Dialogos custom para confirmar/pedir datos.
- Toasters para feedback no bloqueante.
- Tooltips visuales para extensiones.
- Barras de seleccion y acciones masivas por tab.

## 11. Endpoints principales (api.php)

### 11.1 Estado y seguridad

- GET action=auth_status
- POST action=auth_setup
- POST action=auth_login
- POST action=auth_logout
- POST action=auth_get_security
- POST action=auth_update_smtp
- POST action=auth_request_reset
- POST action=auth_reset_password

### 11.2 Inicializacion y sistema

- GET action=init
- GET action=get_php_ini
- POST action=save_php_ini
- POST action=refresh_metrics

### 11.3 Proyectos y papelera

- POST action=create_project
- POST action=move
- POST action=bulk_move
- POST action=list_trash
- POST action=restore_project
- POST action=bulk_restore
- POST action=delete_permanently
- POST action=bulk_delete_permanently

### 11.4 Archivos y README

- POST action=list_files

### 11.5 Credenciales por proyecto

- POST action=list_passwords
- POST action=save_passwords
- POST action=update_password
- POST action=delete_password

## 12. Instalacion rapida (XAMPP Windows)

1. Coloca los archivos en C:/xampp/htdocs o en la carpeta que quieras administrar.
2. Inicia Apache en XAMPP.
3. Abre http://localhost/.
4. Si es primera vez, completa setup de seguridad.
5. Configura SMTP si usaras recuperacion por correo.

## 13. Solucion de problemas

- Error SMTP de conexion:
  - valida host, puerto y cifrado
  - para Gmail usa normalmente 587 + tls o 465 + ssl
  - verifica salida de red/firewall del servidor Apache/PHP
- No deja editar php.ini:
  - revisa permisos de escritura del archivo
  - reinicia Apache despues de guardar
- No aparece un proyecto:
  - revisa que no sea carpeta protegida/excluida
- Fallo de autenticacion o bloqueo:
  - espera fin del lockout o reinicia guardas desde configuracion

## 14. Notas operativas

- Este panel sigue orientado a entorno local o intranet controlada.
- Si se publica en red abierta, se recomienda reforzar:
  - HTTPS real
  - control de IP
  - rotacion de secretos
  - monitoreo y auditoria de eventos

## 15. Historial de cambios recientes (resumen)

- Migracion UI a Tailwind y modales custom.
- Sistema de papelera completo con restaurar y borrado definitivo.
- Cache inteligente de metricas con invalidacion automatica.
- Autenticacion por modos local/red, CSRF y lockout.
- Recuperacion de clave por correo SMTP y panel de configuracion SMTP.
- Sanitizacion reforzada del README renderizado.
- Restriccion de carpetas criticas.
- Cifrado en reposo para credenciales por proyecto.
- Acciones masivas en proyectos y papelera.
