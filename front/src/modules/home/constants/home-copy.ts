// User-facing copy of the vet "Inicio" screen. Failure and type labels are reused from the
// program alerts copy so the same code reads the same everywhere.

export const HOME_COPY = {
  title: 'Inicio',
  greeting: (name: string | null | undefined) => (name ? `Hola, ${name}` : 'Hola'),
  subtitle: 'Un resumen de lo que viene y de lo que necesita tu atención.',
  refresh: 'Actualizar',
  errorMessage: 'No pudimos cargar tu resumen. Probá de nuevo en unos segundos.',
  noAccess: 'Todavía no tenés acceso a los programas. Podés ver los datos de la empresa desde el menú.',
  goToProfile: 'Ir a la empresa',
  upcoming: {
    title: 'Próximos programas',
    empty: 'No hay programas con fecha próxima.',
    seeAll: 'Ver todos los programas',
    more: 'Hay más programas. Mostramos los más próximos.',
  },
  recent: {
    title: 'Avisos recientes',
    more: 'Hay más avisos. Mostramos los más recientes.',
    empty: 'Todavía no se enviaron avisos.',
    sentAt: (label: string) => `Enviado ${label}`,
    noTask: 'Aviso del programa',
  },
  problems: {
    title: 'Avisos con problemas',
    empty: 'No hay avisos con problemas. Todo en orden.',
    scheduledFor: (label: string) => `Era para ${label}`,
    open: 'Ver avisos del programa',
    more: 'Hay más avisos con problemas. Mostramos los más recientes.',
  },
} as const
