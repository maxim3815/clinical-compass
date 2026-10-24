<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Phone } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { recordHelpCall } from '@/lib/helpCalls';
import type { HelpLine } from '@/lib/helpCalls';

/**
 * Звонки на экране помощи и в карточке «Если вам сейчас тяжело»: 112 и кризисная линия [медик].
 * Нажатие врач видит тихой строкой в карточке пациента. В очереди у двери терапевта пациент
 * без входа: нажатия не записываются.
 */
const { record = true } = defineProps<{
    record?: boolean;
}>();

const page = usePage();

function call(line: HelpLine): void {
    if (record) {
        recordHelpCall(line);
    }
}
const crisisLine = computed(() => page.props.crisisLine);
</script>

<template>
    <div class="flex flex-col gap-3">
        <Button size="xl" as-child>
            <a href="tel:112" data-test="call-112" @click="call('112')">
                <Phone />
                Позвонить 112
            </a>
        </Button>
        <Button
            v-if="crisisLine"
            variant="outline"
            size="xl"
            class="h-auto min-h-14 flex-col gap-0 bg-card py-2 whitespace-normal"
            as-child
        >
            <a
                :href="crisisLine.href"
                data-test="call-crisis-line"
                @click="call('crisis_line')"
            >
                <span class="flex items-center gap-2">
                    <Phone />
                    Кризисная линия
                </span>
                <span class="text-base font-normal whitespace-nowrap">{{
                    crisisLine.label
                }}</span>
            </a>
        </Button>
    </div>
</template>
