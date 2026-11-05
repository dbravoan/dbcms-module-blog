# dbravoan/dbcms-module-blog 1.0.0

## Qué hace

`dbravoan/dbcms-module-blog` aporta artículos de blog, categorías y comentarios. Instala/activa `blog` y configura slugs y comentarios.

El detalle operativo de comentarios y derechos está en Privacidad y datos.

## Requisitos y dependencias

- `dbravoan/dbcms-core` `^1.0`
- Identificador CMS: `blog`

## Instalación y activación

Instala el paquete con Composer e instálalo/actívalo en el CMS (`blog`).

## Configuración

Ajustes del módulo (claves por nombre):

- `comments_enabled`
- `comments_require_approval`
- `slug_default`
- `slug_{locale}`

## Rutas

Rutas admin/front de posts y comentarios en `Routes/` y el ServiceProvider (pageable).

## Comandos Artisan

- `blog:privacy` — firma: `blog:privacy {action : export, erase or prune} {--id=* : Explicit comment IDs} {--verified : Identity, scope and retention restrictions have been checked}`

## Permisos

- `manage:posts`

## Privacidad y datos

Antes de recoger comentarios declara `legal.processing` para `blog_comments`:
finalidad, base jurídica, datos, destinatarios/transferencias y `retention_days`
positivo desde el envío. Los formularios incluidos muestran el aviso compartido;
el servicio de envío exige la misma configuración.

Ejecuta `./vendor/bin/sail artisan blog:privacy export --id=123 --verified` o
`erase` tras verificar identidad y alcance. Los exports se guardan localmente en
privado; elimínalos después de atender la solicitud. `--verified` confirma una
revisión previa del operador y no la realiza. `blog:privacy prune` diario aplica
el plazo declarado también a datos del módulo inactivo.

Borrar un padre conserva respuestas ajenas como comentarios raíz e invalida la
caché HTML pública. Revisa aparte datos personales citados por otros autores.
Las notificaciones conservan IDs, sin copiar contacto. Reasigna posts antes de
borrar la cuenta de su autor: la protección también rige estando Blog inactivo.
Artículos y medios publicados son registros editoriales y usan los controles de
publicación/retirada de su propietario.

## Enlaces

- [Extensiones](../../../docs/EXTENSIONS.md)
- [Núcleo y extensiones](../../../specs/CORE_AND_EXTENSIONS.md)
- [README del núcleo](../../../packages/core/README.md)
- [README del monorepo](../../../README.md)
