<script setup lang="ts">
import Avatar from '@/components/nocturne/Avatar.vue';
import type { User } from '@/types';

withDefaults(
    defineProps<{
        user: User;
        showEmail?: boolean;
    }>(),
    {
        showEmail: false,
    },
);
</script>

<template>
    <Avatar :name="user.name" :size="32" />

    <div class="grid flex-1 text-left leading-tight">
        <span class="nc-t-sm truncate">{{ user.name }}</span>
        <span v-if="showEmail" class="nc-t-xs nc-tone-muted truncate">{{
            user.email
        }}</span>
    </div>
</template>
