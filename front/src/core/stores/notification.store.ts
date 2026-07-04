import { defineStore } from 'pinia';
import { message } from 'ant-design-vue';

export interface Toast {
    id: string;
    type: 'success' | 'error' | 'warning' | 'info';
    message: string;
}

interface NotificationState {
    toasts: Toast[];
}

export const useNotificationStore = defineStore('notification', {
    state: (): NotificationState => ({
        toasts: [],
    }),

    actions: {
        push(type: Toast['type'], message_text: string): void {
            if (type === 'success') message.success(message_text, 4);
            else if (type === 'error') message.error(message_text, 4);
            else if (type === 'warning') message.warning(message_text, 4);
            else message.info(message_text, 4);
        },

        remove(_id: string): void {
            // No-op: ant-design message se auto-gestiona
        },
    },
});
