import { storeToRefs } from 'pinia'
import { useNotificationStore } from '../stores/notifications.store'

export function useNotifications() {
  const store = useNotificationStore()
  const { unreadCount, list, latest, pagination, loading } = storeToRefs(store)

  return {
    unreadCount,
    list,
    latest,
    pagination,
    loading,
    fetch:         store.fetch,
    fetchLatest:   store.fetchLatest,
    markAsRead:    store.markAsRead,
    markAllAsRead: store.markAllAsRead,
    pushRealtime:  store.pushRealtime,
  }
}
