import { ref } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import { searchAnimalsApi } from '../api/animal.api'
import type { AnimalOption } from '../types/program.types'

// vetGuid/clientGuid se pasan como getters (funciones) en vez de valores directos porque el
// client_id seleccionado en el form cambia dinámicamente — la búsqueda siempre debe usar el
// cliente actual del form, no uno capturado en el momento de crear el composable.
export function useAnimalSearch(vetGuid: () => string, clientGuid: () => string) {
  const options = ref<AnimalOption[]>([])
  const loading = ref(false)

  const search = useDebounceFn(async (value: string) => {
    if (!clientGuid()) {
      options.value = []
      return
    }
    loading.value = true
    try {
      options.value = await searchAnimalsApi(vetGuid(), clientGuid(), value)
    } finally {
      loading.value = false
    }
  }, 300)

  function reset() {
    options.value = []
  }

  return { options, loading, search, reset }
}
