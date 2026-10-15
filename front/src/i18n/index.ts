import { createI18n } from 'vue-i18n';
import global from './locales/es/global';
import auth from './locales/es/auth';
import supportMessages from './locales/es/support-messages';
import dashboard from './locales/es/dashboard';

export const i18n = createI18n({
    legacy: false,
    locale: 'es',
    fallbackLocale: 'es',
    messages: {
        es: {
            global,
            auth,
            ...supportMessages,
            ...dashboard,
        },
    },
    missingWarn: import.meta.env.DEV,
});
