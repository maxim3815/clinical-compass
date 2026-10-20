<script setup lang="ts">
interface Message {
    id: string | number;
    sender: 'patient' | 'doctor';
    text: string;
    timestamp: string;
}

interface Props {
    messages?: Message[];
}

withDefaults(defineProps<Props>(), {
    messages: () => [
        {
            id: 1,
            sender: 'patient',
            text: "I've been feeling a bit better this week. Thank you for your support.",
            timestamp: 'Apr 21, 10:24 AM',
        },
        {
            id: 2,
            sender: 'doctor',
            text: "That's great to hear, Alexandra! 💚 Keep up the good work. Consistency helps your progress.",
            timestamp: 'Apr 21, 10:25 AM',
        },
    ],
});
</script>

<template>
    <div
        class="flex flex-col gap-4 rounded-3xl border border-[#E2E8F0] bg-white p-5 font-sans shadow-xs"
    >
        <div
            class="flex items-center justify-between border-b border-[#F1F5F9] pb-3"
        >
            <div class="flex items-center gap-2">
                <span class="text-base">💬</span>
                <h4 class="text-sm font-bold text-[#1E293B]">Patient Chat</h4>
            </div>
            <span
                class="flex items-center gap-1 text-xs font-medium text-[#22C55E]"
            >
                <span class="h-2 w-2 rounded-full bg-[#22C55E]"></span>
                Online
            </span>
        </div>

        <div class="flex flex-col gap-3 py-1">
            <div
                v-for="msg in messages"
                :key="msg.id"
                :class="[
                    'flex max-w-[85%] flex-col',
                    msg.sender === 'doctor'
                        ? 'items-end self-end'
                        : 'items-start self-start',
                ]"
            >
                <div
                    :class="[
                        'rounded-2xl p-4 text-sm leading-relaxed shadow-xs',
                        msg.sender === 'doctor'
                            ? 'rounded-br-xs bg-[#D4EFE6] text-[#1E293B]'
                            : 'rounded-bl-xs border border-[#E2E8F0] bg-[#F8FAFC] text-[#1E293B]',
                    ]"
                >
                    {{ msg.text }}
                </div>
                <span class="mt-1 px-1 text-[10px] text-[#94A3B8]">
                    {{ msg.timestamp }}
                </span>
            </div>
        </div>
    </div>
</template>
