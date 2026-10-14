<script setup lang="ts">
import Avatar from '@/components/nocturne/Avatar.vue';

defineProps<{
    organizationName: string;
    roleLabel: string;
    visibilityLabel: string;
    visibleEnvironmentNames: string[];
}>();
</script>

<template>
    <div
        data-test="invitation-card"
        style="
            display: flex;
            align-items: center;
            gap: 9px;
            padding: var(--nc-space-3);
            border-radius: var(--nc-radius-sm);
            background: var(--nc-bg);
            border: 1px solid var(--nc-divider);
        "
    >
        <Avatar :name="organizationName" :size="28" />
        <div style="min-width: 0">
            <div class="nc-t-sm">{{ organizationName }}</div>
            <div class="nc-t-2xs nc-tone-muted">
                {{
                    $t('role: :role · :visibility', {
                        role: roleLabel,
                        visibility: visibilityLabel,
                    })
                }}
            </div>
            <div
                v-if="visibleEnvironmentNames.length > 0"
                style="
                    font-size: 11px;
                    color: var(--nc-text-faint);
                    margin-top: 2px;
                    overflow-wrap: anywhere;
                "
                data-test="invitation-visible-environments"
            >
                {{
                    $t('environments: :list', {
                        list: visibleEnvironmentNames.join(', '),
                    })
                }}
            </div>
        </div>
    </div>
</template>
