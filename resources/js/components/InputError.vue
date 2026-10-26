<script setup lang="ts">
import { errorId } from '@/lib/fieldErrors';

const { size = 'md' } = defineProps<{
    message?: string;
    field?: string;
    size?: 'sm' | 'md';
}>();
</script>

<template>
    <div
        v-if="message"
        :id="field ? errorId(field) : undefined"
        class="nc-tone-down"
        :class="size === 'sm' ? 'nc-t-2xs mt-1' : 'nc-t-xs'"
        role="alert"
    >
        {{ message }}
    </div>
</template>
