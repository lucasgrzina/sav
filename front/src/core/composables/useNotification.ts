import { useNotificationStore } from '@/core/stores/notification.store';
import { storeToRefs } from 'pinia';

export function useNotification() {
    const store = useNotificationStore();
    const { toasts } = storeToRefs(store);

    return {
        toasts,
        success: (msg: string) => store.push('success', msg),
        error: (msg: string) => store.push('error', msg),
        warning: (msg: string) => store.push('warning', msg),
        info: (msg: string) => store.push('info', msg),
    };
}
