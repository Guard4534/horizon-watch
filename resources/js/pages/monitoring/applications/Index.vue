<script setup lang="ts">
defineProps<{
    page: App.Data.Pages.ApplicationListPageData;
}>();
</script>

<template>
    <pre class="p-6 text-xs">{{ page }}</pre>
</template>
