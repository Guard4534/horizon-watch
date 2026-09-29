<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div v-if="message" class="nc-t-2xs nc-tone-down mt-1">{{ message }}</div>
</template>
