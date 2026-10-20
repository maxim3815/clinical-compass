<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    status: 'safe' | 'caution' | 'contraindicated';
    title: string;
    details?: string;
    guidelineQuote?: string;
}

const props = defineProps<Props>();

const statusClasses = computed(() => {
    switch (props.status) {
        case 'safe':
            return {
                bg: 'bg-[#D1E7DD]/50 border-[#A3C9B8]',
                iconBg: 'bg-[#0F5132] text-white',
                icon: '✓',
                titleColor: 'text-[#0F5132]',
            };
        case 'caution':
            return {
                bg: 'bg-[#FEF3C7]/60 border-[#FCD34D]',
                iconBg: 'bg-[#D97706] text-white',
                icon: '⚠️',
                titleColor: 'text-[#B45309]',
            };
        case 'contraindicated':
        default:
            return {
                bg: 'bg-[#FEE2E2]/60 border-[#FCA5A5]',
                iconBg: 'bg-[#DC2626] text-white',
                icon: '✕',
                titleColor: 'text-[#991B1B]',
            };
    }
});
</script>

<template>
    <div
        :class="[
            'flex flex-col gap-2 rounded-3xl border p-5 font-sans shadow-xs transition-all',
            statusClasses.bg,
        ]"
    >
        <div class="flex items-start gap-3">
            <div
                :class="[
                    'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold',
                    statusClasses.iconBg,
                ]"
            >
                {{ statusClasses.icon }}
            </div>

            <div>
                <h4
                    :class="[
                        'text-sm leading-snug font-bold',
                        statusClasses.titleColor,
                    ]"
                >
                    {{ title }}
                </h4>
                <p
                    v-if="details"
                    class="mt-0.5 text-xs leading-relaxed text-[#475569]"
                >
                    {{ details }}
                </p>
                <div
                    v-if="guidelineQuote"
                    class="mt-2 rounded-xl border border-black/5 bg-white/70 p-2.5 text-[11px] text-[#475569] italic"
                >
                    «{{ guidelineQuote }}»
                </div>
            </div>
        </div>
    </div>
</template>
