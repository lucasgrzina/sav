import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useDashboardStore = defineStore('dashboard-ui', () => {
    const activePanel = ref<string>('overview')

    return { activePanel }
})
