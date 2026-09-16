<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: { title: 'Add application' },
});

defineProps<{
    page: App.Data.Pages.ApplicationFormPageData;
}>();
</script>

<template>
    <Head :title="$t('Add application')" />

    <!-- Placeholder: lane F replaces this with the wizard. -->
    <pre>{{ page }}</pre>
</template>
