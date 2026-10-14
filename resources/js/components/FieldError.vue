<script setup lang="ts">
import { errorId } from '@/lib/fieldErrors';

defineProps<{
    message?: string;
    field?: string;
}>();
</script>

<template>
    <div
        v-if="message"
        :id="field ? errorId(field) : undefined"
        class="nc-t-2xs nc-tone-down mt-1"
        role="alert"
    >
        {{ message }}
    </div>
</template>
