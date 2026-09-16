<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { Component } from 'vue';

defineProps<{
    href: NonNullable<InertiaLinkProps['href']>;
    icon: Component;
    label: string;
    active: boolean;
    badge?: number | null;
}>();
</script>

<template>
    <Link :href="href" class="sidebar-link" :class="{ 'is-active': active }">
        <component :is="icon" :size="16" />
        {{ $t(label) }}
        <span
            v-if="badge"
            class="nc-num ml-auto"
            style="
                font-size: 10px;
                padding: 1px 6px;
                border-radius: 99px;
                background: var(--nc-accent-800);
                color: var(--nc-accent-100);
            "
            >{{ badge }}</span
        >
    </Link>
</template>

<style scoped>
.sidebar-link {
    display: flex;
    align-items: center;
    gap: 9px;
    width: 100%;
    font-size: 13px;
    padding: 6px var(--nc-space-2);
    border-radius: var(--nc-radius-sm);
    color: inherit;
    text-decoration: none;
}

.sidebar-link:hover {
    background: color-mix(in srgb, var(--nc-text) 7%, transparent);
}

.sidebar-link.is-active {
    background: color-mix(in srgb, var(--nc-accent) 14%, transparent);
    color: var(--nc-accent-200);
    box-shadow: inset 2px 0 0 var(--nc-accent);
}
</style>
