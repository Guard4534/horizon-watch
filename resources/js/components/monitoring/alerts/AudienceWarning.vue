<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import WarningNotice from '@/components/monitoring/WarningNotice.vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { index as alertRulesIndex } from '@/routes/alert-rules';
import { edit as profileEdit } from '@/routes/profile';

defineProps<{
    link?: 'settings' | 'profile';
}>();

const slug = useTeamSlug();
</script>

<template>
    <WarningNotice :title="$t('Alerts reach nobody')">
        {{
            $t(
                'No member has turned on alert emails, and there is no extra address and no webhook: alerts open here, but nobody is told.',
            )
        }}
        <Link
            v-if="link === 'settings'"
            :href="alertRulesIndex({ current_team: slug })"
            >{{ $t('Set up notifications') }}</Link
        >
        <Link v-else-if="link === 'profile'" :href="profileEdit()">{{
            $t('Turn on your alert emails')
        }}</Link>
    </WarningNotice>
</template>
