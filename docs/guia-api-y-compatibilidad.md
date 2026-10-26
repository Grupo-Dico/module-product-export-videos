# API y compatibilidad

## Alcance del contrato

El módulo `LeanCommerce_ProductVideoExport`, paquete `grupodico/module-product-video-export`, amplía la entidad nativa `catalog_product` para exportación CSV. No añade endpoints REST o GraphQL, rutas públicas, servicios anónimos ni un importador. Administración, autenticación, permisos, transporte de cola y descarga siguen perteneciendo a Magento.

Las integraciones que consumen la exportación pueden solicitar las cuatro columnas mediante el parámetro documentado a continuación. La ausencia del parámetro conserva el comportamiento anterior.

## Solicitud de enriquecimiento

En los parámetros del modelo/formulario nativo:

```text
entity = catalog_product
file_format = csv
export_filter[lc_product_video_export_presence] = all | with | without
```

El valor debe ser una cadena exacta:

| Valor | Resultado |
|---|---|
| `all` | Todos los contextos nativos, con representación diferencial del bloque de videos |
| `with` | Solo contextos cuyo `video_count` efectivo sea mayor que cero; bloque explícito |
| `without` | Solo contextos cuyo `video_count` efectivo sea cero; bloque explícito |
| Clave ausente | Exportación anterior, sin añadir columnas ni activar el procesamiento de videos |

La pantalla nueva envía `all` explícitamente. Una petición antigua que omite la clave no equivale a esa selección de la pantalla.

El transporte nativo también representa el grupo como `exportFilter` en los datos del DTO. Se admite la clave dentro de ese grupo. Si se proporcionan ambos grupos, sus valores de presencia deben coincidir. `null`, una cadena vacía, arrays y valores distintos de los tres admitidos son inválidos; no se convierten silenciosamente a `all`.

El parámetro no introduce un filtro SQL de productos por presencia. Primero se conserva el conjunto de productos y contextos determinado por los filtros nativos; después se evalúan sus videos. Entidades distintas de `catalog_product` conservan su comportamiento.

Las integraciones deben usar el mecanismo nativo para crear solicitudes y DTO. No deben reconstruir mensajes serializados ni publicar archivos por una ruta alternativa para habilitar la función. No existe un nuevo comando CLI de exportación de este módulo.

## Esquema CSV

Las cuatro columnas se añaden al final de las cabeceras devueltas por el exportador padre y sus personalizaciones. Su posición absoluta y la cantidad total de columnas dependen de la instalación. Un consumidor debe localizar columnas por nombre, sin fijar un número de columna.

| Nombre | Tipo lógico cuando el bloque está presente | Regla |
|---|---|---|
| `has_video` | Entero `0` o `1` | Es `1` exactamente cuando `video_count > 0` |
| `video_count` | Entero no negativo | Número de asociaciones habilitadas de tipo video externo |
| `video_urls` | Cadena de lista | Una posición por asociación, aun cuando falte la URL |
| `video_titles` | Cadena de lista | Una posición por asociación, incluso títulos vacíos |

El resultado sin asociaciones elegibles es `(0, 0, '', '')`. No se deduplica por URL. Las listas usan el mismo orden: posición efectiva ascendente, posiciones nulas al final e identificador de la entrada de galería para desempatar.

Si una cabecera existente ya utiliza cualquiera de los cuatro nombres, la exportación enriquecida falla para evitar sobrescribir un campo. No se renombran columnas automáticamente.

### Contexto y bloques heredados

La identidad externa se expresa mediante `sku` y `store_view_code`. El código de tienda vacío representa el ámbito base administrativo, store 0. `product_websites` expresa asignaciones; no identifica el contexto efectivo de los videos.

En `all`, la base presente lleva el bloque completo. Una tienda con cualquier diferencia lleva también el bloque completo. Si la tienda es igual a una base incluida, se omiten las cuatro claves; en una fila conservada por otros datos se representan como cuatro celdas vacías. Sin base disponible, cada contexto disponible lleva el bloque completo. Una fila completamente heredada puede ser omitida por Magento.

Un consumidor de `all` debe tratar las cuatro columnas como unidad: heredar el bloque completo cuando corresponda y nunca heredar individualmente un título vacío de un bloque presente. La ausencia de una fila tampoco demuestra que haya sido seleccionada por los filtros nativos.

En `with` y `without`, cada contexto aceptado lleva las cuatro columnas explícitas. Los demás campos del CSV mantienen su representación nativa, que puede ser diferencial. Los conjuntos de contextos de ambos filtros son disjuntos, pero la suma de sus filas físicas no tiene por qué coincidir con las filas de `all`.

### Listas y normalización

Hay dos niveles de codificación: primero las listas de videos y después el CSV nativo.

1. En títulos, cada secuencia CRLF se convierte en un espacio. Cada CR, LF o tabulación restante se convierte en un espacio. No se aplica `trim` ni se colapsan otros espacios.
2. En cada elemento de ambas listas, cada barra inversa `\` se convierte en `\\` y cada barra vertical `|` se convierte en `\|`, en ese orden.
3. Los elementos se unen con `|`, sin espacios añadidos alrededor del delimitador. Se conservan elementos vacíos, incluidos el primero y el último.
4. El escritor de Magento serializa las columnas originales y nuevas una sola vez como CSV UTF-8, delimitado por comas y con comillas internas duplicadas. Se conserva la configuración nativa de entrecomillado.

Ejemplos de listas antes de la serialización CSV:

```text
Elementos: ["Frente", "Detalle|lado", ""]
Lista:     Frente|Detalle\|lado|
Conteo:    3

Elementos: ["Ruta\demo"]
Lista:     Ruta\\demo
Conteo:    1

Elementos: [""]
Lista:     cadena vacía
Conteo:    1
```

Para decodificar, primero utiliza un lector CSV compatible. Después recorre cada lista respetando las secuencias `\\` y `\|`; solo una barra vertical sin escapar separa elementos. Un `split('|')` simple es incorrecto. Usa `video_count` para distinguir cero elementos de un único elemento vacío y para validar que ambas listas tengan la cantidad esperada. El código incluye `Domain\VideoListCodec` para ese nivel de codificación.

La URL lógica se conserva sin normalización adicional. Si falta en la fuente, su posición permanece vacía y la asociación sigue contando. No se consulta al proveedor del video para corregirla.

### Tratamiento nativo de celdas y hojas de cálculo

El driver CSV de la plataforma inspeccionada añade un espacio cuando una celda comienza con `=`, `+` o `-`. Se conserva ese comportamiento en todas las columnas. El módulo no rechaza fórmulas, no añade otra neutralización y no omite la protección del escritor nativo.

Ese espacio puede modificar el valor final respecto de la cadena lógica anterior a la escritura. No debe retirarse automáticamente como si fuera un escape reversible: también puede existir un espacio original. Por ello, el contrato no promete una reconstrucción idéntica de cualquier valor a partir del CSV.

La apertura directa en Excel o Google Sheets depende de la versión, configuración regional, conversiones automáticas y límites de la aplicación. No se garantiza protección universal ante evaluación de fórmulas ni se certifica reimportación del CSV para crear o actualizar videos. La verificación en las aplicaciones utilizadas por Grupo Dico está pendiente.

## Contrato PHP de lectura

`LeanCommerce\ProductVideoExport\Api\VideoReaderInterface` expone:

```php
public function read(array $productIds, int $storeId): array;
```

`$productIds` contiene enteros positivos del campo de enlace de producto definido por los metadatos de Magento; no debe suponerse que siempre sea el identificador público de entidad. `$storeId` es un entero no negativo; cero indica base. El resultado asocia cada identificador solicitado con un `Domain\VideoResult`, incluso cuando no tiene videos. Un lote vacío devuelve un array vacío sin consulta.

La operación es de lectura. Los argumentos inválidos producen `InvalidArgumentException`; datos contradictorios de una misma asociación producen `DomainException`. Los errores de infraestructura se propagan para impedir presentar un archivo parcial como correcto. El contrato se consume mediante inyección de dependencias.

Las clases de integración en `Model` y `Plugin` dependen de las firmas de Magento y Bss. No constituyen una promesa de estabilidad para adaptadores ajenos a las versiones verificadas.

## Plataforma y verificación

**Estado de la distribución: sin publicar.** La aceptación en el entorno de destino sigue pendiente. Un requisito de Composer no sustituye una prueba de funcionamiento.

| Componente o entorno | Requisito o dato observado | Alcance de la verificación actual |
|---|---|---|
| Magento objetivo | Magento Open Source 2.4.9 | Es la plataforma requerida; falta completar la aceptación sobre el stack de destino |
| PHP de distribución y destino de pruebas | `~8.3.0`; PHP 8.3 confirmado por el cliente | El parche exacto del servidor de pruebas debe registrarse y la aceptación del destino sigue pendiente |
| Base de datos del destino | Motor y versión pendientes | No se presupone equivalencia con la base de datos del laboratorio |
| Laboratorio de integración | Magento Open Source 2.4.9, PHP 8.3.16, Percona 8.0.25-15 | Lectura por lotes, fixtures, flujo de exportación y concurrencia técnica verificados; pruebas de laboratorio confirmadas como satisfactorias por el responsable. No sustituyen la aceptación del destino |
| Herramientas para el objetivo PHP 8.3 | PHP 8.3.35 aislado | Comprobación de calidad, pruebas unitarias y paquete ejecutados; no sustituye una instalación y aceptación en el destino |
| Runtime adicional de herramientas | PHP 8.5.9 aislado | Pruebas unitarias y herramientas ejecutadas; no es el objetivo exclusivo ni una certificación de Magento completo en PHP 8.5 |
| Dependencia Bss observada | `Bss_CustomProductAttributeExport` 1.0.6, suministrado por separado | Deben conservarse y verificarse su implementación y parches en el destino |
| Administración y ACL | Bloques e interceptor nativos; idiomas es_MX/en_US y política ACL con roles en memoria, en PHP 8.3.16 | Render del formulario/filtro, form key y decisiones de autorización verificados. Inicio de sesión y envío desde Admin reportados por el responsable, con confirmación general satisfactoria del laboratorio; falta validar los recorridos en el destino |
| Cola y archivos | Flujo nativo de Magento | Una solicitud real de Admin completó el consumer nativo, notificación y archivo final en laboratorio. La aceptación integral del destino sigue pendiente |
| Excel y Google Sheets | Versiones del cliente por registrar | La confirmación general del laboratorio no desglosa versiones ni casos de aplicación; registrar apertura directa, Unicode, listas y conversiones en la validación del destino |
| Otras ediciones o versiones de Magento/PHP | Fuera de la matriz declarada | Sin compatibilidad comprometida |

La confirmación del servidor de pruebas no certifica que toda su combinación de Magento, PHP, base de datos y servicios esté oficialmente soportada. Deben contrastarse los requisitos de la distribución de Magento y completarse las pruebas del destino antes de comprometer soporte productivo. El laboratorio y el runtime adicional no reemplazan esa verificación.

El paquete declara las dependencias de módulos Magento en su `composer.json`. `Bss_CustomProductAttributeExport` es una dependencia adicional obligatoria, ordenada en `module.xml`; no se inventa un paquete Composer para proveerla. Debe estar presente y habilitada antes de instalar o compilar este módulo.

Los cambios de versión o parches de Magento, ProductVideo, Bss, personalizadores del exportador y adaptadores CSV requieren repetir la verificación de herencia, cabeceras, paginación, errores y archivos. La ruta sin la clave nueva debe conservar el resultado y los errores del exportador anterior.

## Límites de integración

La función está definida para el CSV nativo. Una factoría contextual se inyecta exclusivamente en el modelo de exportación; no hay una sustitución global del adaptador CSV ni de su factoría. Adaptadores personalizados mantienen su construcción nativa y requieren verificación específica de su ciclo de archivos.

Si una integración instancia directamente `ProductExport` y proporciona su propio writer, esa integración conserva la responsabilidad de cerrar y eliminar sus temporales. El ciclo completo de publicación y limpieza documentado se obtiene mediante el flujo de exportación de Magento con la factoría contextual del módulo.

La identificación y limpieza de temporales se verifica con el filesystem local de Magento. No se declara compatibilidad de ese mecanismo con almacenamiento remoto, como S3, ni eliminación atómica frente a actores externos que sustituyan rutas concurrentemente.

No se ofrece una instantánea transaccional de todo el catálogo: los datos se leen al ejecutar el trabajo, y las modificaciones concurrentes pueden reflejarse en distintos momentos entre lotes. Repetir una exportación puede producir resultados diferentes si el catálogo cambió.
