# Guía de instalación y operación

## Estado y requisitos

**Sin publicar.** Aún no hay una versión del módulo autorizada para instalación en producción. Este procedimiento sirve para preparar la instalación de una futura entrega identificada y validada. No sustituye la aceptación sobre el entorno de Grupo Dico.

| Requisito | Comprobación |
|---|---|
| Magento Open Source 2.4.9 | Confirmar versión, parches y requisitos de infraestructura de esa distribución |
| PHP 8.3 | Confirmado para el servidor de pruebas del cliente; registrar el parche de PHP web, CLI y consumers. El paquete requiere `~8.3.0` |
| Base de datos del destino | Motor y versión pendientes de confirmación; no asumir que coinciden con el laboratorio |
| `Bss_CustomProductAttributeExport` | Debe estar instalado, habilitado y ser compatible con la implementación y parches verificados; se suministra por separado |
| Módulos Magento requeridos | Catalog, CatalogImportExport, ImportExport, ProductVideo y Store, según `composer.json` y sus dependencias |
| Cola nativa de exportación | Transporte configurado y mecanismo operativo para ejecutar `exportProcessor` |
| Filesystem | Permisos del usuario de Magento y capacidad para temporales y archivos finales de exportación |
| Administración | Rol de prueba autorizado para exportar y otro sin ese permiso para verificar acceso |

La [matriz de compatibilidad](guia-api-y-compatibilidad.md#plataforma-y-verificación) distingue el destino de pruebas confirmado, las verificaciones parciales y el runtime adicional PHP 8.5. La combinación completa con Magento 2.4.9 debe contrastarse con sus requisitos oficiales y probarse en el destino; esta documentación no certifica soporte oficial del stack. No omitas requisitos de plataforma de Composer para convertir una instalación no compatible en una instalación aceptada.

No se necesita una configuración de negocio nueva, claves de proveedores de video, atributos EAV adicionales ni un cron propio del módulo.

El acceso usa el recurso nativo `Magento_ImportExport::export`. La pantalla, la protección del formulario y la descarga conservan sus controles de Magento; no se añade una ACL alternativa para esta función.

## Preparar una instalación futura

El responsable del entorno debe disponer del paquete de la versión aprobada, su documentación, la identificación de sus dependencias y el manifiesto de entrega. Verifica la integridad de los artefactos recibidos antes de incorporarlos al despliegue. No instales una carpeta temporal de desarrollo como si fuera una versión publicada.

Ensaya primero en un entorno representativo del destino. Registra versiones de PHP, Magento, Bss y parches, transporte de cola, modo de Magento y proceso de despliegue utilizado. Revisa conflictos con otros módulos que reemplacen el exportador de productos, sus cabeceras o el adaptador CSV.

Para una instalación o actualización productiva futura, acuerda una ventana y un plan de recuperación con el responsable de la plataforma. El respaldo y su recuperación deben cubrir el estado que pueda modificar el despliegue completo. Drena o pausa de forma controlada los trabajos de exportación afectados mediante el gestor de procesos existente; no elimines mensajes ni detengas servicios compartidos sin coordinación.

## Incorporar el paquete

La instalación recomendada es mediante Composer, utilizando el repositorio privado o de artefactos autorizado para la entrega. El paquete se llama `grupodico/module-product-video-export`. No se presupone una URL pública ni un nombre Composer para la dependencia Bss.

Cuando exista una versión aprobada, el responsable incorpora esa versión concreta al `composer.json` y al archivo de bloqueo de la aplicación mediante el procedimiento habitual de construcción. Por ejemplo, la forma de la operación es la siguiente; el marcador debe sustituirse por una versión realmente entregada:

```text
composer require grupodico/module-product-video-export:<versión-aprobada>
```

Construye y valida las dependencias en el proceso de despliegue del proyecto. En el destino, instala el archivo de bloqueo aprobado; no resuelvas versiones nuevas de forma improvisada. Conserva `Bss_CustomProductAttributeExport` y sus parches existentes.

## Activar e integrar con Magento

Ejecuta las operaciones de Magento como su usuario de aplicación y con el PHP 8.3 validado para el destino. Los comandos siguientes usan `php` únicamente si ese ejecutable resuelve a la versión prevista. Aplica el mantenimiento, compilación y despliegue atómico habituales de la instalación.

1. Comprueba los módulos requeridos y Bss antes de activar el nuevo módulo:

   ```text
   php bin/magento module:status Bss_CustomProductAttributeExport Magento_CatalogImportExport Magento_ProductVideo
   ```

2. Habilita el módulo e integra el registro de módulos con el procedimiento nativo:

   ```text
   php bin/magento module:enable LeanCommerce_ProductVideoExport
   php bin/magento setup:upgrade
   ```

   El módulo no incorpora esquema ni parches de datos propios. `setup:upgrade` forma parte del despliegue nativo y puede ejecutar cambios pendientes de otros módulos; revisa previamente el estado de toda la aplicación. No lo ejecutes aisladamente en producción sin el procedimiento acordado.

3. En modo producción, recompila la inyección de dependencias e incorpora los estáticos de Administración y los idiomas activos mediante el proceso de despliegue habitual. El módulo aporta traducción `es_MX` y no añade una aplicación JavaScript propia.

   ```text
   php bin/magento setup:di:compile
   ```

4. Actualiza las cachés afectadas conforme al procedimiento de la aplicación. No es necesario vaciar globalmente Redis, borrar sesiones o reconstruir índices de catálogo por este módulo.
5. Reinicia los procesos de larga duración afectados para cargar el código nuevo y reanuda el procesamiento coordinado de la cola. Retira el mantenimiento cuando las verificaciones del despliegue sean correctas.

No copies archivos sobre Magento o Bss y no modifiques `vendor/` manualmente. Si el paquete no resuelve, falta Bss o la compilación detecta incompatibilidad, conserva el diagnóstico y corrige la dependencia antes de continuar.

## Verificar después de instalar

En Administración, abre la exportación de Productos y comprueba las opciones **Todos**, **Con video** y **Sin video**. Exporta un conjunto controlado con base y tiendas que incluyan videos heredados, cambios locales, títulos vacíos y ausencia de videos.

Comprueba la notificación, el archivo publicado y su descarga. Verifica las cuatro cabeceras al final y los valores frente al catálogo en cada contexto. Incluye un caso cuyo único cambio de tienda sea el video, un filtro sin coincidencias y un lote inicial sin resultados seguido de coincidencias posteriores.

También verifica una integración existente que omita la clave nueva: debe conservar su exportación anterior. Comprueba que un rol sin permiso de exportación no obtenga acceso por la instalación del módulo. Completa la [lista de producción](checklist-produccion.md) antes de declarar disponible la versión.

## Cola y operación diaria

Se reutilizan el topic `import_export.export`, la cola `export` y el consumer `exportProcessor`. El módulo no añade otro worker. Los ejecuta el mecanismo de cron o supervisión que ya opere la instalación.

El trabajo calcula los videos cuando se procesa, no cuando el usuario pulsa Exportar. El mensaje inicial confirma que se encoló. La notificación de archivo listo y el archivo final corresponden a la publicación nativa de Magento.

Para atender un retraso, revisa el estado del gestor de consumers, el transporte configurado y los registros de Magento. Iniciar manualmente un consumer puede procesar otros trabajos pendientes: utiliza el procedimiento del entorno y una ventana coordinada, sin purgar la cola.

Los archivos finales siguen el directorio `export/` bajo la ruta nativa `DirectoryList::VAR_IMPORT_EXPORT`; en la instalación verificada corresponde a `var/export/`. Confirma esa ruta en el destino y respeta cualquier configuración propia de Magento. Aplica la política de acceso y retención existente: contienen datos de catálogo exportados por los usuarios. El módulo limpia únicamente sus temporales conocidos; no elimina automáticamente los archivos finales ni exportaciones ajenas.

## Diagnóstico

| Síntoma | Revisión recomendada |
|---|---|
| Solicitud en cola sin archivo final | Consumer, cron o supervisor, transporte, espacio y permisos; distinguir espera de error de ejecución |
| Opción de videos ausente | Entidad Productos, módulo habilitado, configuración y cachés del despliegue |
| Error de parámetro de presencia | Enviar exactamente `all`, `with` o `without`; revisar también `exportFilter` en integraciones con DTO |
| Error por colisión de columnas | Localizar qué personalizador ya aporta `has_video`, `video_count`, `video_urls` o `video_titles`; corregir la integración sin sobrescribir campos |
| Error de contexto o lote incompleto | Revisar las versiones de Magento/Bss y datos de galería; no sustituir la tienda por otra ni emitir ceros como recuperación |
| Archivo temporal existente | Verificar propiedad y estado del trabajo previo; el módulo no trunca un temporal preexistente |
| Fallo de limpieza o publicación | Revisar permisos y almacenamiento y localizar el temporal exacto del trabajo; no borrar por patrones ni eliminar un archivo final |
| Sin datos para exportar | Revisar filtros y contexto; no es un CSV correcto de solo cabeceras |
| Archivo aparentemente incompleto en Excel/Sheets | Comparar con un lector CSV y comprobar límites de la aplicación antes de atribuir truncamiento al exportador |

Al reportar un fallo, registra versión desplegada, hora, entidad, modo de presencia, filtros, canal y mensaje. Consulta los destinos de logging configurados en Magento, normalmente `var/log`, con permisos adecuados. Evita copiar credenciales, archivos de configuración privados, filas completas o URL/títulos sensibles en tickets.

Una ejecución fallida no debe considerarse aceptada porque exista un temporal. Corrige la causa y solicita un nuevo intento; el módulo no reintenta automáticamente ni garantiza contenido idéntico si el catálogo cambió. No modifica los productos para reparar un error de exportación.

## Actualización y reversión

Para actualizar, ensaya el nuevo paquete y las dependencias sobre el stack objetivo, repite la verificación de las integraciones y ejecuta el despliegue nativo con una versión bloqueada. Mantén juntos el código validado, configuración y documentación de cada entrega.

Para revertir una actualización, restaura el artefacto y archivo de bloqueo de la versión anterior mediante el procedimiento de despliegue de la aplicación. Recompila los elementos necesarios, actualiza las cachés afectadas y reinicia los consumers coordinadamente. Si se trata de la primera instalación, deshabilita el módulo y retira el paquete mediante el proceso de dependencias; conserva Bss.

La desactivación devuelve la entidad al exportador que quede configurado por Magento/Bss, según el orden de módulos de la instalación. Verifica ese resultado. El módulo no tiene tablas ni atributos propios que eliminar, y su reversión no requiere borrar catálogo o galería. Los CSV ya publicados permanecen sujetos a la retención nativa.

Drena o identifica los mensajes pendientes antes de retirar una versión: solicitudes creadas con la opción de videos no deben quedar mezcladas sin control con workers que ya no tengan esa función. No borres trabajos ajenos para completar la reversión.
