# Blog

Publica artículos por idioma y mercado, organízalos en categorías y configura los comentarios. El bloque **Artículos del Blog** reutiliza el contenido publicado en páginas y otras composiciones.

`dbravoan/dbcms-module-blog` se instala en una aplicación dbCMS. Composer
resuelve los requisitos que declara el paquete.

## Instalación y ciclo de vida

Desde la raíz de una aplicación dbCMS:

```bash
composer require dbravoan/dbcms-module-blog:~0.0.1
```

En **Sistema y ajustes → Módulos del sistema**, pulsa **Instalar y activar**
en **Blog Engine**. El panel aplica sus migraciones y carga sus contribuciones.
Desactivar conserva los datos y retira las contribuciones públicas.

Para una aplicación nueva también puedes seleccionarlo con
`dbcms new mi-web --modules=blog`. La configuración inicial del sitio se completa
en `/install`.

## Crear y publicar artículos

1. En **Blog → Categorías**, crea la categoría y completa su nombre y slug por idioma. Actívala para mostrarla en la navegación del Blog.
2. En **Blog → Artículos del blog**, elige categoría y mercados. Completa título, slug y resumen por idioma. Añade secciones con bloques del catálogo y usa la vista previa para comprobarlas.
3. Guarda el borrador con la publicación desactivada, o sigue el flujo de publicación de la web. Comprueba la fecha y la URL pública en el contexto elegido. La ruta base se configura en los ajustes de Blog y su valor por defecto es `/blog`.

Para crear borradores mediante MCP, usa un token personal con acceso de escritura
y una cuenta con `manage:posts`. Descubre `post` y `blog_category` con
`cms_entity_types` y envía el cuerpo en `sections_json`. [Guía paso a paso](https://github.com/dbravoan/dbcms-installer/blob/main/docs/HOW_TO_MCP.md)

## Mostrar artículos en otra página

Añade **Artículos del Blog** a una sección propia. Elige recientes, destacados,
una categoría o un artículo concreto. El bloque `posts` consulta el contenido
publicado del idioma y mercado actuales. Usa la marca **Destacado** del artículo
para incluirlo en una lista de destacados. [Ejemplo de portada](https://github.com/dbravoan/dbcms-installer/blob/main/docs/HOW_TO_BUILD_HOMEPAGE_MCP.md)

## Moderar comentarios

En los ajustes del módulo, elige si admites comentarios, si exiges aprobación
y si añades CAPTCHA. Usa **Blog → Comentarios** para aprobar o eliminar envíos.
El formulario y sus respuestas públicas comparten las reglas de publicación
y el tratamiento `blog_comments`. [Privacidad y pruebas](docs/MAINTENANCE.md)

## Desarrollo y mantenimiento

Consulta [arquitectura del módulo, reutilización y pruebas](docs/MAINTENANCE.md).
