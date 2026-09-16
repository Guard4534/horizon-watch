<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: { title: 'Edit environment' },
});

defineProps<{
    page: App.Data.Pages.EnvironmentFormPageData;
}>();
</script>

<template>
    <Head :title="$t('Edit environment')" />

    <!-- Placeholder: lane F replaces this with the real form. -->
    <pre>{{ page }}</pre>
</template>
