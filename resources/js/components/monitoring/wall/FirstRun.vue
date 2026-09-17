<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhPlus } from '@phosphor-icons/vue';
import { useTeamSlug } from '@/composables/useTeamSlug';
import { index as alertRulesIndex } from '@/routes/alert-rules';
import { create as createApplication } from '@/routes/applications';
import { index as membersIndex } from '@/routes/members';

const slug = useTeamSlug();

const PALETTE = [
    'prod',
    'preprod',
    'staging',
    'develop',
    'demo',
    'worker',
    'testing',
] as const;
</script>

<template>
    <div class="flex justify-center" style="padding: var(--nc-space-8) 0">
        <div
            class="flex w-full flex-col"
            style="max-width: 600px; gap: var(--nc-space-6)"
        >
            <div>
                <div
                    class="flex gap-[5px]"
                    style="margin-bottom: var(--nc-space-4)"
                >
                    <span
                        v-for="color in PALETTE"
                        :key="color"
                        class="swatch"
                        :style="{ background: `var(--env-${color})` }"
                    />
                </div>
                <h3 style="margin: 0 0 var(--nc-space-2); font-size: 25px">
                    {{ $t('You are not monitoring anything yet') }}
                </h3>
                <p class="lead">
                    {{
                        $t(
                            'Connect your first application and its environments: the panel starts polling Horizon and opening anomalies as soon as a metric crosses a threshold.',
                        )
                    }}
                </p>
            </div>

            <ol class="flex flex-col" style="gap: var(--nc-space-3)">
                <li class="step">
                    <span class="num">1</span>
                    <div class="min-w-0 flex-1">
                        <div class="step-title">
                            {{ $t('Connect an application') }}
                        </div>
                        <div class="step-body">
                            {{
                                $t(
                                    'Name, domain and Horizon path. Then one environment per installation, each with its colour.',
                                )
                            }}
                        </div>
                    </div>
                    <Link
                        class="nc-btn nc-btn-primary flex-none"
                        :href="createApplication(slug)"
                    >
                        <PhPlus :size="14" />{{ $t('Add application') }}
                    </Link>
                </li>
                <li class="step">
                    <span class="num">2</span>
                    <div class="min-w-0 flex-1">
                        <div class="step-title">
                            {{ $t('Review the thresholds') }}
                        </div>
                        <div class="step-body">
                            {{
                                $t(
                                    'The organization defaults apply straight away: they decide when an anomaly opens.',
                                )
                            }}
                        </div>
                    </div>
                    <Link
                        class="nc-btn nc-btn-secondary flex-none"
                        style="font-size: 13px"
                        :href="alertRulesIndex({ current_team: slug })"
                        >{{ $t('Alert settings') }}</Link
                    >
                </li>
                <li class="step">
                    <span class="num">3</span>
                    <div class="min-w-0 flex-1">
                        <div class="step-title">
                            {{ $t('Invite the team') }}
                        </div>
                        <div class="step-body">
                            {{
                                $t(
                                    'Admin, member or viewer, with visibility limited to a subset of environments if you want.',
                                )
                            }}
                        </div>
                    </div>
                    <Link
                        class="nc-btn nc-btn-secondary flex-none"
                        style="font-size: 13px"
                        :href="membersIndex(slug)"
                        >{{ $t('Members') }}</Link
                    >
                </li>
            </ol>

            <p class="footnote">
                {{
                    $t(
                        'The panel is read-only: it polls Horizon, it does not drive it. All it needs is a user with access to /horizon, with basic auth if the endpoint asks for it.',
                    )
                }}
            </p>
        </div>
    </div>
</template>

<style scoped>
.swatch {
    width: 26px;
    height: 6px;
    border-radius: 3px;
}

.lead {
    margin: 0;
    font-size: 14px;
    line-height: 1.55;
    color: var(--nc-neutral-400);
    text-wrap: pretty;
}

ol {
    margin: 0;
    padding: 0;
    list-style: none;
}

.step {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--nc-space-3);
    padding: var(--nc-space-4);
    border-radius: var(--nc-radius-md);
    background: var(--nc-surface);
    box-shadow: var(--nc-shadow-sm);
}

.num {
    flex: none;
    display: grid;
    place-items: center;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    font-size: 11px;
    background: var(--nc-accent-800);
    color: var(--nc-accent-100);
}

.step-title {
    font-size: 15px;
    font-weight: 500;
}

.step-body {
    margin-top: 3px;
    font-size: 12px;
    line-height: 1.5;
    color: var(--nc-neutral-400);
}

.footnote {
    margin: 0;
    font-size: 12px;
    line-height: 1.55;
    color: var(--nc-neutral-600);
}
</style>
