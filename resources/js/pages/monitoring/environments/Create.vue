<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: { title: 'Add environment' },
});

defineProps<{
    page: App.Data.Pages.EnvironmentFormPageData;
}>();
</script>

<template>
    <Head :title="$t('Add environment')" />

    <!-- Placeholder: lane F replaces this with the real form. -->
    <pre>{{ page }}</pre>
</template>
