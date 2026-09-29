<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentPath } from '@/composables/useCurrentPath';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as teams } from '@/routes/teams';

const sections = [
    { title: 'Profile', href: editProfile().url },
    { title: 'Security', href: editSecurity().url },
    { title: 'Organizations', href: teams().url },
];

const { startsWith } = useCurrentPath();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            :title="$t('Settings')"
            :description="$t('Manage your profile and account settings')"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    :aria-label="$t('Settings')"
                >
                    <Button
                        v-for="section in sections"
                        :key="section.href"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': startsWith(section.href) },
                        ]"
                        as-child
                    >
                        <Link
                            :href="section.href"
                            :aria-current="
                                startsWith(section.href) ? 'page' : undefined
                            "
                        >
                            {{ $t(section.title) }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
