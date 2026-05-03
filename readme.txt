=== ZoeCloud ===
Contributors: zoecloud
Tags: backup, restore, cloudflare r2, aws s3, wordpress backup, migration
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backups portables de WordPress con restauracion segura, descargas locales y subida a Cloudflare R2 o AWS S3.

== Description ==

ZoeCloud crea copias de seguridad portables de WordPress incluyendo archivos, base de datos y metadata de restauracion.

El plugin esta disenado para sitios reales: procesa backups por etapas, muestra progreso, conserva copias locales, permite descarga directa, valida backups antes de restaurar y puede subir archivos a Cloudflare R2 o AWS S3 usando credenciales S3-compatible.

Funcionalidades actuales:

* Crear backups manuales desde el panel de ZoeCloud.
* Incluir `/wp-content/` y, opcionalmente, archivos core de WordPress.
* Exportar la base de datos a `database.sql`.
* Generar `manifest.json` con version, dominio, URLs, archivos y tablas.
* Descargar backups ZIP portables.
* Restaurar backups existentes con reemplazo de URL.
* Validar backups antes de restaurar.
* Eliminar copias locales desde el dashboard y borrar objetos cloud subidos.
* Ejecutar backups programados con WP-Cron.
* Aplicar limite de retencion local y cloud.
* Subir backups a Cloudflare R2 o AWS S3.
* Guardar secretos cifrados en opciones de WordPress.

Estructura del ZIP:

`
/files/
/database.sql
/manifest.json
`

Los nombres de archivo siguen este formato:

`
zoe-cloud-backup-{dominio}-{YYYY-MM-DD-HH-mm}.zip
`

== Requirements ==

* WordPress 6.4 o superior.
* PHP 7.4 o superior.
* Extension PHP `ZipArchive`.
* Directorio de uploads escribible.
* WP-Cron activo para backups programados y procesamiento en segundo plano.
* Acceso saliente a internet si se usa almacenamiento cloud.

== Installation ==

1. Copia la carpeta `zoe-cloud` en `/wp-content/plugins/`.
2. Activa el plugin desde `Plugins > Installed Plugins`.
3. Abre el menu `ZoeCloud` en el admin de WordPress.
4. Revisa el bloque de preflight para confirmar que el entorno puede crear backups.
5. Configura Cloudflare R2 o AWS S3 si quieres subida cloud.
6. Crea tu primera copia con `Create Backup`.

== Cloud Storage Setup ==

Selecciona el proveedor activo en `ZoeCloud > Storage`.

= Cloudflare R2 =

Para subir backups a R2 necesitas:

* R2 Account ID.
* R2 Access Key ID.
* R2 Secret Access Key.
* Nombre del bucket.
* Prefix opcional. Dejalo vacio para guardar cada sitio directamente en la raiz del bucket.

Pasos recomendados:

1. En Cloudflare, crea un bucket R2.
2. Crea credenciales S3 API con permiso de escritura sobre ese bucket.
3. En WordPress, abre `ZoeCloud > Storage`.
4. Pega `Account ID`, `Access Key ID`, `Secret Access Key` y `Bucket`.
5. Guarda los cambios.
6. Activa `Upload to cloud storage` al crear un backup.

Endpoint usado por ZoeCloud:

`
https://{account_id}.r2.cloudflarestorage.com
`

Region usada para firma S3:

`
auto
`

= AWS S3 =

Para subir backups a AWS S3 necesitas:

* S3 Access Key ID.
* S3 Secret Access Key.
* Nombre del bucket.
* Region del bucket, por ejemplo `us-east-1`.
* Prefix opcional. Dejalo vacio para guardar cada sitio directamente en la raiz del bucket.

Endpoint usado por ZoeCloud:

`
https://{bucket}.s3.{region}.amazonaws.com
`

== Backup Workflow ==

El backup se procesa por etapas para evitar timeouts:

1. Inicializa el job.
2. Exporta tablas de base de datos por lotes.
3. Escanea archivos.
4. Agrega archivos al ZIP por lotes.
5. Agrega `database.sql` y `manifest.json`.
6. Registra la copia local.
7. Sube al proveedor cloud seleccionado si esta configurado.
8. Limpia archivos temporales.

La barra de progreso muestra el estado activo y permanece visible unos segundos al finalizar.

== Restore Workflow ==

El sistema de restauracion permite:

* Seleccionar un backup existente.
* Validar estructura del ZIP.
* Revisar origen, numero de archivos y filas de base de datos.
* Restaurar archivos y tablas.
* Reemplazar URL de origen por URL destino.
* Conservar el registro de backups de ZoeCloud despues de restaurar.

Antes de restaurar, ZoeCloud requiere confirmacion explicita porque la operacion puede sobrescribir archivos y base de datos.

== Security ==

ZoeCloud aplica:

* Capability checks con `manage_options`.
* Nonces para acciones administrativas.
* REST API protegida con nonce de WordPress.
* Cifrado de secretos mediante salts de WordPress.
* Validacion de rutas para evitar path traversal en restauracion.
* Proteccion basica del directorio local de backups contra listado directo.

Recomendacion: usa credenciales cloud con permisos limitados al bucket de backups.

== Frequently Asked Questions ==

= Puedo usar ZoeCloud en local con DDEV? =

Si. Los backups locales funcionan sin servicios externos. La subida a Cloudflare R2 o AWS S3 tambien funciona desde local mientras el contenedor tenga salida a internet.

= Necesito OAuth para Cloudflare R2? =

No. R2 usa credenciales S3-compatible, por eso no requiere redirect URI ni dominio publico.

= Google Drive sigue siendo parte del plan? =

Si. Drive queda como integracion futura. Para una experiencia de un clic en sitios locales se necesitara un OAuth broker publico.

= ZoeCloud elimina la copia despues de restaurar? =

No. Las copias se conservan despues de restaurar. Si quieres borrarlas, usa el boton `Delete` en la tabla de backups.

= Donde se guardan las copias locales? =

En el directorio de uploads de WordPress, dentro de:

`
wp-content/uploads/zoecloud-backups/
`

== Changelog ==

= 0.1.0 =

* Backup manual y programado.
* Procesamiento por etapas.
* Exportacion de base de datos.
* Backup ZIP portable.
* Descarga local.
* Restauracion con validacion y reemplazo de URL.
* Eliminacion de backups locales.
* UI administrativa con progreso.
* Integracion inicial con Cloudflare R2 y AWS S3.

== Roadmap ==

* OAuth broker para Google Drive.
* Integraciones S3 compatibles adicionales.
* Restauracion desde subida manual de ZIP.
* Backups incrementales.
* Dashboard SaaS.
* Multisite.
