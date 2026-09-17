<script setup lang="ts">
import AppHeaderBar from '@/components/AppHeaderBar.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import TabBar from '@/components/mobile/TabBar.vue';
import { Toaster } from '@/components/ui/sonner';

defineProps<{
    title?: string;
    subtitle?: string;
    live: boolean;
}>();
</script>

<template>
    <!-- Below 640px the sidebar gives way to the bottom tab bar. The switch
         is CSS, not useIsMobile(): the shell must be right on the first
         paint, before any script has measured the viewport. -->
    <div
        class="shell flex min-h-screen items-stretch"
        style="background: var(--nc-bg); font-size: 15px"
    >
        <AppSidebar class="shell-sidebar" />
        <main class="shell-main flex min-w-0 flex-1 flex-col">
            <AppHeaderBar :title="title" :subtitle="subtitle" :live="live" />
            <slot />
        </main>
        <div class="shell-tabs"><TabBar /></div>
        <Toaster />
    </div>
</template>

<style scoped>
.shell-tabs {
    display: none;
}

@media (max-width: 639px) {
    .shell-sidebar {
        display: none;
    }

    .shell-tabs {
        display: block;
    }

    /* Room for the fixed tab bar under the last row. */
    .shell-main {
        padding-bottom: calc(64px + env(safe-area-inset-bottom));
    }
}
</style>
