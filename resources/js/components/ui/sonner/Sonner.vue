<script lang="ts" setup>
import type { ToasterProps } from "vue-sonner"
import { CircleCheckIcon, InfoIcon, Loader2Icon, OctagonXIcon, TriangleAlertIcon, XIcon } from "@lucide/vue"
import { reactiveOmit } from "@vueuse/core"
import { computed } from "vue"
import { Toaster as Sonner } from "vue-sonner"
import { cn } from "@/lib/utils"

import 'vue-sonner/style.css';

const props = defineProps<ToasterProps>()

const delegatedProps = reactiveOmit(props, "toastOptions")

// Текст уведомления крупнее, чем по умолчанию: его читают и пациенты.
const toastOptions = computed(() => ({
  ...props.toastOptions,
  classes: { title: "text-[0.9375rem] leading-snug font-medium", ...props.toastOptions?.classes },
}))
</script>

<template>
  <Sonner
    :class="cn('toaster group', props.class)"
    :style="{
      '--normal-bg': 'var(--popover)',
      '--normal-text': 'var(--popover-foreground)',
      '--normal-border': 'var(--border)',
      '--border-radius': 'var(--radius)',
    }"
    v-bind="delegatedProps"
    :toast-options="toastOptions"
  >
    <template #success-icon>
      <CircleCheckIcon class="size-5 text-success" />
    </template>
    <template #info-icon>
      <InfoIcon class="size-5 text-primary" />
    </template>
    <template #warning-icon>
      <TriangleAlertIcon class="size-5 text-warning" />
    </template>
    <template #error-icon>
      <OctagonXIcon class="size-5 text-danger" />
    </template>
    <template #loading-icon>
      <div>
        <Loader2Icon class="size-4 animate-spin" />
      </div>
    </template>
    <template #close-icon>
      <XIcon class="size-4" />
    </template>
  </Sonner>
</template>
