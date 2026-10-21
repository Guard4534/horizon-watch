<script setup lang="ts">
import { PhWarningCircle } from '@phosphor-icons/vue';
import { computed } from 'vue';

const { errors, title = null } = defineProps<{
    errors: Array<string | null | undefined>;
    title?: string | null;
}>();

const messages = computed(() => [
    ...new Set(errors.filter((message): message is string => Boolean(message))),
]);
</script>

<template>
    <div
        v-if="messages.length"
        role="alert"
        class="nc-t-xs nc-tone-down flex w-full"
        style="
            gap: var(--nc-space-2);
            padding: var(--nc-space-2) var(--nc-space-3);
            border: 1px solid var(--st-down);
            border-radius: var(--nc-radius-md);
            background: color-mix(in srgb, var(--st-down) 10%, transparent);
        "
    >
        <PhWarningCircle :size="14" class="mt-[2px] flex-none" />
        <div class="min-w-0">
            <p v-if="title">{{ title }}</p>
            <p v-if="!title && messages.length === 1">{{ messages[0] }}</p>
            <ul v-else class="list-inside list-disc">
                <li v-for="message in messages" :key="message">
                    {{ message }}
                </li>
            </ul>
        </div>
    </div>
</template>
