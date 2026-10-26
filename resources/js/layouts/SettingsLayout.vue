<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
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
        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    :aria-label="$t('Settings')"
                >
                    <Link
                        v-for="section in sections"
                        :key="section.href"
                        :href="section.href"
                        class="settings-link"
                        :class="{ 'is-active': startsWith(section.href) }"
                        :aria-current="
                            startsWith(section.href) ? 'page' : undefined
                        "
                    >
                        {{ $t(section.title) }}
                    </Link>
                </nav>
            </aside>

            <hr class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>

<style scoped>
.settings-link {
    display: block;
    font-size: 14px;
    padding: 6px var(--nc-space-3);
    border-radius: var(--nc-radius-sm);
    color: inherit;
    text-decoration: none;
}

.settings-link:hover {
    background: color-mix(in srgb, var(--nc-text) 7%, transparent);
}

.settings-link.is-active {
    background: color-mix(in srgb, var(--nc-accent) 14%, transparent);
    color: var(--nc-accent-200);
}
</style>
