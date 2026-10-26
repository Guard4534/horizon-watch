<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-8 space-y-0.5'">
        <h2
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium'
                    : 'text-xl font-medium tracking-tight'
            "
        >
            {{ title }}
        </h2>
        <p v-if="description" class="nc-t-sm nc-tone-muted">
            {{ description }}
        </p>
    </header>
</template>
