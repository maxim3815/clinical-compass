<script setup lang="ts">
interface Props {
    medicationName: string;
    dosageText: string;
    lastCheckInDate?: string;
    nextAppointmentDate?: string;
}

withDefaults(defineProps<Props>(), {
    lastCheckInDate: 'Apr 21, 2025',
    nextAppointmentDate: 'Apr 29, 2025',
});
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-3xl border border-[#E2E8F0] bg-white p-5 font-sans shadow-xs"
    >
        <div class="flex items-start gap-3">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-[#E8F2EC] text-lg text-[#235347]"
            >
                💊
            </div>
            <div>
                <span class="text-xs font-semibold text-[#64748B]">
                    Current Treatment
                </span>
                <h4 class="mt-0.5 text-base font-bold text-[#1E293B]">
                    {{ medicationName }}
                </h4>
                <p class="text-xs font-medium text-[#235347]">
                    {{ dosageText }}
                </p>
            </div>
        </div>

        <div class="flex flex-col gap-2 border-t border-[#F1F5F9] pt-3 text-xs">
            <div class="flex items-center justify-between text-[#64748B]">
                <span class="flex items-center gap-1.5">
                    <svg
                        class="h-3.5 w-3.5 stroke-2"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <circle cx="12" cy="12" r="10" />
                        <path d="M12 6v6l4 2" />
                    </svg>
                    Last Check-in:
                </span>
                <span class="font-semibold text-[#1E293B]">{{
                    lastCheckInDate
                }}</span>
            </div>

            <div class="flex items-center justify-between text-[#64748B]">
                <span class="flex items-center gap-1.5">
                    <svg
                        class="h-3.5 w-3.5 stroke-2"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <rect
                            x="3"
                            y="4"
                            width="18"
                            height="18"
                            rx="2"
                            ry="2"
                        />
                        <path d="M16 2v4M8 2v4M3 10h18" />
                    </svg>
                    Next Appointment:
                </span>
                <span class="font-semibold text-[#235347]">{{
                    nextAppointmentDate
                }}</span>
            </div>
        </div>
    </div>
</template>
