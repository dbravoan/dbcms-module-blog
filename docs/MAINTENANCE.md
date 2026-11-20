# Mantener Blog

## Arquitectura y reutilización

`BlogModuleServiceProvider` registra los esquemas de `BlogEntitySchemas`, las
rutas, `BlogPostsBlock`, las fuentes públicas y las contribuciones semánticas.
Para una mutación, parte de `PostMutationHandler` o `CategoryMutationHandler`
y del servicio de dominio. Core aplica el flujo compartido de entidad y
secciones. Para una consulta pública, parte de `BlogArticleReader`,
`BlogCatalogueReader` o `PublicContentDiscovery` según la entrada.

Usa los componentes `x-admin.*` de Core para pantallas nuevas y conserva las
vistas públicas en el módulo o en el override del tema. Sigue el lector, el
esquema y los tests equivalentes antes de extender el contrato. [Convenciones de Core](https://github.com/dbravoan/dbcms-core/blob/main/docs/CONVENTIONS.md)

## Privacidad de comentarios

El lector público solo entrega comentarios y respuestas aprobados. `blog::partials.comments` es el propietario del listado y formulario, con consentimiento, captcha configurado y acción localizada. Los temas incluyen este parcial en lugar de duplicar reglas.

Para conservar o atender derechos sobre comentarios, usa `php artisan blog:privacy`.
La acción `prune` aplica la conservación configurada. `export` y `erase`
requieren IDs explícitos con `--id` y `--verified` tras comprobar identidad,
alcance y restricciones de conservación. El módulo gestiona estos datos durante
su ciclo de disponibilidad.

## Composiciones de prueba

El perfil `default` cubre el ciclo de vida y los contratos propios. `integration`
añade las composiciones de módulos y temas declaradas en `tests/package.php`.

Desde la raíz de Blog, ejecuta el tooling con Docker Compose y la base
desechable del paquete.

```bash
docker compose run --build --rm tools composer prepare
docker compose run --rm tools composer test
docker compose run --rm tools composer check
docker compose run --rm tools composer test:dist
```

Usa `composer profiles` para enumerar perfiles y `DBCMS_TEST_PROFILE=integration`
para seleccionar integración. Regenera los locks con `composer fixture:lock`
cuando cambien las dependencias de prueba. [Pruebas de Core](https://github.com/dbravoan/dbcms-core/blob/main/docs/TESTING.md)

Para navegador, usa una aplicación desechable con el marcador
`.dbcms-browser-disposable` que contiene `dbcms-module-browser` y una base
`dbcms_browser_*`. Ejecuta `tests/Browser/fixtures/bootstrap.php` con
`DBCMS_BROWSER_FIXTURE=1` y las variables de la aplicación y MySQL.
En un entorno con Playwright, define `DBCMS_BROWSER_MODULE=1`,
`DBCMS_BROWSER_BRANDS` con las URLs de `dbcms` y `termocms` en JSON y
`DBCMS_BROWSER_FIXTURES` con la ruta al JSON de datos del fixture. Ejecuta
`npx playwright test --config tests/Browser/playwright.config.mjs`.

## Publicar

Actualiza el changelog, verifica el SHA del candidato y el distribuible, y
publica un tag del módulo. Comprueba una instalación resuelta por Composer.
