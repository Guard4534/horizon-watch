<script setup lang="ts">
import { PhWarningCircle } from '@phosphor-icons/vue';
import { computed } from 'vue';

type Props = {
    errors: string[];
    title?: string;
};

const props = withDefaults(defineProps<Props>(), {
    title: 'Something went wrong.',
});

const uniqueErrors = computed(() => Array.from(new Set(props.errors)));
</script>

<template>
    <div
        role="alert"
        class="nc-t-sm nc-tone-down flex w-full gap-3"
        style="
            padding: var(--nc-space-3) var(--nc-space-4);
            border: 1px solid var(--st-down);
            border-radius: var(--nc-radius-md);
            background: color-mix(in srgb, var(--st-down) 10%, transparent);
        "
    >
        <PhWarningCircle :size="16" class="mt-0.5 flex-none" />
        <div>
            <p>{{ $t(title) }}</p>
            <ul class="list-inside list-disc">
                <li v-for="(error, index) in uniqueErrors" :key="index">
                    {{ error }}
                </li>
            </ul>
        </div>
    </div>
</template>
