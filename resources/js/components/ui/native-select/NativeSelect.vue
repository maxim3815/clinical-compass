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
      'border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:bg-input/30 h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
      props.class,
    )"
  >
    <slot />
  </select>
</template>
