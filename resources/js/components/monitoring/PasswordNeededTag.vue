<script setup lang="ts">
import { PhKey } from '@phosphor-icons/vue';
</script>

<template>
    <span
        class="nc-tag nc-tag-sm nc-tag-neutral inline-flex flex-none items-center gap-1"
        :title="$t('A basic-auth username is set, but no password is stored.')"
    >
        <PhKey :size="11" aria-hidden="true" />{{ $t('Password needed') }}
    </span>
</template>
