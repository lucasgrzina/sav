import { nextTick, ref, shallowRef } from 'vue';

interface ConfirmOptions {
    title?: string;
    message: string;
    confirmLabel?: string;
    cancelLabel?: string;
    danger?: boolean;
    onConfirm?: () => Promise<void> | void;
}

const visible      = shallowRef(false);
const loading      = shallowRef(false);
const options      = ref<ConfirmOptions>({ message: '' });
let resolvePromise: ((v: boolean) => void) | null = null;

export function useConfirm() {
    function confirm(opts: ConfirmOptions): Promise<boolean> {
        options.value = opts;
        visible.value = true;
        return new Promise(resolve => {
            resolvePromise = resolve;
        });
    }

    async function accept() {
        if (options.value.onConfirm) {
            loading.value = true;
            await nextTick();
            try {
                await options.value.onConfirm();
                resolvePromise?.(true);
            } catch {
                resolvePromise?.(false);
            } finally {
                loading.value = false;
                visible.value = false;
                resolvePromise = null;
            }
        } else {
            visible.value = false;
            resolvePromise?.(true);
            resolvePromise = null;
        }
    }

    function cancel() {
        visible.value = false;
        resolvePromise?.(false);
        resolvePromise = null;
    }

    return { visible, loading, options, confirm, accept, cancel };
}
