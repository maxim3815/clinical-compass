import type { Directive } from 'vue';
import type { SideEffectReport } from '@/types/adverse-events';
import type {
    CrisisLine,
    UrgentAlert,
    UrgentQueueAnswer,
} from '@/types/alerts';
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
            // Ответы из очереди с сигналом по 9-му вопросу, у терапевта.
            urgentQueue: UrgentQueueAnswer[];
            // Открытый ключ VAPID для подписки на пуши. У гостя null.
            vapidPublicKey: string | null;
            // Пациентка: кнопки отметки приёма в женском роде.
            femalePatient: boolean;
            // Кризисная линия на экране помощи пациента, у врача null.
            crisisLine: CrisisLine | null;
            // Вид врача: у терапевта своё меню и кабинет. У пациента null.
            doctorKind: 'psychiatrist' | 'therapist' | null;
            // Пациента ведёт терапевт: чата и окна побочных эффектов нет.
            withTherapist: boolean;
            rulesPending: boolean;
            [key: string]: unknown;
        };
        flashDataType: {
            toast?: FlashToast;
            // Пациент только что добавил свой препарат: id назначения, к нему прокручивается список.
            addedMedication?: number;
            invitationUrl?: string;
            passwordResetUrl?: string;
            // Пациент нажал «Сообщить врачу»: время отправки ЧЧ:ММ.
            helpReportedAt?: string;
            // «Сообщить врачу» не принято: врача нет или правило по кнопке выключено.
            helpReportRefused?: boolean;
            // Пациент отправил побочные эффекты: что показать после отправки.
            sideEffectReport?: SideEffectReport;
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
