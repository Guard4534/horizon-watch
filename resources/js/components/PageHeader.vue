<script lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';

export type Crumb = {
    label: string;
    href: NonNullable<InertiaLinkProps['href']>;
};
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    title: string;
    subtitle?: string | null;
    crumbs?: Crumb[];
}>();
</script>

<template>
    <header class="page-header">
        <div class="min-w-0">
            <nav
                v-if="crumbs?.length"
                class="nc-t-2xs nc-tone-muted"
                :aria-label="$t('Breadcrumb')"
            >
                <template v-for="(crumb, index) in crumbs" :key="index">
                    <span
                        v-if="index > 0"
                        class="crumb-separator"
                        aria-hidden="true"
                        >/</span
                    >
                    <Link :href="crumb.href" class="crumb">{{
                        crumb.label
                    }}</Link>
                </template>
            </nav>
            <div class="title-row">
                <h2 class="page-title">
                    <slot name="title">{{ title }}</slot>
                </h2>
                <slot name="status" />
            </div>
            <div
                v-if="subtitle"
                class="nc-t-xs nc-tone-muted"
                style="letter-spacing: 0.01em"
            >
                {{ subtitle }}
            </div>
        </div>
        <div v-if="$slots.actions" class="page-actions">
            <slot name="actions" />
        </div>
    </header>
</template>

<style scoped>
.page-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--nc-space-3) var(--nc-space-4);
}

.title-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--nc-space-2) var(--nc-space-3);
}

.page-title {
    min-width: 0;
    margin: 0;
    font-size: 26px;
    line-height: 1.15;
    overflow-wrap: anywhere;
}

.page-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: var(--nc-space-2);
    margin-left: auto;
}

.crumb {
    color: inherit;
}

.crumb:hover {
    color: var(--nc-accent);
}

.crumb-separator {
    margin: 0 6px;
}
</style>
