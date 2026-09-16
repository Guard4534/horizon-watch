<script setup lang="ts">
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

// breadcrumbs survives from the starter kit only to supply the header title
// when no explicit `title` is given (its last item's `title`); the trail
// itself is not rendered.
const {
    title,
    subtitle,
    live = false,
    breadcrumbs = [],
} = defineProps<{
    title?: string;
    subtitle?: string;
    live?: boolean;
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppSidebarLayout
        :title="title ?? breadcrumbs.at(-1)?.title"
        :subtitle="subtitle"
        :live="live"
    >
        <slot />
    </AppSidebarLayout>
</template>
