<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Toaster } from '@/components/ui/sonner';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const name = usePage().props.name;
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center bg-background px-4 py-10 sm:px-6"
    >
        <div class="flex w-full max-w-md flex-col gap-6">
            <Link
                :href="home()"
                class="flex items-center gap-3 self-center rounded-xl outline-none focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring focus-visible:outline-solid"
            >
                <span
                    class="flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-xs"
                >
                    <AppLogoIcon class="size-7" />
                </span>
                <span class="text-lg font-semibold tracking-tight">{{
                    name
                }}</span>
            </Link>

            <!-- Гостевые страницы открывает и пациент: подписи и ошибки полей крупнее, чем в кабинете врача. -->
            <main
                class="flex flex-col gap-6 rounded-2xl border bg-card p-6 shadow-sm sm:p-8 [&_[data-slot=input-error]]:text-base [&_[data-slot=label]]:text-base"
            >
                <header v-if="title || description" class="space-y-2">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-base text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </header>
                <slot />
            </main>
        </div>
        <Toaster position="top-center" container-aria-label="Уведомления" />
    </div>
</template>
