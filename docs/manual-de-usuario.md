# Manual de usuario

Product Video Export añade información de videos al CSV de productos de Magento. Permite identificar productos con o sin videos habilitados y consultar sus URL y títulos en el contexto de cada tienda. El archivo conserva los campos y filtros de la exportación de productos, incluidos los aportados por Bss.

**Estado: sin publicar.** La disponibilidad de una versión para producción y su aceptación en el entorno de Grupo Dico están pendientes. La guía describe el funcionamiento implementado y el contrato del archivo.

## Exportar desde Administración

1. Inicia sesión con un usuario autorizado para exportar datos.
2. Abre **Sistema → Transferencia de datos → Exportar**. Los nombres pueden aparecer en inglés según el idioma de Administración: **System → Data Transfer → Export**.
3. Selecciona **Productos** como tipo de entidad y **CSV** como formato.
4. Aplica los filtros de producto que necesites. Conservan su significado habitual en Magento.
5. En **Presencia de video**, elige una de las tres opciones de la tabla siguiente. **Todos** es la opción inicial.
6. Envía la exportación mediante el control nativo de la pantalla.
7. Espera la notificación de Magento y descarga el archivo desde la página de exportaciones. El aviso de que la solicitud se añadió a la cola confirma su recepción; el archivo estará disponible cuando termine el procesamiento.

No es necesario configurar claves de YouTube o Vimeo para consultar estos datos. El módulo lee los videos ya asociados al catálogo; no visita sus URL ni verifica si se reproducen.

| Opción | Qué incluye | Cómo presenta los videos |
|---|---|---|
| Todos | Los contextos de producto y tienda que entrega la exportación nativa | Conserva la representación de Magento: los valores de tienda iguales a la base pueden quedar vacíos o no generar una fila |
| Con video | Contextos con al menos un video habilitado | Las cuatro columnas siempre muestran el resultado de ese contexto |
| Sin video | Contextos sin videos habilitados | Las cuatro columnas muestran `0`, `0` y las dos listas vacías |

La selección se aplica por producto y tienda. Un mismo SKU puede aparecer en un archivo **Con video** para una tienda y en **Sin video** para otra. Los filtros de producto se aplican antes: la opción de videos no recupera productos o tiendas excluidos por ellos.

## Identificar la tienda

Usa las columnas nativas `sku` y `store_view_code` para interpretar cada fila. Un `store_view_code` vacío identifica los valores base de Magento, correspondientes al ámbito administrativo. No significa que el dato pertenezca a una tienda comercial predeterminada.

`product_websites` indica las asignaciones del producto a sitios web. No sustituye a `store_view_code` ni cambia la tienda a la que pertenecen los videos de la fila.

Las filas representan contextos de producto y tienda, no videos individuales. Un producto con tres videos puede tener los tres en una misma fila.

## Las cuatro columnas nuevas

Se añaden al final de las columnas que ya exporta la instalación.

| Columna | Significado |
|---|---|
| `has_video` | `1` si hay al menos un video habilitado; `0` si no hay ninguno |
| `video_count` | Cantidad de asociaciones de video habilitadas en ese contexto |
| `video_urls` | URL de esos videos, en una lista separada por barras verticales |
| `video_titles` | Títulos en el mismo orden que las URL, conservando las posiciones con título vacío |

La lista usa el carácter `|` como separador. Dentro de un valor, `\|` representa una barra vertical literal y `\\` representa una barra inversa literal. Para procesar las listas con código, consulta la [guía del contrato CSV](guia-api-y-compatibilidad.md#listas-y-normalización).

Se cuentan asociaciones de galería: dos videos distintos con la misma URL cuentan como dos. El orden sigue la posición configurada en Magento; en caso de empate, se utiliza la identidad de la entrada de galería. Las posiciones sin valor van al final.

Un título vacío sigue ocupando su lugar. Por ejemplo, para tres videos con títulos `Frente`, `Detalle|lado` y un último título vacío, el valor lógico de `video_titles` es:

```text
Frente|Detalle\|lado|
```

`video_count=3` permite interpretar la posición final vacía. Un video con URL faltante también conserva su posición y su conteo; conviene revisar ese dato en el catálogo.

## Entender los vacíos en Todos

En **Todos**, las cuatro columnas forman un bloque. Si el resultado de una tienda es igual al de la base incluida en la exportación, Magento puede omitir el bloque completo. Esto también ocurre si los valores se guardaron expresamente en la tienda pero son iguales a los de base.

- Si otros atributos conservan la fila, aparecen las cuatro celdas vacías: el bloque coincide con la base.
- Si tampoco existen otras diferencias que requieran una fila, Magento puede omitir esa fila.
- Si cambia cualquiera de los cuatro valores de video, se escribe el bloque completo.
- Si los filtros nativos dejan una tienda sin incluir la base de ese producto, se escribe su bloque completo.

En el ejemplo, `vacío` es una explicación; no es texto que se escriba en el CSV. Los códigos de tienda son ilustrativos.

| SKU | `store_view_code` | `has_video` | `video_count` | `video_urls` | `video_titles` | Interpretación |
|---|---|---:|---:|---|---|---|
| EJEMPLO | vacío | 1 | 1 | URL A | General | Resultado base |
| EJEMPLO | tienda_a | vacío | vacío | vacío | vacío | Igual a la base; la fila existe por otro dato |
| EJEMPLO | tienda_b | 1 | 1 | URL A | Local | Título distinto en esta tienda |
| EJEMPLO | tienda_c | 0 | 0 | vacío | vacío | Todos los videos están deshabilitados en esta tienda |
| EJEMPLO | tienda_d | 1 | 1 | URL A | vacío | Hay un video con título local vacío |

Cuatro vacíos en **Todos** no significan automáticamente que falten videos. Para buscar contextos sin videos, utiliza **Sin video**. Un título vacío dentro de un bloque que sí incluye `has_video` y `video_count` es un valor explícito; no se rellena desde la base.

Las filas físicas de **Todos** pueden diferir de la suma de filas de **Con video** y **Sin video**, porque estos dos filtros muestran los bloques explícitamente. Ninguna de esas opciones completa los demás atributos de producto que Magento exporte como heredados.

## Herencia y habilitación

Los valores de video de una tienda heredan de la base cuando no tienen un valor propio. Un título local vacío o una posición local cero son valores propios válidos. No se reemplazan por el valor base.

Los videos deshabilitados no se cuentan. Una entrada deshabilitada globalmente queda excluida de todas las tiendas. Una deshabilitación de la base puede tener una habilitación propia en una tienda cuando Magento lo permite. Si no queda ningún video habilitado, el resultado efectivo es `0`, `0` y las dos listas vacías, sujeto a la representación de **Todos** ya explicada.

## Abrir el archivo en Excel o Google Sheets

El archivo utiliza CSV en UTF-8 y separador de columnas por coma. Conserva el CSV original descargado. Si la aplicación muestra todo en una columna o caracteres incorrectos, importa el archivo indicando UTF-8 y coma; la apertura directa depende de la configuración y versión de la aplicación.

Para evitar conversiones de SKU u otros valores a fechas o números, selecciona formato de texto para esas columnas cuando la aplicación lo permita. No elimines espacios iniciales de forma masiva: el escritor nativo de Magento puede añadir un espacio a una celda que comience con `=`, `+` o `-`. El módulo conserva ese tratamiento; no garantiza un comportamiento universal frente a fórmulas en todas las versiones de Excel o Sheets.

Los saltos de línea y tabulaciones de los títulos se convierten en espacios. No se recortan los espacios existentes. Las comas y comillas se escapan mediante el escritor CSV de Magento.

Las hojas de cálculo tienen límites de filas y longitud de celda. Un archivo con muchos productos o listas extensas puede superar esos límites aunque el CSV sea válido. La apertura real en las versiones utilizadas por Grupo Dico sigue pendiente de aceptación; una vista incompleta en una hoja no demuestra por sí sola que el exportador haya truncado el archivo.

## Problemas habituales

| Situación | Qué hacer |
|---|---|
| No aparece Presencia de video | Comprueba que la entidad sea Productos. Pide al administrador que verifique la instalación y las dependencias del módulo |
| La solicitud se recibió, pero no hay archivo | Espera la notificación y actualiza la página. Si persiste, reporta la hora, filtros y entidad para que Operaciones revise el procesamiento de la cola |
| Magento indica que no hay datos para exportar | Revisa los filtros de producto y presencia. No se genera un CSV vacío con solo cabeceras |
| El archivo tiene cuatro celdas de video vacías en una tienda | Revisa la fila base del mismo SKU y las reglas de Todos |
| El conteo es mayor que las URL distintas | Comprueba si hay varias entradas de galería con la misma URL; cada asociación habilitada cuenta |
| Hay un mensaje de error de exportación | Conserva el mensaje, hora y filtros y repórtalos. Un archivo parcial no debe tratarse como una exportación terminada |
| Las columnas se ven desplazadas en una hoja de cálculo | Importa como CSV UTF-8 con coma y comillas de texto. No separes manualmente por todas las barras verticales |

El módulo no edita productos ni importa los videos del CSV. Tampoco mide reproducciones, ventas o resultados de campañas. Cualquier análisis externo debe cruzar SKU y contexto de tienda, sin contar las filas como videos ni asumir que una URL accesible indica uso del video.
