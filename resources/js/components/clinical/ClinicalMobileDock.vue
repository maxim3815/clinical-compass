<script setup lang="ts">
interface TabItem {
    id: string;
    label: string;
    icon: string;
}

interface Props {
    activeTab?: string;
    tabs?: TabItem[];
}

withDefaults(defineProps<Props>(), {
    activeTab: 'home',
    tabs: () => [
        { id: 'home', label: 'Главная', icon: '🏠' },
        { id: 'medications', label: 'Препараты', icon: '💊' },
        { id: 'insights', label: 'Аналитика', icon: '📊' },
        { id: 'more', label: 'Ещё', icon: '•••' },
    ],
});

const emit = defineEmits<{
    (e: 'change', tabId: string): void;
}>();
</script>

<template>
    <div
        class="inline-flex w-full max-w-sm items-center justify-around rounded-full border border-[#E2E8F0] bg-white/95 px-4 py-2 font-sans shadow-lg backdrop-blur-md select-none"
    >
        <button
            v-for="t in tabs"
            :key="t.id"
            type="button"
            :class="[
                'flex flex-col items-center gap-1 rounded-full px-3.5 py-1.5 transition-all outline-none',
                activeTab === t.id
                    ? 'font-bold text-[#235347]'
                    : 'font-medium text-[#94A3B8] hover:text-[#64748B]',
            ]"
            @click="emit('change', t.id)"
        >
            <span class="text-lg leading-none">{{ t.icon }}</span>
            <span class="text-[10px] leading-none">{{ t.label }}</span>
        </button>
    </div>
</template>
