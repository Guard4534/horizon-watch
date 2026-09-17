<script setup lang="ts">
import { formatElapsed } from '@/lib/monitoring';

defineProps<{
    alert: App.Data.Monitoring.AlertData;
}>();
</script>

<template>
    {{ formatElapsed(alert.minutesAgo, alert.sinceTruncated) }}
</template>
