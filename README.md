# Product Video Export

Módulo de Grupo Dico para exportar datos de videos de producto en el CSV nativo multitienda de Magento.

Añade `has_video`, `video_count`, `video_urls` y `video_titles`, con los filtros **Todos**, **Con video** y **Sin video**. Calcula los videos habilitados y sus valores heredados por tienda. Conserva los campos, permisos, cola, descarga y escritura CSV de Magento y la integración con Bss.

**Estado: código fuente para validación; sin versión publicada.** Las pruebas de laboratorio han sido confirmadas como satisfactorias. La siguiente validación corresponde al servidor de pruebas de Grupo Dico; todavía no hay aceptación en ese entorno ni en producción.

## Requisitos

- Magento Open Source **2.4.9** y PHP **8.3** (`~8.3.0`), objetivo confirmado para el servidor de pruebas del cliente.
- `Bss_CustomProductAttributeExport`, instalado y habilitado, suministrado por separado.
- Dependencias Magento declaradas en `composer.json` y flujo nativo de exportación operativo.

El motor y la versión de base de datos del destino siguen por confirmar. La [matriz de compatibilidad](docs/guia-api-y-compatibilidad.md#plataforma-y-verificación) distingue el objetivo del cliente, las pruebas realizadas y los requisitos oficiales de la plataforma. No se declara aceptado ni oficialmente soportado el stack completo por su coincidencia con el laboratorio. PHP 8.5 se ha utilizado como entorno adicional de herramientas; no es el objetivo exclusivo de la entrega.

## Uso e instalación

En Administración, selecciona **Sistema → Transferencia de datos → Exportar → Productos → CSV** y elige **Presencia de video**. En **Todos**, cuatro celdas vacías pueden representar herencia; **Con video** y **Sin video** muestran el bloque explícito de cada contexto aceptado. Consulta el [manual de usuario](docs/manual-de-usuario.md) antes de interpretar el archivo.

El paquete Composer es `grupodico/module-product-video-export` y el módulo es `LeanCommerce_ProductVideoExport`. Para una futura versión entregada, sigue la [guía de instalación y operación](docs/guia-instalacion-y-operacion.md) y completa la [verificación de producción](docs/checklist-produccion.md). La distribución no crea atributos ni tablas y no edita el catálogo.

Las peticiones programáticas sin el parámetro nuevo mantienen la exportación anterior. El contrato completo de parámetros, CSV y dependencias está en la [guía de API y compatibilidad](docs/guia-api-y-compatibilidad.md); la [arquitectura técnica](docs/arquitectura-tecnica.md) explica los componentes y su ciclo de archivos.

El módulo no importa videos, verifica proveedores externos ni mide reproducciones. La serialización conserva el tratamiento nativo de Magento; no se promete reimportación ni comportamiento universal de fórmulas en hojas de cálculo.

## Documentación y propiedad

El [índice de documentación](docs/README.md), el [historial de cambios](CHANGELOG.md) y las [condiciones de propiedad](LICENSE.md) acompañan al código. El código, pruebas y documentación pertenecen a **Grupo Dico**.

## Desarrollo y pruebas

El repositorio fuente es [Grupo-Dico/module-product-export-videos](https://github.com/Grupo-Dico/module-product-export-videos). Su nombre no cambia el identificador Composer `grupodico/module-product-video-export` ni el módulo Magento.

La [guía de desarrollo y pruebas](docs/desarrollo-y-pruebas.md) describe el entorno de calidad reproducible, la CI y los requisitos de la integración. Las herramientas se instalan en `tests/tooling`; Magento y Bss se suministran en el entorno de integración.
