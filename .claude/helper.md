# Claude Code Helper — SAV (Sistema de Alertas Veterinarias)

Guía para usar agentes especializados y la estructura de carpetas en `.claude/`.

---

## Agentes disponibles

### Pipeline de features nuevas (PRD → producción)

```
funcional → arquitecto → dev-backend → backend-tester / frontend-tester → qa-backend / qa-frontend
```

### Pipeline rápido (bug / mejora acotada)

```
ticket-builder → arquitecto → dev-backend
```

### Generación de módulos desde cero

```
ticket-builder → arquitecto → backend-module-gen → frontend-module-gen → qa-backend → qa-frontend
```

---

### 1. **funcional** — Analista funcional para PRDs

**Cuándo usarlo:**
- Convertir un PRD en especificación técnica accionable con reglas del dominio SAV
- Detectar ambigüedades antes de que lleguen al arquitecto (días de protocolo, roles de alerta, año ganadero, multi-tenant, multi-país)

**Ejemplo:**
```
Invocá el agente funcional sobre el archivo .claude/docs/prds/PRD-SAV-003-confirmar-tareas.md
```

**Output:** `.claude/docs/specs/[nombre]-spec.md`

---

### 2. **ticket-builder** — Convertir requerimientos en tickets estructurados

**Cuándo usarlo:**
- Bug reportado verbalmente sin estructura
- Mejora o refactor acotado que necesita decisiones explícitas antes del arquitecto
- Detectar y frenar decisiones que violan reglas duras del dominio SAV

**Ejemplo:**
```
Usá el agente ticket-builder. Quiero agregar soporte para que el encargado de campo 
confirme desde el celular que se realizó la inseminación.
```

**Output:** `.claude/docs/tickets/TKT-XXX-nombre-corto.md`

---

### 3. **arquitecto** — Diseño técnico detallado

**Cuándo usarlo:**
- Diseñar la implementación de un ticket o spec (nivel "ejecutar paso a paso")
- Tomar decisiones arquitectónicas y documentarlas
- Investigar root cause de bugs complejos

**Ejemplo:**
```
Invocá el agente arquitecto sobre .claude/docs/tickets/TKT-005-alerta-confirmacion-ia.md
```

**Output:** `.claude/docs/plans/[nombre]-plan.md`

---

### 4. **dev-backend** — Implementación full-stack

**Cuándo usarlo:**
- Ejecutar un plan del arquitecto (backend + frontend)
- Implementar un fix con diagnóstico ya definido

**Ejemplo:**
```
Invocá el agente dev-backend para ejecutar el plan en 
.claude/docs/plans/TKT-005-alerta-confirmacion-ia-plan.md
```

**Output:** Código en el repo, tests pasando.

---

### 5. **backend-module-gen** — Generador de módulos Laravel completos

**Cuándo usarlo:**
- Crear un módulo backend nuevo desde cero (migration, modelo, repositorio, servicio, requests, resource, controller, rutas, permisos)
- Útil cuando el plan del arquitecto indica "generar módulo X"

**Ejemplo:**
```
Usá el agente backend-module-gen para crear el módulo Breed (raza bovina).
Tiene: nombre, especie, descripción. Se relaciona con Animal.
```

---

### 6. **frontend-module-gen** — Generador de módulos Vue 3 completos

**Cuándo usarlo:**
- Crear el módulo frontend completo para un módulo backend ya existente
- Genera: types, api, validators, stores, composables, components, pages, router

**Ejemplo:**
```
Usá el agente frontend-module-gen para el módulo Breed.
El Resource retorna: { guid, name, species, description, created_at }
Operaciones: CRUD completo.
```

---

### 7. **ui-specialist** — Componentes de UI complejos

**Cuándo usarlo:**
- Construir o refactorizar componentes visuales específicos del dominio SAV
- Timeline de protocolos, confirmación de tareas de campo, badges de estado animal
- Crear nuevos átomos cuando no existe uno adecuado en `front/src/components/atoms/`

**Ejemplo:**
```
Usá el agente ui-specialist para construir el componente ProtocolTimeline
que muestra los días D0, D7, D11 con estado de cada tarea.
```

---

### 8. **qa-backend** — Revisión de calidad código Laravel

**Cuándo usarlo:**
- Después de generar un módulo nuevo con backend-module-gen
- Antes de hacer un PR
- Cuando sospechás que una implementación no sigue las convenciones SAV

**Ejemplo:**
```
Usá el agente qa-backend para revisar el módulo Breed.
```

**Output:** `.claude/docs/reviews/qa-backend-{nombre}-{fecha}.md`

---

### 9. **qa-frontend** — Revisión de calidad código Vue 3

**Cuándo usarlo:**
- Después de generar un módulo nuevo con frontend-module-gen
- Para verificar tipos, i18n, PermissionGuard, query invalidation

**Ejemplo:**
```
Usá el agente qa-frontend para revisar el módulo Breed.
```

**Output:** `.claude/docs/reviews/qa-frontend-{nombre}-{fecha}.md`

---

### 10. **backend-tester** — Tests PHPUnit/Pest

**Cuándo usarlo:**
- Generar feature tests por endpoint y unit tests por Service
- Después de crear o modificar un módulo backend

**Ejemplo:**
```
Usá el agente backend-tester para escribir los tests del módulo Breed.
```

---

### 11. **frontend-tester** — Tests Vitest + Vue Test Utils

**Cuándo usarlo:**
- Generar tests de componentes, composables y stores Pinia
- Después de crear o modificar un módulo frontend

**Ejemplo:**
```
Usá el agente frontend-tester para escribir los tests del módulo Breed.
```

---

## Estructura de carpetas en `.claude/`

```
.claude/
├── agents/                  ← Agentes especializados del proyecto
│   ├── arquitecto.md
│   ├── dev-backend.md
│   ├── funcional.md
│   ├── ticket-builder.md
│   ├── backend-module-gen.md
│   ├── frontend-module-gen.md
│   ├── qa-backend.md
│   ├── qa-frontend.md
│   ├── ui-specialist.md
│   ├── backend-tester.md
│   └── frontend-tester.md
│
├── docs/
│   ├── prds/                ← Product Requirements Documents (input del funcional)
│   ├── specs/               ← Specs funcionales (output del funcional, input del arquitecto)
│   ├── tickets/             ← Tickets técnicos (output del ticket-builder, input del arquitecto)
│   ├── plans/               ← Planes de implementación (output del arquitecto, input del dev)
│   └── reviews/             ← Reportes de QA (output de qa-backend / qa-frontend)
│
├── knowledge/               ← Base de conocimiento del dominio SAV
│   ├── veterinary-domain.md ← Especies, protocolos IATF/MOET/OPU-FIV, sanidad, roles
│   └── regulations-by-country.md ← SENASA AR, SENASICA MX, ICA CO, SAG CL, MAPA BR
│
└── memory/                  ← Auto-memory persistente entre sesiones
    └── MEMORY.md            ← Índice de memoria
```

---

## Reglas de oro

| Regla | Por qué |
|-------|---------|
| Leé `.claude/knowledge/` antes de tocar protocolos o alertas | El dominio veterinario tiene invariantes que no son obvias |
| Toda query scoped al tenant | Cada vet es un tenant — filtrar siempre |
| GUID en URLs, nunca ID interno | Convención SAV no negociable |
| `days_offset` + `time_of_day` en toda tarea | Sin estos campos, el protocolo está incompleto |
| Roles explícitos en alertas | "notificar al usuario" no es válido — hay 4 roles específicos |
| Consultá memory/ para contexto previo | Evita repetir decisiones ya tomadas |

---

*Proyecto: SAV — Sistema de Alertas Veterinarias*
*Actualizado: 2026-05-22*
