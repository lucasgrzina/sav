# Dominio Veterinario — Base de Conocimiento SAV

## Especies productivas y de compañía

### Bovinos (prioridad 1 — dominio principal de SAV)
- **Razas lecheras**: Holstein, Jersey, Pardo Suizo, Ayrshire
- **Razas cárnicas**: Angus, Hereford, Shorthorn, Brahman, Brangus, Limousin, Simmental
- **Razas doble propósito**: Simmental, Fleckvieh
- **Unidad de manejo**: rodeo, lote, categoría (vaca, vaquillona, ternera, toro, novillito, novillo)
- **Identificación individual**: caravana auricular, chip RFID, tatuaje, marca a fuego
- **Parámetros productivos clave**: tasa de preñez, intervalo parto-concepción (IPC), tasa de destete, condición corporal (1-9), producción de leche (L/día)

### Porcinos
- **Categorías**: cerda reproductora, cachazo, lechón, capón, gorrino
- **Parámetros**: lechones nacidos vivos/camada, intervalo destete-celo, tasa de parición

### Equinos
- **Categorías**: yegua, padrillo, potrillo, potro, castrado
- **Ciclo reproductivo**: estacional (hemisferio sur: sept-marzo), luteal, anovulatoria

### Pequeños animales
- **Caninos y felinos**: no tienen `animals_group` — se manejan como pacientes individuales
- **Parámetros**: peso, edad, historial de vacunación, microchip

### Aves (avicultura)
- **Manejo por bandada (flock)**, no individual
- **Tipos**: broilers, ponedoras, reproductores

---

## Reproducción Bovina (núcleo de SAV)

### Técnicas (árbol jerárquico — modelo `Technique`)

```
Técnicas Reproductivas (parent_id = null)
├── IATF — Inseminación Artificial a Tiempo Fijo
│   └── IA Convencional (detección de celo manual)
├── MOET — Multiple Ovulation and Embryo Transfer
│   └── TE Convencional (transferencia de embrión)
└── FIV — Fertilización In Vitro
    └── OPU-FIV (Ovum Pick-Up + FIV)
```

### Protocolos IATF — Días y tareas (cómo mapea a `ProtocolTask`)

**Protocolo Sincro-Ovsynch estándar:**
| Día | Nombre | Tratamiento | `days_offset` | `time_of_day` |
|-----|--------|------------|--------------|---------------|
| D0 | Inicio | Implante progesterona (DIV/CIDR) + Benzoato de estradiol (BE) | 0 | After |
| D7 | Tratamiento 2 | ECG (eCG/PMSG) + Cloprostenol (PGF2α) | 7 | After |
| D8 | Tratamiento 3 | Cloprostenol (PGF2α) | 8 | After |
| D9 | Retiro | Retiro implante + BE o GnRH | 9 | After |
| D11 | IA | Inseminación artificial | 11 | After |
| D30 | Diagnóstico | Diagnóstico de preñez (ecografía) | 30 | After |
| D60 | Confirmación | Confirmación de preñez | 60 | After |

**Protocolo J-Synch (IATF extendido):**
| Día | Tratamiento | `days_offset` |
|-----|------------|--------------|
| D0 | Implante P4 + 2mg BE | 0 |
| D8 | 400UI ECG + PGF2α | 8 |
| D9 | PGF2α | 9 |
| D10 | Retiro implante + 1mg BE | 10 |
| D12 | IA | 12 |

**Protocolo Heatsynch:**
| Día | Tratamiento | `days_offset` |
|-----|------------|--------------|
| D0 | GnRH | 0 |
| D7 | PGF2α | 7 |
| D9 | GnRH | 9 |
| D11 | IA | 11 |

### Protocolos MOET
- **Superovulación**: FSH decreciente 4 días (D0 a D3), 8 dosis
- **PGF2α retiro CL**: D5
- **IA**: D6 y D7 (doble inseminación)
- **Colecta de embriones**: D14-D15 (7-8 días post-IA)
- **Evaluación y transferencia**: misma sesión que colecta
- **Clasificación embriones**: grado 1 (excelente), 2 (bueno), 3 (regular), 4 (malo/degenerado)

### Protocolos OPU-FIV
- **OPU (aspiración folicular)**: cada 7-14 días según protocolo
- **FIV**: fecundación en laboratorio (día 0-7)
- **Cultivo embrionario**: D7-D8
- **Transferencia o criopreservación**: D7 en adelante

### Terminología de diagnóstico reproductivo
- **Preñada**: diagnóstico positivo (ecografía, palpación rectal)
- **Vacía**: diagnóstico negativo
- **Repetidora**: vaca que no queda preñada en 3+ servicios
- **Anestro**: ausencia de ciclo estral (posparto, nutricional, estacional)
- **Quiste folicular/luteal**: patología reproductiva
- **CL**: cuerpo lúteo (indicador de actividad cíclica)

---

## Sanidad Animal

### Plan Sanitario (`HealthPlan` en SAV)

El plan sanitario es el documento que organiza las actividades preventivas de un establecimiento por año. Obligatorio en Argentina para ciertos rodeos (SENASA).

**Actividades sanitarias por especie (`HealthActivity`):**

| Actividad | Especie | Frecuencia | Obligatoriedad AR |
|-----------|---------|-----------|-------------------|
| Vacuna Aftosa | Bovina | 2x/año (abr + oct) | Obligatoria |
| Vacuna Brucelosis | Bovinas hembras 3-8 meses | 1x vida | Obligatoria |
| Test Tuberculosis | Bovinos tamberos y exportación | Anual | Obligatoria (cuencas lecheras) |
| Vacuna Mancha/Gangrena | Bovinos | Anual | Recomendada |
| Vacuna IBR/DVB/PI3/BRSV | Bovinos | Anual | Recomendada |
| Antiparasitario externo | Bovinos | 2-4x/año | Recomendada |
| Antiparasitario interno | Bovinos | 2-4x/año | Recomendada |
| Vacuna Parvovirus/Leptospira | Porcinos | Anual | Recomendada |
| Vacuna Newcastle/Marek | Aves | Según calendario | Obligatoria bandadas comerciales |
| Vacuna Antirrábica | Caninos | Anual | Obligatoria (municipios) |

### Enfermedades de Notificación Obligatoria (Argentina/OIE)
- **Lista A (erradicación)**: Fiebre Aftosa, Brucelosis, Tuberculosis, Leucosis bovina
- **OIE lista única**: Fiebre Aftosa, Influenza Aviar, PPC (Peste Porcina Clásica), PSA (Porcina Africana), Fiebre del Nilo Occidental, Rabia
- El sistema SAV debe poder registrar notificaciones SENASA vinculadas a animales/establecimientos

---

## Alertas y Notificaciones (cómo mapea a SAV)

### Tipos de destinatarios (`ProtocolAlert.roles` en JSON)
- `vet`: el veterinario responsable
- `vet-assistant`: asistente del vet (ejecuta el procedimiento)
- `client-owner`: dueño del establecimiento
- `client-manager`: capataz/encargado del campo

### Momento de la alerta (`days_offset` + `time_of_day`)
- `Before`: días ANTES del `target_date` del programa
- `After`: días DESPUÉS del `target_date` del programa
- El `target_date` del programa es la fecha de inicio (D0) del protocolo

### Canales disponibles
- **WhatsApp Cloud API**: preferido en Argentina (alta tasa de entrega, gratuito hasta cierto volumen)
- **Twilio SMS**: fallback o para usuarios sin WhatsApp
- **Email**: para notificaciones administrativas, reportes

### `require_confirmation`
- Aplica cuando la tarea requiere que alguien confirme que se realizó (ej: IA realizada, colecta exitosa)
- En la WebApp, el cliente/encargado ve un botón "Confirmar" en su bandeja de notificaciones
- `confirmed_at` registra quién y cuándo confirmó

---

## Glosario de Términos por Región

### Argentina
- **Establecimiento**: campo, estancia, tambo, feedlot, cabaña (mapeado al modelo `Establishment`)
- **Rodeo**: grupo de animales de un establecimiento
- **RENSPA**: Registro Nacional Sanitario de Productores Agropecuarios (identificador único del establecimiento)
- **DIV/CIDR**: dispositivo intravaginal de progesterona (marca comercial y genérico)
- **Caravana**: identificación auricular plástica o electrónica
- **Tasa de preñez**: % de animales preñados sobre total inseminados
- **Año ganadero**: julio a junio (no coincide con año calendario)

### México
- **Rancho/Granja**: equivalente a Establecimiento
- **Arete**: caravana auricular
- **SINIIGA**: sistema de identificación individual (arete electrónico homologado)

### Perú/Colombia
- **Fundo/Hacienda**: equivalente a Establecimiento
- **Corriente**: ganado criollo sin raza definida

---

## Roles del Sistema y su Relación con el Dominio

| Rol (`RoleEnum`) | Descripción veterinaria | Permisos tipo |
|-----------------|------------------------|---------------|
| `superadmin` | Administrador del SaaS SAV | Todo |
| `vet` | Médico veterinario responsable (tenant owner) | CRUD completo de su práctica |
| `vet-assistant` | Asistente técnico (inseminador, enfermero) | Ejecutar tareas, ver programas |
| `vet-administrative` | Recepcionista/admin del consultorio | Clientes, agenda, facturación |
| `client-owner` | Dueño del establecimiento | Ver sus programas y planes sanitarios |
| `client-manager` | Capataz/encargado del campo | Ver y confirmar tareas en campo |
| `client-administrative` | Admin del establecimiento | Ver reportes y facturas |
