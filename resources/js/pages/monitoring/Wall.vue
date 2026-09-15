<script setup lang="ts">
defineProps<{
    page: App.Data.Pages.WallPageData;
}>();
</script>

<template>
    <pre class="p-6 text-xs">{{ page }}</pre>
</template>
