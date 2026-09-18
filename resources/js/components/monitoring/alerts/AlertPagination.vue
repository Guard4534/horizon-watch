<script setup lang="ts">
import { PhCaretLeft, PhCaretRight } from '@phosphor-icons/vue';

defineProps<{
    page: number;
    pages: number;
}>();

defineEmits<{
    go: [number: number];
}>();
</script>

<template>
    <nav
        class="flex items-center"
        style="gap: var(--nc-space-2); font-size: 12px"
        :aria-label="$t('Pages')"
    >
        <span class="nc-num" style="color: var(--nc-neutral-500)">{{
            $t('Page :page of :pages', {
                page: String(page),
                pages: String(pages),
            })
        }}</span>
        <button
            type="button"
            class="nc-btn nc-btn-secondary ml-auto"
            style="font-size: 12px; padding: 3px 9px"
            :disabled="page <= 1"
            @click="$emit('go', Math.min(page - 1, pages))"
        >
            <PhCaretLeft :size="12" />{{ $t('Previous') }}
        </button>
        <button
            type="button"
            class="nc-btn nc-btn-secondary"
            style="font-size: 12px; padding: 3px 9px"
            :disabled="page >= pages"
            @click="$emit('go', page + 1)"
        >
            {{ $t('Next') }}<PhCaretRight :size="12" />
        </button>
    </nav>
</template>
