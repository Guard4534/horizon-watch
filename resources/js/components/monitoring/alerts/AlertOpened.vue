<script setup lang="ts">
import { formatAgo } from '@/lib/monitoring';

defineProps<{
    alert: App.Data.Monitoring.AlertData;
}>();
</script>

<template>
    {{ formatAgo(alert.minutesAgo * 60) }}
</template>
