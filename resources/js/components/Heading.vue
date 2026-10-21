<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? 'space-y-1' : 'mb-6 space-y-1'">
        <h2
            :class="
                variant === 'small'
                    ? 'text-lg font-semibold'
                    : 'text-2xl font-semibold tracking-tight'
            "
        >
            {{ title }}
        </h2>
        <p v-if="description" class="text-base text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
