<script setup lang="ts">
import { formatMinutesAgo } from '@/lib/monitoring';

// Past the 24-hour look-back the minutes are capped: say so rather than
// show "1440 min ago".
defineProps<{
    alert: App.Data.Monitoring.AlertData;
}>();
</script>

<template>
    <template v-if="alert.sinceTruncated">{{ $t('more than 24 h') }}</template>
    <template v-else>{{ formatMinutesAgo(alert.minutesAgo) }}</template>
</template>
