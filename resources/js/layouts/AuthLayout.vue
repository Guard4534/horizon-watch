<script setup lang="ts">
import AuthSimpleLayout from '@/layouts/auth/AuthSimpleLayout.vue';

const { title = '', description = '', kicker = '' } = defineProps<{
    title?: string;
    description?: string;
    kicker?: string;
}>();
</script>

<template>
    <AuthSimpleLayout :title="title" :description="description" :kicker="kicker">
        <slot />
    </AuthSimpleLayout>
</template>
