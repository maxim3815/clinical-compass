import type { Directive } from 'vue';
import type { CrisisLine, UrgentAlert } from '@/types/alerts';
import type { Auth } from '@/types/auth';
import type { FlashToast } from '@/types/ui';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            // У пациента — непрочитанные сообщения, у врача — диалоги с ними.
            unreadMessages: number;
            // У врача — пациенты с открытым алертом, новыми пропусками приёма или противопоказанием без решения, у пациента 0.
            patientSignals: number;
            // У врача — непринятые алерты для красной плашки, у пациента пусто.
            urgentAlerts: UrgentAlert[];
            // Открытый ключ VAPID для подписки на пуши. У гостя null.
            vapidPublicKey: string | null;
            // Пациентка: кнопки отметки приёма в женском роде.
            femalePatient: boolean;
            // Кризисная линия на экране помощи пациента, у врача null.
            crisisLine: CrisisLine | null;
            [key: string]: unknown;
        };
        flashDataType: {
            toast?: FlashToast;
            invitationUrl?: string;
            passwordResetUrl?: string;
            // Пациент нажал «Сообщить врачу»: время отправки ЧЧ:ММ.
            helpReportedAt?: string;
            // «Сообщить врачу» не принято: врача нет или правило по кнопке выключено.
            helpReportRefused?: boolean;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
