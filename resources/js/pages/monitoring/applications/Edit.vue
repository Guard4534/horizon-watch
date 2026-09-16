<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: { title: 'Edit application' },
});

defineProps<{
    page: App.Data.Pages.ApplicationFormPageData;
}>();
</script>

<template>
    <Head :title="$t('Edit application')" />

    <!-- Placeholder: lane F replaces this with the real form. -->
    <pre>{{ page }}</pre>
</template>
