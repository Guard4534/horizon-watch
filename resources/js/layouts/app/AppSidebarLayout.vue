<script setup lang="ts">
import AppHeaderBar from '@/components/AppHeaderBar.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import { Toaster } from '@/components/ui/sonner';

defineProps<{
    title?: string;
    subtitle?: string;
    live: boolean;
}>();
</script>

<template>
    <div class="flex min-h-screen items-stretch" style="background: var(--nc-bg); font-size: 15px">
        <AppSidebar />
        <main class="flex min-w-0 flex-1 flex-col">
            <AppHeaderBar :title="title" :subtitle="subtitle" :live="live" />
            <slot />
        </main>
        <Toaster />
    </div>
</template>
