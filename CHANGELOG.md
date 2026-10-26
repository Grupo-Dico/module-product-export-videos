# Historial de cambios

## Sin publicar

- Exportación multitienda con `has_video`, `video_count`, `video_urls` y `video_titles`, añadidas al CSV nativo de productos y sus cabeceras Bss.
- Selección Todos, Con video y Sin video por contexto efectivo de tienda; Todos conserva la representación diferencial nativa y Con/Sin muestran el bloque explícito.
- Herencia por campo, conteo de asociaciones habilitadas, orden estable y listas con escapes y posiciones vacías conservadas.
- Compatibilidad con peticiones antiguas sin el parámetro de presencia, que mantienen la exportación anterior.
- Escritura CSV delegada a Magento, guardas frente a resultados parciales y limpieza acotada de temporales propios en el flujo de exportación.
- Etiqueta y opciones del filtro traducidas al renderizar Administración, respetando el idioma vigente y los filtros nativos.
- Documentación de uso, integración, instalación, operación y comprobación del destino para Grupo Dico.
- Repositorio standalone con herramientas de calidad reproducibles, CI, pruebas unitarias y guía de requisitos para integración.

La versión permanece sin publicar. PHP 8.3 está confirmado para el servidor de pruebas del cliente; la base de datos del destino, la aceptación completa de la combinación con Magento Open Source 2.4.9 y las aplicaciones de hojas de cálculo están pendientes.
