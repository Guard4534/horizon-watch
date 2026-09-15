<script setup lang="ts">
defineProps<{
    page: App.Data.Pages.EnvironmentDetailPageData;
}>();
</script>

<template>
    <pre class="p-6 text-xs">{{ page }}</pre>
</template>
