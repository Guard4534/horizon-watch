<script setup lang="ts">
defineProps<{
    page: App.Data.Pages.ApplicationDetailPageData;
}>();
</script>

<template>
    <pre class="p-6 text-xs">{{ page }}</pre>
</template>
