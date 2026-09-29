<script setup lang="ts">
import type { Component } from 'vue';
import EmptyState from '@/components/monitoring/EmptyState.vue';
import { useVisibility } from '@/composables/useVisibility';

defineProps<{
    title: string;
    body: string;
    kicker?: string;
    icon?: Component;
}>();

const { somethingIsHidden } = useVisibility();
</script>

<template>
    <EmptyState
        :icon="icon"
        :kicker="kicker"
        :title="title"
        :body="
            somethingIsHidden
                ? $t(
                      'No environment is visible to you yet. Your access covers part of this organization, which may hold environments you cannot see.',
                  )
                : body
        "
    >
        <slot v-if="!somethingIsHidden" />
    </EmptyState>
</template>
