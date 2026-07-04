import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { http } from '@/core/api/http'

window.Pusher = Pusher

const REVERB_APP_KEY      = import.meta.env.VITE_REVERB_APP_KEY as string
const REVERB_HOST         = import.meta.env.VITE_REVERB_HOST as string
const REVERB_PORT         = Number(import.meta.env.VITE_REVERB_PORT ?? 8080)
const REVERB_SCHEME       = import.meta.env.VITE_REVERB_SCHEME as string
const BROADCAST_AUTH_URL  = import.meta.env.VITE_REVERB_BROADCAST_AUTH as string

let echoInstance: Echo<'reverb'> | null = null

export function getEcho(): Echo<'reverb'> {
  if (echoInstance) return echoInstance

  echoInstance = new Echo({
    broadcaster:       'reverb',
    key:               REVERB_APP_KEY,
    wsHost:            REVERB_HOST,
    wsPort:            REVERB_SCHEME === 'https' ? 443 : REVERB_PORT,
    wssPort:           REVERB_SCHEME === 'https' ? 443 : REVERB_PORT,
    forceTLS:          REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: { name: string }) => ({
      authorize: (
        socketId: string,
        callback: (error: boolean, data: unknown) => void,
      ) => {
        http
          .post(BROADCAST_AUTH_URL, {
            socket_id:    socketId,
            channel_name: channel.name,
          })
          .then((res) => callback(false, res.data))
          .catch((err) => callback(true, err))
      },
    }),
  })

  return echoInstance
}

export function destroyEcho(): void {
  if (echoInstance) {
    echoInstance.disconnect()
    echoInstance = null
  }
}
