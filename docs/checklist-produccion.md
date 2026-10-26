# Checklist de producción

**Estado: sin publicar; aceptación del destino pendiente.** Esta lista debe completarse para una versión concreta antes de habilitarla a usuarios en producción. Las comprobaciones de laboratorio no sustituyen las del stack que operará Grupo Dico.

## Identificación de la instalación

| Dato | Registro de la instalación |
|---|---|
| Versión del módulo y paquete recibido | Por completar cuando exista una entrega |
| Entorno y fecha | Por completar |
| Responsable técnico y responsable de aceptación | Por completar |
| Magento y parches | Por completar |
| PHP web, CLI y consumers | Por completar |
| Motor y versión de base de datos | Pendientes de confirmación en el destino |
| Bss y parches | Por completar |
| Transporte y gestión de `exportProcessor` | Por completar |
| Versiones de Excel/Google Sheets y configuración regional | Por completar |

## Antes del despliegue

- [ ] Hay una entrega identificada, con integridad comprobada, documentación y plan de recuperación.
- [ ] Se registró el stack real: Magento Open Source 2.4.9, el PHP 8.3 confirmado para pruebas y la base de datos. Se contrastó la combinación con los requisitos oficiales y se completó su aceptación, sin omitir requisitos de plataforma.
- [ ] `Bss_CustomProductAttributeExport` está presente y habilitado; su implementación y parches son los verificados.
- [ ] Se revisaron otros exportadores, personalizadores de cabeceras y adaptadores CSV que puedan interactuar con la función.
- [ ] Las verificaciones automáticas corresponden a la versión que se instalará y no hay fallos pendientes que afecten el uso previsto.
- [ ] Se completó la aceptación de integración sobre un entorno representativo del destino, separada de las pruebas unitarias.
- [ ] El responsable del entorno acordó ventana, respaldo y recuperación para el despliegue productivo completo.
- [ ] Hay capacidad y permisos para generar temporales y publicar archivos; se conoce su ubicación y política de retención.
- [ ] Los trabajos pendientes y consumers se coordinaron sin eliminar mensajes o archivos ajenos.

## Despliegue

- [ ] Se instaló el paquete y archivo de bloqueo previstos, sin modificar Magento/Bss manualmente.
- [ ] Se integró el módulo con el procedimiento nativo y se revisaron cambios pendientes de la aplicación antes de ejecutar actualizaciones.
- [ ] Compilación, traducciones, estáticos y cachés corresponden al modo y a los idiomas del entorno.
- [ ] Los consumers cargan el código correcto y el transporte de exportación está operativo.
- [ ] La opción Presencia de video muestra Todos como valor inicial y las tres opciones traducidas según el idioma de Administración.

## Aceptación funcional

- [ ] Todos conserva las columnas existentes y añade exactamente `has_video`, `video_count`, `video_urls` y `video_titles` al final.
- [ ] La base corresponde a `store_view_code` vacío; ninguna tienda comercial sustituye ese contexto.
- [ ] Todos muestra el bloque completo ante diferencias y omite los cuatro valores iguales a una base presente, incluidos valores locales guardados iguales.
- [ ] Un contexto sin base incluida muestra su bloque completo; una diferencia únicamente de video conserva la fila correspondiente.
- [ ] Con video y Sin video filtran el resultado efectivo de cada tienda y muestran sus cuatro columnas explícitas.
- [ ] Un lote inicial sin coincidencias no impide exportar coincidencias posteriores.
- [ ] Se comprobaron herencia, deshabilitados, título vacío, posición cero, empate de posiciones y asociaciones distintas con la misma URL.
- [ ] Conteos y listas coinciden, incluidos elementos vacíos y valores con barra inversa, barra vertical, comas, comillas y Unicode.
- [ ] Los saltos y tabulaciones de títulos se normalizan a espacios sin recortar otros espacios.
- [ ] Una solicitud sin la clave nueva conserva la exportación anterior, incluidas sus cabeceras y comportamiento de error.
- [ ] No se modificaron productos, videos, atributos, configuración de catálogo o asociaciones por ejecutar la exportación.

## Canales, acceso y fallos

- [ ] La solicitud se encola, termina, notifica, publica y permite descargar el archivo mediante el flujo de Magento.
- [ ] El rol autorizado conserva su acceso y un rol sin el permiso nativo de exportación no obtiene acceso nuevo.
- [ ] Cero coincidencias produce el resultado nativo de ausencia de datos; no se publica un CSV de solo cabeceras.
- [ ] Un fallo provocado antes y después de escribir filas no publica un parcial como correcto.
- [ ] La limpieza retira solo temporales registrados propios y conserva archivos finales y preexistentes.
- [ ] Un error de publicación o limpieza se detecta y puede diagnosticarse; no se confunde recepción en cola con éxito del archivo.
- [ ] Se comprobó aislamiento entre una ejecución fallida, una petición sin clave y una ejecución enriquecida posterior.
- [ ] Se registraron tiempo, memoria, consultas y volumen representativo para operación; el archivo terminó íntegro.

## Lectura en aplicaciones y cierre operativo

- [ ] Marketing abrió el CSV en las versiones de Excel y Google Sheets previstas y confirmó separador, Unicode y presentación de los valores.
- [ ] Se revisaron conversiones automáticas, espacios iniciales nativos y celdas que comiencen con `=`, `+` o `-`, sin atribuir protección universal a la prueba.
- [ ] Se comprobó que los límites de filas y celdas de las aplicaciones no oculten información relevante del conjunto previsto.
- [ ] Los usuarios entienden que cuatro vacíos en Todos pueden representar herencia y que las filas no representan videos individuales.
- [ ] Operaciones conoce diagnóstico, retención, acceso a los archivos y procedimiento de reversión.
- [ ] El responsable de Grupo Dico confirmó la aceptación de esta versión en el entorno identificado.

Una casilla pendiente debe mantener explícita su limitación. No se declara una instalación aceptada únicamente porque Composer resuelva, compile el módulo o una prueba unitaria termine correctamente.
