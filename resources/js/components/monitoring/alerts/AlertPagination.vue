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
        class="nc-t-xs flex items-center"
        style="gap: var(--nc-space-2)"
        :aria-label="$t('Pages')"
    >
        <span class="nc-num nc-tone-muted">{{
            $t('Page :page of :pages', {
                page: String(page),
                pages: String(pages),
            })
        }}</span>
        <button
            type="button"
            class="nc-btn nc-btn-secondary nc-t-xs ml-auto"
            style="padding: 3px 9px"
            :disabled="page <= 1"
            @click="$emit('go', Math.min(page - 1, pages))"
        >
            <PhCaretLeft :size="12" />{{ $t('Previous') }}
        </button>
        <button
            type="button"
            class="nc-btn nc-btn-secondary nc-t-xs"
            style="padding: 3px 9px"
            :disabled="page >= pages"
            @click="$emit('go', page + 1)"
        >
            {{ $t('Next') }}<PhCaretRight :size="12" />
        </button>
    </nav>
</template>
