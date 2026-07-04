import { createI18n } from 'vue-i18n';
import global from './locales/es/global';
import auth from './locales/es/auth';
import supportMessages from './locales/es/support-messages';

export const i18n = createI18n({
    legacy: false,
    locale: 'es',
    fallbackLocale: 'es',
    messages: {
        es: {
            global,
            auth,
            ...supportMessages,
        },
    },
    missingWarn: import.meta.env.DEV,
});
