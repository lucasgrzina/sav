import { defineStore } from 'pinia'
import {
  getLatestNotificationsApi,
  listNotificationsApi,
  markAsReadApi,
  markAllAsReadApi,
} from '../api/notifications.api'
import type { NotificationItem, NotificationRealtimePayload } from '../types/notification.types'

interface NotificationPagination {
  currentPage: number
  lastPage: number
  perPage: number
  total: number
}

export const useNotificationStore = defineStore('notifications', {
  state: () => ({
    unreadCount: 0 as number,
    list: [] as NotificationItem[],
    latest: [] as NotificationItem[],
    pagination: {
      currentPage: 1,
      lastPage: 1,
      perPage: 15,
      total: 0,
    } as NotificationPagination,
    loading: false as boolean,
  }),

  actions: {
    async fetch(page = 1): Promise<void> {
      this.loading = true
      try {
        const res = await listNotificationsApi({ page, per_page: this.pagination.perPage })
        this.list        = res.notifications.data
        this.unreadCount = res.unread_count
        this.pagination  = {
          currentPage: res.notifications.current_page,
          lastPage:    res.notifications.last_page,
          perPage:     res.notifications.per_page,
          total:       res.notifications.total,
        }
      } finally {
        this.loading = false
      }
    },

    async fetchLatest(): Promise<void> {
      const res        = await getLatestNotificationsApi()
      this.latest      = res.notifications
      this.unreadCount = res.unread_count
    },

    async markAsRead(guid: string): Promise<void> {
      await markAsReadApi(guid)

      const updateItem = (item: NotificationItem) => {
        if (item.guid === guid && item.read_at === null) {
          item.read_at     = new Date().toISOString()
          this.unreadCount = Math.max(0, this.unreadCount - 1)
        }
      }

      this.list.forEach(updateItem)
      this.latest.forEach(updateItem)
    },

    async markAllAsRead(): Promise<void> {
      await markAllAsReadApi()

      const now = new Date().toISOString()
      this.list.forEach((item) => { item.read_at = item.read_at ?? now })
      this.latest.forEach((item) => { item.read_at = item.read_at ?? now })
      this.unreadCount = 0
    },

    pushRealtime(notification: NotificationRealtimePayload): void {
      if (this.latest.some((n) => n.guid === notification.guid)) return

      const item: NotificationItem = {
        guid:       notification.guid,
        payload:    notification.data,
        read_at:    notification.is_read ? new Date().toISOString() : null,
        created_at: new Date().toISOString(),
      }

      this.latest.unshift(item)

      if (this.latest.length > 10) {
        this.latest.pop()
      }

      if (!notification.is_read) {
        this.unreadCount++
      }
    },

    reset(): void {
      this.$reset()
    },
  },
})
