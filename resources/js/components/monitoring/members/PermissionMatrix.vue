<script setup lang="ts">
import { PhCheck, PhMinus } from '@phosphor-icons/vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';

defineProps<{
    rows: App.Data.Pages.PermissionMatrixRowData[];
    roles: App.Data.Teams.RoleOptionData[];
}>();
</script>

<template>
    <SectionCard :title="$t('Capabilities by role')">
        <div class="overflow-x-auto">
            <table class="nc-table">
                <thead>
                    <tr>
                        <th>{{ $t('Capability') }}</th>
                        <th
                            v-for="role in roles"
                            :key="role.value"
                            class="text-center"
                        >
                            {{ role.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.permission">
                        <td class="nc-t-sm">{{ row.label }}</td>
                        <td
                            v-for="role in roles"
                            :key="role.value"
                            class="text-center"
                        >
                            <component
                                :is="row[role.value] ? PhCheck : PhMinus"
                                :size="15"
                                class="inline-block"
                                :style="{
                                    color: row[role.value]
                                        ? 'var(--st-ok)'
                                        : 'var(--nc-neutral-700)',
                                }"
                            />
                            <span class="sr-only">{{
                                row[role.value] ? $t('yes') : $t('no')
                            }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div
            class="nc-t-2xs nc-tone-faint"
            style="margin-top: var(--nc-space-2)"
        >
            {{
                $t(
                    'Admins manage applications, environments, credentials and thresholds; members act on alerts; viewers only look. Reading is not a permission: everyone sees the wall, the details and the alert history of the environments they can see. A person can belong to several organizations, with a separate role in each.',
                )
            }}
        </div>
    </SectionCard>
</template>
