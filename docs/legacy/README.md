# Documentación del sistema legacy

Los documentos de esta carpeta describen el sistema **anterior** de SAV (Laravel 11 + Filament + Twilio, "rama develop", auditado 2026-06-20 según `INVENTARIO_TECNICO.md`). **No reflejan el estado del código actual en `back/` y `front/`**, que es un rewrite desde cero.

Sirven como base de entendimiento funcional: qué reglas de negocio, flujos y pantallas existían, para evaluar qué vale la pena adaptar a la nueva arquitectura y qué no. Antes de asumir que algo descripto acá ya existe en el código actual, verificar contra `back/` (migraciones, modelos, rutas) y `front/` (módulos).

## Contenido

- `ANALISIS_FUNCIONAL.md` — análisis funcional completo por módulo (auth, clientes, técnicas/protocolos, programas, planes sanitarios, alertas, agenda, importación, facturación, WhatsApp, soporte, configuración)
- `diccionario-datos-er.md` — diccionario de datos y modelo ER completo del sistema legacy
- `funcionalidades-negocio-veterinario.md` — funcionalidades de negocio desde la óptica veterinaria
- `guia-soporte-operativo.md` — guía operativa de soporte (flujos de troubleshooting)
- `INVENTARIO_TECNICO.md` — auditoría técnica: stack, arquitectura, deudas técnicas y bugs conocidos
- `manual-funcional-pantallas.md` — manual de pantallas (panel Filament y API)
- `actividades.jpg` — screenshot de la matriz actividad×mes del panel de Planes Sanitarios (Filament)
