<script setup lang="ts">
import ClinicalButton from './ClinicalButton.vue';

interface Props {
    pinCode?: string;
    expiresInText?: string;
}

withDefaults(defineProps<Props>(), {
    pinCode: '8419',
    expiresInText: '14:30',
});

const emit = defineEmits<{
    (e: 'regenerate'): void;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center gap-6 rounded-3xl border border-[#E2E8F0] bg-white p-6 font-sans shadow-xs md:flex-row"
    >
        <!-- Mock Vector QR code -->
        <div
            class="flex h-32 w-32 shrink-0 items-center justify-center rounded-2xl border-2 border-[#E2E8F0] bg-white p-2 shadow-xs"
        >
            <div class="grid h-full w-full grid-cols-6 grid-rows-6 gap-1 p-1">
                <div
                    class="col-span-2 row-span-2 rounded-xs bg-[#1E293B]"
                ></div>
                <div class="rounded-xs bg-[#1E293B]"></div>
                <div class="rounded-xs bg-[#1E293B]"></div>
                <div
                    class="col-span-2 row-span-2 rounded-xs bg-[#1E293B]"
                ></div>
                <div class="rounded-xs bg-[#1E293B]"></div>
                <div class="rounded-xs bg-[#1E293B]"></div>
                <div class="rounded-xs bg-[#1E293B]"></div>
                <div class="rounded-xs bg-[#1E293B]"></div>
                <div class="rounded-xs bg-[#1E293B]"></div>
                <div class="rounded-xs bg-[#1E293B]"></div>
                <div
                    class="col-span-2 row-span-2 rounded-xs bg-[#1E293B]"
                ></div>
                <div class="rounded-xs bg-[#1E293B]"></div>
                <div class="rounded-xs bg-[#1E293B]"></div>
                <div class="rounded-xs bg-[#1E293B]"></div>
                <div class="rounded-xs bg-[#1E293B]"></div>
            </div>
        </div>

        <div
            class="flex flex-1 flex-col items-center text-center md:items-start md:text-left"
        >
            <span class="text-xs font-semibold text-[#64748B]">
                Быстрое подключение на приёме
            </span>
            <h4 class="mt-0.5 text-lg font-bold text-[#1E293B]">
                Сканируйте QR или назовите PIN
            </h4>

            <div class="mt-3 flex items-center gap-2">
                <div
                    v-for="(digit, idx) in pinCode.split('')"
                    :key="idx"
                    class="flex h-13 w-11 items-center justify-center rounded-xl border-2 border-[#E2E8F0] bg-[#F8FAFC] text-2xl font-bold text-[#1E293B]"
                >
                    {{ digit }}
                </div>
            </div>

            <div class="mt-3 flex items-center gap-3">
                <span class="flex items-center gap-1 text-xs text-[#64748B]">
                    <span>🕒</span>
                    <span>Действует: {{ expiresInText }}</span>
                </span>

                <button
                    type="button"
                    class="text-xs font-semibold text-[#235347] hover:underline"
                    @click="emit('regenerate')"
                >
                    Обновить PIN
                </button>
            </div>
        </div>
    </div>
</template>
