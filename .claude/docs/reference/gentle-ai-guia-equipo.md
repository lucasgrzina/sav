# Gentle AI en SAV — Guía para el equipo

Esta guía explica qué es `gentle-ai`, cómo está aplicado puntualmente en este repo, qué ganamos (y qué cuesta) usarlo, y los pasos exactos para que cualquier persona del equipo lo tenga funcionando en su máquina.

## 1. Qué es gentle-ai (y qué NO es)

`gentle-ai` ([github.com/Gentleman-Programming/gentle-ai](https://github.com/Gentleman-Programming/gentle-ai)) es un CLI que se instala **a nivel de máquina** (no es una dependencia de Composer/npm del proyecto). Su trabajo es mantener sincronizada la configuración de agentes de IA (Claude Code, Cursor, GitHub Copilot, Gemini CLI, OpenClaw, etc.) a partir de una única fuente de verdad.

En este repo esa fuente de verdad es `SOUL.md` (raíz del proyecto): ahí vive la persona (reglas, tono, filosofía) que `gentle-ai` propaga a `.claude/CLAUDE.md` y al resto de carpetas de agentes que ves en `git status` (`.cursor/`, `.copilot/`, `.gemini/`, `.openclaw/`, `.config/`, `.gga`). Por eso esas carpetas conviven en la raíz: no es desorden, es el mismo "cerebro" exportado para cada herramienta.

Lo que **no** es gentle-ai:
- No reemplaza Engram (memoria persistente) — es un producto relacionado del mismo ecosistema, con su propio binario y su propio plugin de Claude Code.
- No reemplaza CodeGraph ni codebase-memory-mcp — esas son herramientas de exploración de código que puede tener configuradas cada dev a nivel personal; no son parte del setup compartido de este repo.

## 2. Cómo está aplicado en SAV concretamente

### 2.1 Pipeline de dominio vs. agentes genéricos

`.claude/agents/` tiene dos capas, y la regla de precedencia está escrita en `.claude/CLAUDE.md`:

- **Agentes de dominio SAV (custom, primarios)**: `ticket-builder`, `funcional`, `arquitecto`, `dev-backend`, `backend-module-gen`, `frontend-module-gen`, `backend-tester`, `frontend-tester`, `qa-backend`, `qa-frontend`, `ui-specialist`. Son el flujo por defecto para tickets, bugs y features.
- **Agentes genéricos que trae gentle-ai (aditivos)**: `sdd-*` (Spec-Driven Development formal: proposal → spec → design → tasks → apply → verify → archive), `review-*` (4R: risk, resilience, readability, reliability), `jd-*` (judgment-day, revisión dual adversarial).

Los genéricos **no reemplazan** al pipeline SAV salvo que se pida explícitamente `/sdd-*` o el cambio sea grande y multi-módulo y el equipo confirme que quiere el trail formal de specs.

### 2.2 Disparadores automáticos (Agent Trigger Rules)

Documentados en `.claude/CLAUDE.md`, son recomendaciones que el orquestador aplica solo, sin que nadie tenga que acordarse:

- Pre-commit / pre-push: `review-readability` (barato, siempre).
- Pre-PR sobre `auth/`, `update/`, `security/`, `payments/`, o diffs > 400 líneas: las 4 lentes de review en paralelo.
- Post fase de diseño o apply de SDD: `judgment-day`.

### 2.3 Memoria persistente (Engram) en vez de OpenSpec

El repo tuvo OpenSpec (CLI standalone de specs) corriendo en paralelo con el `sdd-*` de gentle-ai — dos sistemas compitiendo sobre el mismo artefacto. Se removió y todo se migró a Engram: los 14 specs de módulo bajo `specs/{módulo}` y el change pendiente `protocol-alerts-enhancements` bajo `sdd/protocol-alerts-enhancements/*`. Esto está bloqueado en `CLAUDE.md`: no se vuelve a ofrecer `openspec` ni `hybrid` como modo de artifact store en este proyecto.

### 2.4 El hook de `settings.json`

`.claude/settings.json` corre `gentle-ai skill-registry refresh` en cada prompt. Mantiene `.atl/skill-registry.md` actualizado para que el agente sepa qué skills existen sin tener que releer todo el disco. Si `gentle-ai` no está instalado en tu máquina, el hook falla en silencio (`|| true`) — Claude Code sigue funcionando, pero perdés ese refresh automático.

## 3. Ventajas vs. no usarlo

**A favor:**
- Una sola fuente de verdad (`SOUL.md`) para persona y reglas. Si mañana el equipo suma Cursor o Copilot, no hay que reescribir las reglas a mano en cada herramienta.
- Agentes de dominio versionados en el repo: todo el equipo trabaja con el mismo "senior dev" y las mismas convenciones SAV (backend/frontend), no con el prompt personal de cada uno.
- Red de revisión automática y barata (readability siempre, 4R completo en paths sensibles, judgment-day en diseño/apply) sin que nadie tenga que pedirla.
- SDD formal disponible cuando el cambio lo amerita, sin forzarlo en tickets chicos.
- Memoria de equipo compartida (Engram): decisiones y specs sobreviven entre sesiones y no dependen de la memoria de una sola persona.

**En contra / costos:**
- Instalación extra a nivel de máquina (`gentle-ai` + plugin de Engram) — no alcanza con clonar el repo si querés el hook y la memoria funcionando.
- Curva de aprendizaje: hay que entender la regla de precedencia (cuándo gana el agente de dominio sobre el genérico).
- Dependencia de binarios externos activamente mantenidos por un tercero (`gentle-ai`, `engram`).

**Costo de NO usarlo** (ignorar `.claude/` y laburar a mano):
- Cada dev termina con su propio criterio de convención → inconsistencia entre PRs.
- Se pierde la capa de revisión automática (4R, judgment-day) → lo que hoy se atrapa antes del PR pasa directo a review humana.
- Sin Engram, las decisiones de arquitectura quedan solo en la cabeza de quien las tomó.

## 4. Instalación para el resto del equipo

### Paso 0 — Lo que ya viene con `git clone` (nada que instalar)

`SOUL.md`, `.claude/CLAUDE.md`, `.claude/agents/`, `.claude/skills/`, `.claude/commands/`, `.claude/settings.json`, y las carpetas de otros agentes (`.cursor/`, `.copilot/`, `.gemini/`, `.openclaw/`, `.config/`, `.gga`) quedan versionados en git porque este proyecto instaló gentle-ai con `--scope=workspace` (config a nivel de proyecto, no de usuario). Con solo clonar el repo y abrir Claude Code ya tenés la persona, los agentes de dominio y los skills funcionando.

### Paso 1 — Instalar el CLI `gentle-ai`

Windows (PowerShell):
```powershell
irm https://raw.githubusercontent.com/Gentleman-Programming/gentle-ai/main/scripts/install.ps1 | iex
```

Mac/Linux:
```bash
brew tap Gentleman-Programming/homebrew-tap
brew install gentle-ai
```

Verificar:
```bash
gentle-ai doctor
```

### Paso 2 — Habilitar el plugin de Engram en Claude Code

El binario `engram` (memoria persistente) se instala junto con el ecosistema de gentle-ai, pero para que Claude Code tenga las tools `mem_save`/`mem_search`/etc. hay que sumar el plugin dentro de Claude Code:

```
/plugin marketplace add Gentleman-Programming/engram
/plugin install engram@engram
```

`gentle-ai doctor` te confirma si el servicio de Engram está corriendo y accesible (`engram:reachable`).

### Paso 3 — Confirmar que quedó todo funcionando

1. Abrí Claude Code en la carpeta del proyecto y mandá cualquier prompt: la respuesta debería tener la personalidad "Senior Architect" (output style `Gentleman`).
2. Corré `gentle-ai doctor` — debería mostrar `gentle-ai`, `engram` y el agente `claude-code` en estado `ok`.
3. Pedile a Claude que liste los agentes disponibles o probá invocar `ticket-builder` sobre un requerimiento cualquiera — si responde con las preguntas cerradas del dominio SAV, está bien conectado al pipeline del proyecto.

### Mantenimiento

Cuando gentle-ai saque una versión nueva o el proyecto actualice su template compartido, correr en la raíz del repo:

```bash
gentle-ai sync
```

Esto trae actualizaciones de skills/agentes genéricos sin pisar las reglas propias de SAV (la sección "Agent Precedence" y "OpenSpec Removed" de `CLAUDE.md` están marcadas explícitamente como reglas de proyecto que sobreviven a un resync).
