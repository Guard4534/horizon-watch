<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { getCurrentInstance, useTemplateRef, watch } from 'vue';
import AppHeaderBar from '@/components/AppHeaderBar.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import PanelHealthBanner from '@/components/PanelHealthBanner.vue';
import TabBar from '@/components/mobile/TabBar.vue';
import { Toaster } from '@/components/ui/sonner';

defineProps<{
    title?: string;
    subtitle?: string;
    live?: boolean;
}>();

const main = useTemplateRef('main');

const page = usePage();
const head = getCurrentInstance()?.proxy?.$headManager;

watch(
    () => page.props.openAlertCount,
    () => head?.forceUpdate(),
);
</script>

<template>
    <div
        class="shell flex min-h-screen items-stretch"
        style="background: var(--nc-bg); font-size: 15px"
    >
        <a href="#content" class="skip-link" @click.prevent="main?.focus()">{{
            $t('Skip to content')
        }}</a>
        <AppSidebar class="shell-sidebar" />
        <main
            id="content"
            ref="main"
            tabindex="-1"
            class="shell-main flex min-w-0 flex-1 flex-col"
        >
            <AppHeaderBar :title="title" :subtitle="subtitle" :live="live" />
            <PanelHealthBanner />
            <slot />
        </main>
        <div class="shell-tabs"><TabBar /></div>
        <Toaster :container-aria-label="$t('Notifications')" />
    </div>
</template>

<style scoped>
.skip-link {
    position: fixed;
    top: var(--nc-space-3);
    left: var(--nc-space-3);
    z-index: 60;
    padding: var(--nc-space-2) var(--nc-space-4);
    border-radius: var(--nc-radius-md);
    background: var(--nc-surface);
    box-shadow: var(--nc-shadow-md);
    color: var(--nc-text);
    font-size: 14px;
    text-decoration: none;
    transform: translateY(calc(-100% - var(--nc-space-6)));
}

.skip-link:focus {
    transform: none;
}

.shell-main:focus {
    outline: none;
}

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

    .shell-main {
        padding-bottom: calc(64px + env(safe-area-inset-bottom));
    }
}
</style>
