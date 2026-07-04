# Regulaciones Sanitarias por País — Base de Conocimiento SAV

## Argentina (mercado inicial)

### Autoridad regulatoria
**SENASA** — Servicio Nacional de Sanidad y Calidad Agroalimentaria  
Web: senasa.gob.ar | Dependencia: Ministerio de Economía (ex-MINAGRO)

### Registro de establecimientos: RENSPA
- Registro Nacional Sanitario de Productores Agropecuarios
- Formato: `XX.XXX.X.XXXXX/XX` (provincia.departamento.tipo.número/secuencia)
- Obligatorio para cualquier actividad de producción animal
- **Impacto en SAV**: el modelo `Establishment` debe tener campo `renspa` (nullable para otros países)
- El RENSPA identifica geográficamente el establecimiento y define qué campaña sanitaria aplica

### Identificación fiscal
- **Personas físicas**: CUIL — formato `XX-XXXXXXXX-X` (11 dígitos + guiones)
- **Personas jurídicas**: CUIT — mismo formato, distinto prefijo (30/33/34)
- **Validación**: dígito verificador módulo 11 (algoritmo conocido)
- **Impacto en SAV**: `Client.cuit_cuil` y `Vet.cuit` → migrar a `tax_id_type` + `tax_id_number` en nueva arquitectura

### Identificación animal

**Bovinos:**
- **Caravana auricular**: obligatoria para todos los bovinos. Visual (plástico) + electrónica (RFID chip) para export
- **SIRTRAC** (Sistema de Información para la Rastreabilidad): base nacional de trazabilidad bovina, gestionado por Cámara Argentina de Feedlot y SENASA
- **SIGBOV**: sistema SENASA de movimiento de hacienda
- **RP** (Registro Personal): número identificatorio del animal dentro del establecimiento. Campo `Animal.rp_donor` en SAV actual
- **Nro de caravana**: número único nacional (formato varía según proveedor homologado)
- **Impacto en SAV**: modelo `Animal` necesita `caravana_number`, `rfid_id` (opcional) además de `rp_donor`

**Equinos:**
- **Documento del Equino**: libreta sanitaria individual
- **SENASA Equinos**: registro por libreta, sin RFID obligatorio nacional

**Porcinos:**
- Identificación por predio (DIE — Documento de Identificación del Establecimiento)
- Sin identificación individual obligatoria a nivel nacional

### Campañas sanitarias obligatorias

**Fiebre Aftosa:**
- Vacunación obligatoria 2x/año (abril y octubre)
- Aplica a: bovinos, bubalinos, porcinos, caprinos, ovinos
- Certificado: DTA (Declaración de Tratamiento Antiaftósico)
- Sin vacunación: no se puede mover hacienda ni emitir guía
- **Impacto en SAV**: `HealthActivity` "Vacuna Aftosa" con `months = [4, 10]`

**Brucelosis:**
- Vacunación obligatoria de vaquillonas bovinas entre 3 y 8 meses con cepa RB51 o B19
- Test de diagnóstico: BPA (Brucelosis en placa con antígeno) — anual en establecimientos de alto riesgo
- **Impacto en SAV**: `HealthActivity` "Vacuna Brucelosis" con categoría de animal específica

**Tuberculosis:**
- Test tuberculínico obligatorio en cuencas lecheras y para exportación
- Tambos: anual. Rodeos de cría: según programa provincial
- Animal positivo: sacrificio obligatorio (indemnizado)
- **Impacto en SAV**: `HealthActivity` "Test Tuberculosis"

### Plan Sanitario (mapa directo al modelo `HealthPlan`)
- Obligatorio para establecimientos con más de 50 animales en algunas provincias
- Presentación ante SENASA o Colegio de Veterinarios provincial
- Año ganadero: **1 julio al 30 junio** (no año calendario)
- Debe incluir: actividades programadas por mes, responsable veterinario (matrícula), establecimiento (RENSPA)
- **Impacto en SAV**: `HealthPlan.year` refiere al año ganadero (julio N - junio N+1)

### Documentación de movimiento
- **DT-e (Documento de Tránsito electrónico)**: reemplazó la guía de hacienda papel
- Emitido por el propietario/veterinario antes de mover animales
- Vinculado al RENSPA origen y destino
- **Impacto en SAV (futuro)**: feature de emisión/registro de DT-e vinculada a Program o evento

### Regulaciones provinciales relevantes
- **Buenos Aires**: SENASBA (Servicio Nacional de Sanidad Animal Buenos Aires) — cuenca lechera, control tuberculosis estricto
- **Córdoba**: campañas adicionales de Queratoconjuntivitis bovina
- **Santa Fe**: provincia productora lechera, control tuberculosis y brucelosis adicional
- **Corrientes/Chaco**: región NEA, brucelosis endémica, programas específicos

---

## México (expansión fase 2)

### Autoridad regulatoria
**SENASICA** — Servicio Nacional de Sanidad, Inocuidad y Calidad Agroalimentaria  
Dependencia: SADER (Secretaría de Agricultura y Desarrollo Rural)

### Identificación fiscal
- **Personas físicas**: RFC (Registro Federal de Contribuyentes) — formato `AAAA######XXX` (12-13 chars alfanumérico)
- **Personas morales**: RFC — formato `AAA######XXX` (12 chars)
- No tiene dígito verificador estándar (validación por formato)

### Identificación animal
- **SINIIGA** (Sistema Nacional de Identificación Individual de Ganado Bovino y Porcino)
  - Arete electrónico RFID homologado por SENASICA
  - Obligatorio para bovinos en predios > 5 cabezas (gradual)
  - Formato: 15 dígitos (ISO 11784/11785)
  - **Impacto en SAV**: campo `siniiga_id` en modelo `Animal`

### Registro de establecimientos
- **PREDIO** (Padrón de Unidades de Producción Pecuaria): equivalente al RENSPA
- Código de estado + municipio + número secuencial
- **Impacto en SAV**: campo `predio_code` en `Establishment` (o campo genérico `regulatory_id`)

### Campañas zoosanitarias federales
- **CPA** (Campaña contra la Pleuropneumonía Contagiosa Bovina): En zona norte
- **Campaña Brucelosis**: vacunación obligatoria con cepa RB51 (hembras 4-12 meses)
- **Campaña Tuberculosis**: erradicación en cuencas lecheras
- **PROGAN** (Programa de Fomento Ganadero): condicionado a cumplimiento sanitario

---

## Perú (expansión fase 3)

### Autoridad regulatoria
**SENASA Perú** — Servicio Nacional de Sanidad Agraria  
Dependencia: MIDAGRI (Ministerio de Desarrollo Agrario y Riego)

### Identificación fiscal
- **RUC** (Registro Único de Contribuyentes): 11 dígitos, comienza con 10 (persona natural) o 20 (empresa)
- Validación: dígito verificador módulo 11

### Identificación animal
- **Guía SENASA**: documento de movimiento de animales
- **SIAG** (Sistema de Información para Animales Genéticamente Mejorados): registro de reproductores de alto valor

### Enfermedades de notificación obligatoria Perú
- Fiebre Aftosa, Fiebre Amarilla, Brucelosis, Tuberculosis (igual que OIE lista)
- Comunidad Andina (CAN): acuerdos fitosanitarios con Bolivia, Colombia, Ecuador

---

## Colombia (expansión fase 3)

### Autoridad regulatoria
**ICA** — Instituto Colombiano Agropecuario  
Web: ica.gov.co

### Identificación fiscal
- **NIT** (Número de Identificación Tributaria): formato `XXX.XXX.XXX-X`
- **CC** (Cédula de Ciudadanía): persona natural

### Registro de establecimientos
- **PREDIO PECUARIO ICA**: registro obligatorio
- **CICLOVAC**: sistema nacional de registro de vacunaciones

---

## Chile (expansión fase 4)

### Autoridad regulatoria
**SAG** — Servicio Agrícola y Ganadero  
Web: sag.gob.cl

### Identificación fiscal
- **RUT** (Rol Único Tributario): formato `XX.XXX.XXX-X` (igual que Uruguay)
- Dígito verificador módulo 11

### Identificación animal
- **SIPEC** (Sistema de Identificación Pecuaria): RFID obligatorio para bovinos exportación
- Chile libre de Aftosa sin vacunación desde 2002 — estatus sanitario premium

---

## Brasil (expansión fase 4)

### Autoridad regulatoria
**MAPA** — Ministério da Agricultura, Pecuária e Abastecimento  
**MAPA/SDA** — Secretaria de Defesa Agropecuária

### Identificación fiscal
- **CNPJ** (empresa): `XX.XXX.XXX/XXXX-XX`
- **CPF** (persona): `XXX.XXX.XXX-XX`

### Identificación animal
- **SISBOV** (Sistema Brasileiro de Identificação e Certificação de Origem Bovina e Bubalina)
- Brinco (caravana) individual para trazabilidad

---

## OIE / WOAH (marco internacional)

**WOAH** — World Organisation for Animal Health (antes OIE)  
Web: woah.org

### Lista única de enfermedades de notificación obligatoria (selección relevante)

| Enfermedad | Especie | Importancia para SAV |
|------------|---------|---------------------|
| Fiebre Aftosa | Rumiantes, porcinos | Vacunación y control en AR, MX, PE |
| Brucelosis bovina | Bovinos | Vacunación obligatoria múltiples países |
| Tuberculosis bovina | Bovinos | Test y sacrificio sanitario |
| Influenza Aviar | Aves | Relevante para clientes avícolas |
| Peste Porcina Clásica (PPC) | Porcinos | México y Perú en riesgo |
| Peste Porcina Africana (PSA) | Porcinos | Expansión global 2024-2025 |
| Leucosis Bovina Enzoótica | Bovinos | Control en cuencas lecheras |

### Zonificación sanitaria
- **Zona libre sin vacunación**: estatus premium (Chile, parte de Brasil, Uruguay)
- **Zona libre con vacunación**: Argentina, la mayoría de Sudamérica
- **Zona de control**: zonas donde hay casos activos
- **Impacto en SAV**: un establecimiento puede tener diferente restricción de movimiento según zona

---

## Tabla resumen: Campos específicos por país en el modelo de datos

| Campo | Argentina | México | Perú | Colombia | Chile | Brasil |
|-------|-----------|--------|------|----------|-------|--------|
| `tax_id_type` | CUIT/CUIL | RFC | RUC | NIT | RUT | CNPJ/CPF |
| `tax_id_format` | `\d{2}-\d{8}-\d` | `[A-Z]{3,4}\d{6}[A-Z0-9]{3}` | `\d{11}` | `\d{3}\.\d{3}\.\d{3}-\d` | `\d{2}\.\d{3}\.\d{3}-[0-9K]` | variable |
| `establishment_regulatory_id` | RENSPA | Predio | Predio SENASA | Predio ICA | SIPEC | SISBOV |
| `animal_national_id` | Caravana/SIRTRAC | SINIIGA | SENASA | CICLOVAC | SIPEC | SISBOV |
| `regulatory_body` | SENASA | SENASICA | SENASA Perú | ICA | SAG | MAPA |
| `aftosa_vaccine_months` | [4, 10] | [3, 9] | [4, 10] | [4, 10] | N/A (libre) | [4, 10] |
| `health_plan_year_start` | julio | enero | enero | enero | enero | enero |
