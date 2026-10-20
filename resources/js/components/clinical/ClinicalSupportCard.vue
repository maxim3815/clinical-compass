<script setup lang="ts">
interface Props {
    title?: string;
    message?: string;
}

withDefaults(defineProps<Props>(), {
    title: "You're doing well",
    message: "Small steps create big changes. Keep going — you're not alone.",
});
</script>

<template>
    <div
        class="relative overflow-hidden rounded-3xl border border-[#CDE3D5] bg-gradient-to-br from-[#EAF4EE] to-[#D8ECE0] p-6 font-sans text-[#1E293B] shadow-xs"
    >
        <!-- Soft background decorative organic curves -->
        <div
            class="pointer-events-none absolute -right-6 -bottom-6 h-32 w-32 rounded-full bg-white/20"
        ></div>
        <div
            class="pointer-events-none absolute right-10 -bottom-10 h-44 w-44 rounded-full bg-[#A3C9B8]/20"
        ></div>

        <div class="relative z-10 flex items-start gap-4">
            <div
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-white/90 text-xl text-[#235347] shadow-xs"
            >
                🌱
            </div>
            <div>
                <h4 class="text-base font-bold text-[#235347]">{{ title }}</h4>
                <p class="mt-1 text-xs leading-relaxed text-[#285A52]">
                    {{ message }}
                </p>
            </div>
        </div>
    </div>
</template>
