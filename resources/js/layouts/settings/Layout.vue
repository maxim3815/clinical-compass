<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, KeyRound, LogOut, Smartphone, UserRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import SignOutDialog from '@/components/SignOutDialog.vue';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editApp } from '@/routes/app';
import { edit as editNotifications } from '@/routes/notifications';
import { edit as editPassword } from '@/routes/password';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

const page = usePage();

const isPatient = computed(() => page.props.auth.user.role === 'patient');

const sidebarNavItems = computed<NavItem[]>(() => [
    {
        title: 'Профиль',
        href: editProfile(),
        icon: UserRound,
    },
    {
        title: 'Пароль',
        href: editPassword(),
        icon: KeyRound,
    },
    {
        title: 'Уведомления',
        href: editNotifications(),
        icon: Bell,
    },
    // Установка приложения на телефон нужна только пациенту.
    ...(isPatient.value
        ? [
              {
                  title: 'Приложение',
                  href: editApp(),
                  icon: Smartphone,
              },
          ]
        : []),
]);

const { isCurrentOrParentUrl } = useCurrentUrl();

// У пациента нет бокового меню с кнопкой выхода, поэтому выход — здесь, у обеих ролей.
const signOutOpen = ref(false);
</script>

<template>
    <div class="@container px-4 py-6">
        <!-- Разделы перечислены плитками ниже, подпись под заголовком их бы только повторила. -->
        <Heading title="Настройки" />

        <div class="flex flex-col gap-6 @3xl:flex-row @3xl:gap-10">
            <nav
                class="grid gap-2 @3xl:flex @3xl:w-52 @3xl:shrink-0 @3xl:flex-col @3xl:gap-1"
                :class="
                    sidebarNavItems.length === 4
                        ? 'grid-cols-1 @xs:grid-cols-2'
                        : 'grid-cols-1 @md:grid-cols-3'
                "
                aria-label="Настройки"
            >
                <Link
                    v-for="item in sidebarNavItems"
                    :key="toUrl(item.href)"
                    :href="item.href"
                    class="flex h-11 items-center justify-center gap-2 rounded-lg border px-3 text-base font-medium transition-colors outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring focus-visible:outline-solid @3xl:h-10 @3xl:justify-start @3xl:border-transparent @3xl:text-sm"
                    :class="
                        isCurrentOrParentUrl(item.href)
                            ? 'border-transparent bg-secondary font-semibold text-secondary-foreground'
                            : 'bg-card text-foreground hover:bg-accent @3xl:bg-transparent'
                    "
                    :aria-current="
                        isCurrentOrParentUrl(item.href) ? 'page' : undefined
                    "
                >
                    <component :is="item.icon" class="size-4 shrink-0" />
                    {{ item.title }}
                </Link>
            </nav>

            <div class="flex min-w-0 flex-1 flex-col gap-6 @3xl:max-w-2xl">
                <section
                    class="space-y-12 rounded-2xl border bg-card p-5 shadow-xs sm:p-6"
                >
                    <slot />
                </section>

                <Button
                    variant="outline"
                    size="lg"
                    class="mt-4 w-full @md:w-auto @md:self-start"
                    data-test="settings-logout"
                    @click="signOutOpen = true"
                >
                    <LogOut />
                    Выйти из кабинета
                </Button>
                <SignOutDialog v-model:open="signOutOpen" />
            </div>
        </div>
    </div>
</template>
