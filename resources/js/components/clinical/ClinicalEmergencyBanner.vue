<script setup lang="ts">
interface Props {
    title?: string;
    message?: string;
    emergencyPhone?: string;
    crisisHotline?: string;
}

withDefaults(defineProps<Props>(), {
    title: 'Immediate Crisis Support',
    message:
        'If you are in distress or need immediate support, you are not alone.',
    emergencyPhone: '112',
    crisisHotline: '8-800-200-47-03',
});
</script>

<template>
    <div
        class="flex flex-col gap-4 rounded-3xl border border-[#BADBCC] bg-[#EAF4EE] p-6 font-sans text-[#1E293B] shadow-xs"
    >
        <div>
            <h4 class="text-base font-bold text-[#1E293B]">{{ title }}</h4>
            <p class="mt-1 text-xs text-[#64748B]">{{ message }}</p>
        </div>

        <div class="flex flex-col gap-2">
            <a
                :href="`tel:${emergencyPhone}`"
                class="flex h-12 w-full items-center justify-center gap-2 rounded-2xl border border-[#E2E8F0] bg-white text-sm font-bold text-[#1E293B] shadow-xs transition-colors hover:border-[#235347]"
            >
                <span>📞</span>
                <span>Call {{ emergencyPhone }} (Emergency)</span>
            </a>

            <a
                :href="`tel:${crisisHotline.replace(/\D/g, '')}`"
                class="flex h-12 w-full items-center justify-center gap-2 rounded-2xl border border-[#E2E8F0] bg-white text-sm font-semibold text-[#235347] shadow-xs transition-colors hover:border-[#235347]"
            >
                <span>📞</span>
                <span>Crisis Line {{ crisisHotline }}</span>
            </a>
        </div>
    </div>
</template>
