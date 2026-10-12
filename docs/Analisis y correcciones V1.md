# **Minuta de revisión de SAV** 

**Fecha:** 11 de octubre de 2026 **De:** Martín **Para:** Gustavo y Lucas **CC** : Edgardo **Asunto:** Observaciones y propuestas para revisar 

Comparto una devolución sobre el recorrido de la versión de prueba de SAV. La intención es aportar al desarrollo, reconocer lo que está bien encaminado y poner el foco en los aspectos que pueden fortalecer la claridad y la confianza del usuario. 

Las observaciones surgen de navegar pantallas y formularios con ChatWork. 

Diferenciamos a continuación las situaciones observadas de las propuestas que todavía debemos evaluar. 

# **1. El propósito que queremos resolver** 

SAV registra y convierte protocolos veterinarios en tareas con fechas y horarios concretos, y envía avisos automáticos preestablecidos por WhatsApp a veterinarios, propietarios y personal del campo. 

Permite organizar programas reproductivos y planes sanitarios por establecimiento, para que cada persona sepa qué tiene que hacer y cuándo. 

Su valor central es reducir el trabajo de coordinación: que el veterinario pueda preparar un programa y no tenga que recordar y escribir manualmente cada aviso. 

# **2. Lo que encontramos bien encaminado** 

- La organización por empresa, cliente y establecimiento tiene sentido para el trabajo cotidiano. 

- La reutilización de protocolos y plantillas puede ahorrar carga repetitiva. 

- La configuración de tareas y alertas con tiempos propios permite anticipar preparativos y recordar intervenciones. 

- La selección de destinatarios por función —veterinario, propietario, encargado o administrativo— refleja una necesidad concreta. 

- La sugerencia de completar establecimientos, contactos y personal en ese orden ayuda a orientar al usuario. 

- La presentación general se percibe limpia y los botones principales son reconocibles. 

- La opción “Requiere confirmación” abre una posibilidad interesante, cuyo funcionamiento convendría explicar mejor. 

La estructura observada responde al propósito de SAV. El siguiente paso es hacer que esa lógica resulte fácil de configurar y verificar. 

# **3. Puntos que nos gustaría revisar primero.** 

# **Consistencia de fechas** 

En un mismo programa de Agropecuaria ID, en Estancia La Invernada, con el protocolo de superovulación P500: 

- El listado y el detalle muestran **23/09/2026** . 

- El formulario de edición muestra **24/09/2026** como fecha de colecta de embriones. 

Revisemos que la fecha se mantenga consistente entre pantallas y hasta la programación del aviso. No se comprobó cuál de las fechas utiliza el envío automático ni se identificó la causa de la diferencia. 

# **Consistencia de responsables** 

El detalle del mismo programa muestra propietario, encargado y administrativo. Sin embargo, al editar aparece: 

“Este establecimiento no tiene personal del cliente vinculado”. 

En la ficha del cliente, esas personas aparecen sin establecimientos asignados. 

Sería bueno revisar cómo conviven las asignaciones del programa con las del establecimiento y qué ocurre al editar y guardar. No se probó si guardar altera los destinatarios. 

# **Referencia temporal de las alertas** 

Las tareas y las alertas tienen campos de días y opciones “Antes/Después”. Conviene indicar expresamente respecto de qué se calcula cada uno: 

- La fecha objetivo del programa. 

- La fecha de la tarea correspondiente. 

Una pequeña aclaración puede evitar errores importantes de configuración. 

# **Significado de “Requiere confirmación”** 

Aclarar qué confirma el destinatario: 

- Que recibió o leyó el aviso. 

- Que realizará la tarea. 

- Que ya realizó la tarea. 

También sería útil mostrar dónde se consulta esa respuesta y qué ocurre cuando no llega. Son significados diferentes y conviene que el usuario no tenga que deducirlos. 

# **Estados y fechas pasadas** 

Se observaron dos programas muy similares, activos, con “Próximo objetivo” el 23/09/2026, una fecha anterior a la revisión. 

Pueden ser datos de prueba. Aun así, vale la pena revisar cómo se distinguirán programas activos, finalizados, cancelados o con fechas pendientes de actualizar, y qué significa exactamente “Próximo objetivo”. 

# **4. Hacer verificable la automatización** 

La confianza en SAV depende de que el usuario pueda comprobar que el aviso correcto quedó preparado para la persona correcta y en el momento correcto. 

# **Resumen antes de guardar o activar** 

Proponemos evaluar una vista que reúna: 

- Cliente y establecimiento. 

- Protocolo y grupo de animales, cuando corresponda. 

- Tarea y fecha prevista. 

- Texto del WhatsApp. 

- Persona destinataria, función y número. 

- Fecha y hora de envío. 

El usuario debería poder entender: **“Este mensaje se enviará a esta persona, en este número, este día y a esta hora”.** 

En ese momento también convendría advertir sobre funciones sin destinatario, personas sin WhatsApp o fechas que ya quedaron atrás. 

# **Visibilidad de los envíos** 

En las pantallas recorridas se vieron mensajes previstos, fechas y destinatarios, pero no se encontró un historial claro de envíos. 

Sería útil poder consultar qué aviso está programado, cuál fue enviado y cuál tuvo un inconveniente. Los estados de entrega, lectura o confirmación deberían mostrarse únicamente cuando exista información que los respalde. 

No se concluye que esta funcionalidad esté ausente en todo el sistema: no quedó visible durante el recorrido. 

# **Cambios y cancelaciones** 

Revisar especialmente qué sucede cuando se modifica: 

- La fecha objetivo. 

- El protocolo. 

- Un responsable o su teléfono. 

- La composición de un grupo. 

- El estado del programa. 

La expectativa es que los avisos pendientes queden actualizados y no permanezcan mensajes anteriores que puedan confundir. También convendría prevenir envíos duplicados y dejar claros los cambios que afectan comunicaciones futuras. 

# **Prioridad del seguimiento** 

El registro de tareas realizadas puede aportar valor, pero no lo pondríamos por delante de la confiabilidad de los avisos. El compromiso central de SAV es organizar y comunicar correctamente. 

Enviar un aviso, recibir una confirmación y realizar una tarea son hechos distintos. 

# **5. WhatsApp claro y con contexto** 

Muchos destinatarios tendrán su principal contacto con SAV a través de WhatsApp. Por eso, proponemos revisar el mensaje tal como lo recibe una persona en el campo. 

Que permita identificar: 

- El veterinario o empresa responsable. 

- El establecimiento. 

- El programa o grupo, cuando corresponda. 

- La tarea. 

- La fecha y hora. 

- Qué respuesta se espera, si corresponde. 

Si alguien participa en varios programas, debería entender cada mensaje sin buscar conversaciones anteriores. 

También convendría acompañar “hoy” o “mañana” con la fecha concreta, para evitar confusiones si el aviso se lee más tarde, y revisar la cantidad de mensajes para que las comunicaciones importantes no se pierdan entre avisos repetitivos. 

# **6. Lenguaje cercano al usuario** 

Proponemos repasar los textos pensando en veterinarios y encargados de campo. Hay oportunidades de mejorar la comprensión con palabras familiares, nombres consistentes y ayudas breves. 

# **Cambios de redacción sugeridos** 

**Texto observado Propuesta** Planes sanitarios instanciados por Planes sanitarios creados para cada establecimiento establecimiento Instanciá una plantilla del catálogo Elegí una plantilla y creá un plan sanitario para un sobre un establecimiento establecimiento No hay planes sanitarios Todavía no creaste planes sanitarios instanciados todavía Staff Personal del cliente Editar perfil de staff Editar datos del personal Agregar miembro Agregar persona Flags Opciones de contacto Teléfono o correo electrónico, según el tipo Valor, en contactos elegido Tipo, en contactos Tipo de contacto Referencia, con ejemplos como “Administración” Etiqueta, en contactos o “Personal” Principal, en contactos Contacto principal Alertas, como indicador del Usado para alertas contacto Función, cuando se refiere al trabajo de la Rol persona; distinguirlo de los permisos de acceso Destinatarios del aviso, aclarando que se eligen Roles que reciben la alerta por función Fecha de incorporación o Fecha de creación, Alta / Fecha de alta según corresponda Clientes vinculados a esta empresa Clientes de esta empresa Vincular, al incorporar un cliente Agregar a mis clientes existente Quitar de mis clientes, explicando qué Desvincular información se conserva Personal vinculado Personal asignado Sin personal vinculado Sin personal asignado Sin establecimientos Sin establecimientos asignados Global, como origen del protocolo Del catálogo de SAV Protocolos globales y propios de tu Protocolos de SAV y de tu empresa empresa Plantillas del sistema Plantillas de SAV Nueva plantilla propia Crear mi plantilla Añadir a actividades Agregar actividad Mensajes, cuando abre soporte Soporte o Ayuda y soporte Email / Agregar email Correo electrónico / Agregar correo No data Todavía no hay registros, adaptado a cada sección 

Seleccioná la hora 

# Select time 

Los cambios deben preservar el funcionamiento real. En particular, “Quitar de mis clientes” necesita una explicación precisa de sus consecuencias. 

# **Conceptos que necesitan consistencia o explicación** 

- **Técnica, Programa, Tipo de programa y Protocolo:** usar cada término con el mismo significado en listas, detalles y formularios. En el recorrido, algunos rótulos no permitieron distinguirlos con facilidad. 

- **Programas:** evaluar “Programas reproductivos” en el menú para diferenciarlos de los planes sanitarios. 

- **Plantillas:** evaluar “Plantillas de planes sanitarios” y explicar que son modelos reutilizables. 

- **Fecha objetivo:** nombrar el evento concreto cuando sea posible, como ya sucede con “Fecha de colecta de embriones”. 

- **Próximo objetivo y Objetivos:** aclarar qué fecha y qué cantidad representan. Si se cuentan grupos, decir “Cantidad de grupos”; si se cuentan eventos, nombrarlos. 

- **Días de diferencia y Antes/Después:** indicar la fecha de referencia. 

- **Requiere confirmación:** expresar qué se solicita confirmar. 

- **Sin alertas para esta tarea:** evaluar “Esta tarea no tiene avisos programados”. 

- **Sin animales:** evaluar “Sin animales registrados en este grupo”. 

- **Buscar RP existente o escribir uno nuevo:** evaluar “Buscá o ingresá el número de identificación del animal (RP)”, previa confirmación del significado del campo. 

- **Activo:** explicar qué implica para el programa y para sus avisos. 

- **Enviar y Descargar:** indicar qué se envía o descarga y por qué canal. 

- **Alertas, Avisos y Notificaciones:** diferenciar los WhatsApp programados de las notificaciones internas. 

- **Usuarios, Personal y Contactos:** aclarar quién accede al sistema, quién participa en el trabajo y qué datos se usan para comunicarse. 

Mantendríamos términos habituales del ámbito veterinario, como protocolo, establecimiento, IATF y RENSPA, con ayudas breves cuando hagan falta. 

# **7. Primeros pasos y uso desde el celular** 

# **Pantalla inicial** 

Actualmente el ingreso lleva al perfil de la empresa. Proponemos evaluar una entrada más orientada al trabajo: próximos programas, avisos por enviar y situaciones que requieren atención. 

Los datos de la empresa siguen siendo necesarios, pero una vista operativa podría comunicar mejor el valor cotidiano de SAV. 

# **Recorrido de configuración** 

Sería útil que el sistema explique el camino para dejar un programa listo: 

# **Cliente → establecimiento → personal y WhatsApp → protocolo → fecha y responsables → revisión de avisos.** 

La guía existente en la ficha del cliente es una buena base. 

# **Vista angosta** 

En la vista angosta observada, algunas columnas y acciones de las tablas quedan fuera del área visible y ciertos títulos se cortan. 

Conviene revisar especialmente las acciones frecuentes desde el celular: consultar un programa, identificar responsables y verificar próximos avisos. Esta observación no equivale a una prueba completa en distintos dispositivos. 

# **8. Utilidad y oportunidad de SAV** 

La idea tiene una utilidad concreta: aliviar la coordinación repetitiva entre el veterinario y las personas que ejecutan los trabajos. 

La hipótesis más prometedora es su uso por veterinarios que manejan varios establecimientos y programas simultáneos. Para quienes llevan pocos programas, una agenda y los mensajes manuales pueden seguir resultando suficientes. 

El envío de WhatsApp, por sí solo, ofrece poca diferenciación. La oportunidad está en combinar: 

- Protocolos veterinarios bien preparados. 

- Configuración rápida. 

- Coordinación por funciones y establecimientos. 

- Fechas y destinatarios confiables. 

- Facilidad para repetir y reprogramar trabajos. 

Todavía no tenemos evidencia suficiente para afirmar demanda comercial, disposición a pagar o mejora de resultados. Eso necesita validarse con usuarios reales. 

# **9. Referencias del mercado** 

Una búsqueda inicial encontró propuestas relacionadas: 

|**Producto**|**Funciones que anuncia**|**Relación con SAV**|
|---|---|---|
|Zap Agro IA<br>— Brasil|Gestión ganadera mediante WhatsApp,<br>protocolos de IATF, agenda de manejo,<br>calendario sanitario y alertas por<br>WhatsApp.|Es una referencia cercana,<br>con un alcance de gestión<br>más amplio.|
|GanaderoAI|Gestión de varias fincas por WhatsApp,<br>historial sanitario y reproductivo,<br>protocolos sanitarios automáticos y<br>recordatorios.|Coincide parcialmente en<br>coordinación y alertas.|
||Recordatorios automáticos de citas,|Comparte el canal de|
|Mivete|vacunas y desparasitación por WhatsApp<br>para clínicas veterinarias.|comunicación, en otro<br>contexto de trabajo.|



La información corresponde a lo publicado por los proveedores. No se probaron esos productos ni se verificó su adopción. La búsqueda muestra propuestas similares, pero no permite afirmar que reproduzcan exactamente el flujo de SAV. 

# **10. Aspectos que conviene cuidar durante el desarrollo** 

- **Esfuerzo de configuración:** que cargar un programa no termine costando más trabajo que organizarlo manualmente. 

- **Confianza:** que los fallos de envío sean visibles y que las fechas y destinatarios sean consistentes. 

- **Reprogramación:** que cambiar un trabajo no deje avisos anteriores pendientes. 

- **Cantidad de mensajes:** que cada aviso sea pertinente y no genere saturación. 

- **Alcance:** priorizar la función central antes de sumar módulos que aumenten la complejidad. 

- **Resultados:** distinguir entre mensajes enviados, tareas realizadas y beneficios comprobados. 

# **11. Propuesta de validación** 

Cuando las funciones centrales estén listas para uso real, proponemos un piloto con **5 a 10 veterinarios ajenos al desarrollo** , durante programas completos. 

Observar: 

- Cuánto tardan en configurar el primer programa. 

- En qué pasos necesitan ayuda. 

- Cuánto trabajo manual les ahorra. 

- Qué sucede al cambiar fechas o responsables. 

- Qué dificultades encuentran los destinatarios. 

- Si vuelven a usar SAV por iniciativa propia. 

- Si están dispuestos a pagar por el servicio. 

La reutilización espontánea en el siguiente programa sería una señal más valiosa que una buena reacción durante una demostración. 

# **12. Solicitud sobre mi cuenta** 

Por favor, cambiar el correo de mi usuario de SAV: 

**Actual:** <u>mmujica@imtesa.com.ar</u> 

**Nuevo:** <u>martinmuji@gmail.com</u> 

Conservar los datos, permisos y programas asociados. Este cambio está solicitado; no se realizó durante la revisión. 

# **13. Orden de trabajo propuesto** 

Para que esta devolución sea manejable, sugiero comenzar el lunes por: 

1. Contrastar la diferencia de fechas y las asignaciones de responsables. 

2. Aclarar las referencias temporales y el significado de confirmación. 

3. Revisar cómo se verifican los avisos y cómo se actualizan al reprogramar. 

4. Incorporar las mejoras de lenguaje más directas. 

5. Definir las condiciones del piloto con usuarios externos. 

No es necesario resolver toda la lista de una vez. La intención es acompañar el desarrollo con prioridades concretas y seguir construyendo un SAV sencillo, útil y confiable. 

# **Saludos** 

**Martín** 

