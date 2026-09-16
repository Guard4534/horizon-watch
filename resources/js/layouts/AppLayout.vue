<script setup lang="ts">
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

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
