type EventCallback<T = unknown> = (payload: T) => void

class EventBusService {
  private listeners: Record<string, EventCallback[]> = {}

  on<T = unknown>(event: string, callback: EventCallback<T>): () => void {
    if (!this.listeners[event]) {
      this.listeners[event] = []
    }
    this.listeners[event].push(callback as EventCallback)

    return () => this.off(event, callback as EventCallback)
  }

  off(event: string, callback: EventCallback): void {
    if (!this.listeners[event]) return
    this.listeners[event] = this.listeners[event].filter((cb) => cb !== callback)
  }

  emit<T = unknown>(event: string, payload: T): void {
    if (!this.listeners[event]) return
    this.listeners[event].forEach((cb) => cb(payload))
  }
}

export const eventBus = new EventBusService()
export default eventBus
