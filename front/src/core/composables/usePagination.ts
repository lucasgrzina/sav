import { computed, shallowRef } from 'vue'

export function usePagination(defaultPerPage = 10) {
  const page = shallowRef(1)
  const perPage = shallowRef(defaultPerPage)
  const total = shallowRef(0)

  const totalPages = computed(() => Math.ceil(total.value / perPage.value))

  function reset() {
    page.value = 1
  }

  return { page, perPage, total, totalPages, reset }
}
