<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { Menu, PanelLeftClose, PanelLeftOpen } from "@lucide/vue"
import { cn } from "@/lib/utils"
import { Button } from '@/components/ui/button'
import { useSidebar } from "./utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

const { isMobile, openMobile, state, toggleSidebar } = useSidebar()
</script>

<template>
  <Button
    data-sidebar="trigger"
    :aria-expanded="isMobile ? openMobile : state === 'expanded'"
    data-slot="sidebar-trigger"
    variant="ghost"
    size="icon"
    :class="cn('h-7 w-7', props.class)"
    @click="toggleSidebar"
  >
    <Menu v-if="isMobile" />
    <PanelLeftOpen v-else-if="state === 'collapsed'" />
    <PanelLeftClose v-else />
    <span class="sr-only">Показать или скрыть меню</span>
  </Button>
</template>
