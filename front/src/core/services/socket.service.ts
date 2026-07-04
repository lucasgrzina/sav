import { getEcho, destroyEcho } from './echo.service'
import eventBus from './event-bus.service'
import type Echo from 'laravel-echo'

class SocketService {
  private connected = false
  private echo: Echo<'reverb'> | null = null
  private userId: number | null = null

  connect(userId: number): void {
    if (this.connected) return

    this.echo   = getEcho()
    this.userId = userId

    this.echo
      .private(`app.user.${userId}`)
      .listen('.app.event', (data: { event: string; payload: unknown }) => {
        eventBus.emit(data.event, data.payload)
      })

    this.connected = true
  }

  disconnect(): void {
    if (!this.connected) return

    if (this.echo && this.userId !== null) {
      this.echo.leave(`app.user.${this.userId}`)
    }
    destroyEcho()

    this.connected = false
    this.echo      = null
    this.userId    = null
  }

  isConnected(): boolean {
    return this.connected
  }
}

export const socketService = new SocketService()
export default socketService
