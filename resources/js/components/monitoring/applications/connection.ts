import {
    PhCheckCircle,
    PhCircleNotch,
    PhHourglassMedium,
    PhMinusCircle,
    PhWarning,
} from '@phosphor-icons/vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import type { Component } from 'vue';
import {
    horizonStatusLabel,
    readingErrorLabel,
} from '@/components/monitoring/environment/readings';

export type ConnectionOutcome =
    | { state: 'idle' }
    | { state: 'testing' }
    | { state: 'done'; result: App.Data.Monitoring.ConnectionResultData }
    | { state: 'throttled' }
    | { state: 'invalid'; message: string }
    | { state: 'failed' };

export function connectionTone(outcome: ConnectionOutcome): string {
    switch (outcome.state) {
        case 'idle':
            return 'var(--nc-neutral-500)';
        case 'testing':
            return 'var(--nc-neutral-400)';
        case 'done':
            return outcome.result.reachable ? 'var(--st-ok)' : 'var(--st-down)';
        case 'throttled':
            return 'var(--st-warn)';
        case 'invalid':
        case 'failed':
            return 'var(--st-down)';
    }
}

export function connectionIcon(outcome: ConnectionOutcome): Component {
    switch (outcome.state) {
        case 'idle':
            return PhMinusCircle;
        case 'testing':
            return PhCircleNotch;
        case 'done':
            return outcome.result.reachable ? PhCheckCircle : PhWarning;
        case 'throttled':
            return PhHourglassMedium;
        case 'invalid':
        case 'failed':
            return PhWarning;
    }
}

export function connectionState(outcome: ConnectionOutcome): string {
    switch (outcome.state) {
        case 'testing':
            return trans('Testing…');
        case 'done':
            return outcome.result.reachable
                ? trans('Connected')
                : trans('Not connected');
        default:
            return trans('Not tested');
    }
}

export function connectionMessage(outcome: ConnectionOutcome): string | null {
    switch (outcome.state) {
        case 'idle':
            return null;
        case 'testing':
            return trans('Testing the connection…');
        case 'throttled':
            return trans('Too many attempts. Wait a minute and try again.');
        case 'invalid':
            return outcome.message;
        case 'failed':
            return trans(
                'The test could not run. Reload the page and try again.',
            );
        case 'done':
            return resultMessage(outcome.result);
    }
}

function resultMessage(
    result: App.Data.Monitoring.ConnectionResultData,
): string {
    if (result.reachable) {
        return transChoice(
            'Connected · Horizon :status · :count master · :ms ms|Connected · Horizon :status · :count masters · :ms ms',
            result.masterCount ?? 0,
            {
                status: result.horizonStatus
                    ? horizonStatusLabel(result.horizonStatus)
                    : '—',
                ms: String(result.latencyMs ?? 0),
            },
        );
    }

    return result.error
        ? readingErrorLabel(result.error)
        : trans('The test could not run. Reload the page and try again.');
}
