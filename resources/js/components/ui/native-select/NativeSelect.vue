<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

// Обычный <select>: на телефоне открывается системный выбор, значения могут быть числами.
const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

const modelValue = defineModel<unknown>()
</script>

<template>
  <select
    v-model="modelValue"
    data-slot="native-select"
    :class="cn(
      'border-input focus-visible:border-ring focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring aria-invalid:ring-danger-subtle aria-invalid:border-destructive h-10 pointer-coarse:h-11 w-full min-w-0 rounded-lg border bg-card px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-70',
      props.class,
    )"
  >
    <slot />
  </select>
</template>
