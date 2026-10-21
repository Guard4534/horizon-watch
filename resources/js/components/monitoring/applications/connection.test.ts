import {
    PhCheckCircle,
    PhCircleNotch,
    PhHourglassMedium,
    PhMinusCircle,
    PhWarning,
} from '@phosphor-icons/vue';
import { describe, expect, it } from 'vite-plus/test';
import {
    connectionIcon,
    connectionMessage,
    connectionState,
    connectionTone,
} from '@/components/monitoring/applications/connection';
import type { ConnectionOutcome } from '@/components/monitoring/applications/connection';
import { readingErrorLabel } from '@/components/monitoring/environment/readings';

const result = (
    overrides: Partial<App.Data.Monitoring.ConnectionResultData> = {},
): App.Data.Monitoring.ConnectionResultData => ({
    reachable: true,
    horizonStatus: 'running',
    masterCount: 2,
    latencyMs: 40,
    error: null,
    problem: null,
    suggestedUrl: null,
    credentialsInClear: false,
    ...overrides,
});

const done = (
    overrides: Partial<App.Data.Monitoring.ConnectionResultData> = {},
): ConnectionOutcome => ({ state: 'done', result: result(overrides) });

const refused = done({
    reachable: false,
    horizonStatus: null,
    masterCount: null,
    latencyMs: null,
    error: 'unauthorized',
});

const outcomes: Record<string, ConnectionOutcome> = {
    idle: { state: 'idle' },
    testing: { state: 'testing' },
    connected: done(),
    refused,
    throttled: { state: 'throttled' },
    invalid: { state: 'invalid', message: 'The Horizon URL is not valid.' },
    failed: { state: 'failed' },
};

describe('connectionTone', () => {
    it.each<[string, string]>([
        ['idle', 'var(--nc-neutral-500)'],
        ['testing', 'var(--nc-neutral-400)'],
        ['connected', 'var(--st-ok)'],
        ['refused', 'var(--st-down)'],
        ['throttled', 'var(--st-warn)'],
        ['invalid', 'var(--st-down)'],
        ['failed', 'var(--st-down)'],
    ])('%s is %s', (name, tone) => {
        expect(connectionTone(outcomes[name])).toBe(tone);
    });
});

describe('connectionIcon', () => {
    it.each<[string, unknown]>([
        ['idle', PhMinusCircle],
        ['testing', PhCircleNotch],
        ['connected', PhCheckCircle],
        ['refused', PhWarning],
        ['throttled', PhHourglassMedium],
        ['invalid', PhWarning],
        ['failed', PhWarning],
    ])('%s', (name, icon) => {
        expect(connectionIcon(outcomes[name])).toBe(icon);
    });
});

describe('connectionState', () => {
    it.each<[string, string]>([
        ['idle', 'Not tested'],
        ['testing', 'Testing…'],
        ['connected', 'Connected'],
        ['refused', 'Not connected'],
        ['throttled', 'Not tested'],
        ['invalid', 'Not tested'],
        ['failed', 'Not tested'],
    ])('%s reads "%s"', (name, label) => {
        expect(connectionState(outcomes[name])).toBe(label);
    });
});

describe('connectionMessage', () => {
    it('says nothing before a test', () => {
        expect(connectionMessage(outcomes.idle)).toBeNull();
    });

    it('describes a working connection', () => {
        expect(connectionMessage(outcomes.connected)).toBe(
            'Connected · Horizon running · 2 masters · 40 ms',
        );
        expect(connectionMessage(done({ masterCount: 1 }))).toBe(
            'Connected · Horizon running · 1 master · 40 ms',
        );
    });

    it('names a refused connection with the shared reading-error label', () => {
        expect(connectionMessage(refused)).toBe(
            readingErrorLabel('unauthorized'),
        );
    });

    it('explains why a test could not run', () => {
        expect(connectionMessage(outcomes.testing)).toBe(
            'Testing the connection…',
        );
        expect(connectionMessage(outcomes.throttled)).toBe(
            'Too many attempts. Wait a minute and try again.',
        );
        expect(connectionMessage(outcomes.invalid)).toBe(
            'The Horizon URL is not valid.',
        );
        expect(connectionMessage(outcomes.failed)).toBe(
            'The test could not run. Reload the page and try again.',
        );
        expect(connectionMessage(done({ reachable: false }))).toBe(
            'The test could not run. Reload the page and try again.',
        );
    });
});
