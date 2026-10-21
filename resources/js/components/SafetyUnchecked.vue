<script setup lang="ts">
defineProps<{
    unchecked: string[];
    unknownDrugs: string[];
}>();
</script>

<template>
    <div
        v-if="unchecked.length > 0 || unknownDrugs.length > 0"
        class="flex flex-col gap-1 text-sm text-muted-foreground"
        data-test="safety-unchecked"
    >
        <p v-if="unchecked.length > 0">
            <span class="font-medium text-foreground">Не проверено</span>, нет
            данных: {{ unchecked.join(', ') }}.
        </p>
        <p v-for="drug in unknownDrugs" :key="drug">
            «{{ drug }}»: препарата нет в справочнике, сочетания с ним не
            проверены.
        </p>
    </div>
</template>
