<script setup lang="ts">
import { PhCheck, PhMinus } from '@phosphor-icons/vue';
import SectionCard from '@/components/nocturne/SectionCard.vue';

defineProps<{
    rows: App.Data.Pages.PermissionMatrixRowData[];
}>();

const COLUMNS = ['owner', 'admin', 'member', 'viewer'] as const;
</script>

<template>
    <SectionCard :title="$t('Capabilities by role')">
        <div class="overflow-x-auto">
            <table class="nc-table">
                <thead>
                    <tr>
                        <th>{{ $t('Capability') }}</th>
                        <th
                            v-for="column in COLUMNS"
                            :key="column"
                            class="text-center"
                        >
                            {{ column }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.permission">
                        <td class="nc-t-sm">{{ row.label }}</td>
                        <td
                            v-for="column in COLUMNS"
                            :key="column"
                            class="text-center"
                        >
                            <component
                                :is="row[column] ? PhCheck : PhMinus"
                                :size="15"
                                class="inline-block"
                                :style="{
                                    color: row[column]
                                        ? 'var(--st-ok)'
                                        : 'var(--nc-neutral-700)',
                                }"
                            />
                            <span class="sr-only">{{
                                row[column] ? $t('yes') : $t('no')
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
                    'Reading is not a permission: every member sees the wall, the details and the alert history, within the environments their visibility allows.',
                )
            }}
        </div>
    </SectionCard>
</template>
