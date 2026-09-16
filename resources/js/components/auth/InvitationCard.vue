<script setup lang="ts">
import { computed } from 'vue';
import { getInitials } from '@/composables/useInitials';

// The organization chip of the mockup (lines 821-828): who invited you, with
// what role, over how many environments. Only rendered for an open
// invitation — every other state carries no organization at all.
const props = defineProps<{
    organizationName: string;
    roleLabel: string;
    visibilityLabel: string;
    // Named environments, only for a "manual" visibility: the label alone
    // ("Manual selection") tells the invitee nothing about what they will
    // see. Empty for the other two, where the label says it all.
    visibleEnvironmentNames: string[];
}>();

const initials = computed(() => getInitials(props.organizationName));
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
        <span
            aria-hidden="true"
            style="
                width: 28px;
                height: 28px;
                flex: none;
                border-radius: 50%;
                background: var(--nc-neutral-800);
                color: var(--nc-neutral-200);
                display: grid;
                place-items: center;
                font-size: 10px;
            "
        >
            {{ initials }}
        </span>
        <div style="min-width: 0">
            <div style="font-size: 13px">{{ organizationName }}</div>
            <div style="font-size: 11px; color: var(--nc-neutral-500)">
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
                    color: var(--nc-neutral-600);
                    margin-top: 2px;
                    overflow-wrap: anywhere;
                "
                data-test="invitation-visible-environments"
            >
                {{ visibleEnvironmentNames.join(', ') }}
            </div>
        </div>
    </div>
</template>
