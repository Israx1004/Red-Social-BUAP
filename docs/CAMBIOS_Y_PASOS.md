# UniLink BUAP — cambios recientes y cómo ejecutar / modificar

## Cómo ejecutarlo (XAMPP)
1. Copia la carpeta del proyecto a `C:\xampp\htdocs\RedSocial_BUAP` (el nombre debe ser exacto).
2. En XAMPP inicia **Apache** y **MySQL**.
3. En `http://localhost/phpmyadmin` importa `red_social.sql` (una sola vez). La tabla de mensajes se crea sola.
4. Abre `http://localhost/RedSocial_BUAP/` (Ctrl+F5 para limpiar caché).
5. Datos de conexión: `backend/shared/helpers.php` (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`).

## Qué se agregó/corrigió
| Función | Archivos principales |
|---|---|
| Marketplace: detalle con foto, precio y descripción; subida de fotos | `frontend/js/marketplace.js`, `frontend/css/marketplace.css`, `backend/microservices/marketplace/marketplace_controller.php` |
| Objetos perdidos: publicar/filtrar/contactar | `frontend/pages/lost-found.php`, mismo controlador de marketplace |
| Perfil: info, foto y publicaciones; seguir; foto de perfil | `frontend/js/profile.js`, `frontend/pages/profile.php`, `users_controller.php`, `feed_model.php` (filtro `author`) |
| Botón de pánico → notificación a admins | `frontend/js/utils.js` (`triggerPanic`), `moderation_controller.php` (`POST moderation/panic`) |
| Chat (mensajes directos) | `frontend/pages/messages.php`, `frontend/js/messages.js`, `frontend/css/messages.css`, `backend/microservices/messages/messages_controller.php` |

## Cómo modificar cosas comunes
- **Cambiar quién recibe la alerta de pánico**: en `moderation_controller.php`, la consulta `WHERE role='admin'` (agrega `OR role='moderator'`).
- **Frecuencia de actualización del chat**: `setInterval(..., 4000)` en `frontend/js/messages.js` (milisegundos).
- **Frecuencia de la campana de notificaciones**: `setInterval(..., 15000)` en `frontend/js/notifications.js`.
- **Carpeta de fotos subidas**: `RedSocial_BUAP/storage/` (se crea sola). Ruta configurable en `STORAGE_PATH`/`STORAGE_URL` de `helpers.php`.
- **Ver mensajes / alertas en la base**: tablas `messages`, `panic_alerts`, `notifications` (phpMyAdmin → `red_social`).

## Revisión general (seguridad y bugs)
- **Una sola vez, si ya tenías datos:** `C:\xampp\php\php.exe database\migrate_unescape.php` (simulación) y luego con `--apply`. Quita las entidades `&quot;` que se guardaban antes.
- **Secreto JWT:** se genera solo en `backend/shared/.jwt_secret`. Para Docker, defínelo en `.env` (`JWT_SECRET`). Al cambiar el secreto, todas las sesiones anteriores se cierran.
- **Archivos subidos:** se valida el tipo real y `storage/.htaccess` impide ejecutar código ahí.
- **Se eliminaron** los scripts públicos de diagnóstico (`debug_login.php`, `fix_passwords.php`, `test_*.php`, …).
- **Páginas nuevas:**
  - `moderation.php` (moderadores y admins)
  - `admin.php` (solo admins)
  - `group-detail.php`
  - `forgot-password.php` y `reset-password.php`
- **Recuperar contraseña sin servidor de correo:** en desarrollo, y abriendo la app desde la misma PC, la página muestra el enlace directamente. En producción lo envía `backend/queue/worker.php` (configura SMTP en `.env`).
- **Tiempo real (opcional):** `cd websocket-server && npm install && node server.js`. Toma el mismo secreto de `.jwt_secret`. Si no está corriendo, la app funciona igual.
- **Docker:** `cd docker && docker compose --env-file ../.env up -d --build` y abre `http://localhost:8080/RedSocial_BUAP/`.
- `database/setup_xampp.sql` está **obsoleto**: usa `red_social.sql`.
