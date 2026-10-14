import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { computed, nextTick, reactive } from 'vue';
import { useApplicationWizard } from '@/composables/useApplicationWizard';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    clearErrors: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    useForm: <T extends object>(data: T) =>
        reactive({
            ...data,
            errors: {} as Record<string, string>,
            post: inertia.post,
            clearErrors: inertia.clearErrors,
        }),
}));

vi.mock('@/composables/useTeamSlug', () => ({
    useTeamSlug: () => computed(() => 'acme'),
}));

vi.mock('@/routes/applications', () => ({
    store: (team: string) => ({ url: `/${team}/applications`, method: 'post' }),
}));

const COLORS = [
    { value: 'prod', label: 'Production' },
    { value: 'staging', label: 'Staging' },
    { value: 'preprod', label: 'Pre-production' },
    { value: 'develop', label: 'Develop' },
    { value: 'demo', label: 'Demo' },
    { value: 'testing', label: 'Testing' },
    { value: 'worker', label: 'Worker' },
];

const reachable = (yes: boolean) =>
    ({
        state: 'done',
        result: {
            reachable: yes,
            horizonStatus: yes ? 'running' : null,
            masterCount: yes ? 1 : null,
            latencyMs: 40,
            error: yes ? null : 'unreachable',
            problem: null,
            suggestedUrl: null,
            credentialsInClear: false,
        },
    }) as const;

async function wizardFor(host = 'example.com') {
    const wizard = useApplicationWizard(COLORS);

    wizard.form.application.name = 'Billing';
    wizard.form.application.host = host;
    await nextTick();

    return wizard;
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.clearErrors.mockReset();
});

describe('useApplicationWizard', () => {
    it('starts with a production environment', () => {
        const { form } = useApplicationWizard(COLORS);

        expect(form.environments).toHaveLength(1);
        expect(form.environments[0]).toMatchObject({
            name: 'production',
            color: 'prod',
            horizonUrl: '',
            isProduction: true,
            pollIntervalSeconds: 15,
        });
    });

    it('suggests the next usual environment with its colour', async () => {
        const { form, addEnvironment } = await wizardFor();

        addEnvironment();
        addEnvironment();

        expect(
            form.environments.map(({ name, color }) => [name, color]),
        ).toEqual([
            ['production', 'prod'],
            ['staging', 'staging'],
            ['preprod', 'preprod'],
        ]);
        expect(form.environments[1].isProduction).toBe(false);
    });

    it('falls back to an unnamed environment in the next colour', () => {
        const { form, addEnvironment } = useApplicationWizard([
            { value: 'worker', label: 'Worker' },
            { value: 'demo', label: 'Demo' },
        ]);

        addEnvironment();
        addEnvironment();

        expect(
            form.environments.map(({ name, color }) => [name, color]),
        ).toEqual([
            ['demo', 'demo'],
            ['', 'demo'],
            ['', 'worker'],
        ]);
    });

    it('derives each Horizon URL from the host, the environment and the path', async () => {
        const { form, horizonPath, addEnvironment } = await wizardFor(
            ' https://example.com/ ',
        );

        addEnvironment();
        await nextTick();

        expect(form.environments.map((env) => env.horizonUrl)).toEqual([
            'https://example.com/horizon',
            'https://staging.example.com/horizon',
        ]);

        horizonPath.value = '/ops/horizon/';
        await nextTick();

        expect(form.environments[0].horizonUrl).toBe(
            'https://example.com/ops/horizon',
        );

        horizonPath.value = '';
        form.environments[1].name = 'qa';
        await nextTick();

        expect(form.environments[1].horizonUrl).toBe('https://qa.example.com');
    });

    it('stops suggesting once the URL is edited by hand', async () => {
        const { form, rows, onUrlInput } = await wizardFor();

        form.environments[0].horizonUrl = 'https://horizon.example.net';
        onUrlInput(0);
        form.application.host = 'example.org';
        await nextTick();

        expect(rows.value[0].urlEdited).toBe(true);
        expect(form.environments[0].horizonUrl).toBe(
            'https://horizon.example.net',
        );

        form.environments[0].horizonUrl = 'https://example.org/horizon';
        onUrlInput(0);

        expect(rows.value[0].urlEdited).toBe(false);
    });

    it('needs a name and a URL for every environment', async () => {
        const wizard = useApplicationWizard(COLORS);

        expect(wizard.applicationFilled.value).toBe(false);
        expect(wizard.environmentsFilled.value).toBe(false);

        wizard.form.application.name = 'Billing';
        wizard.form.application.host = 'example.com';
        await nextTick();

        expect(wizard.applicationFilled.value).toBe(true);
        expect(wizard.environmentsFilled.value).toBe(true);

        wizard.addEnvironment();
        wizard.form.environments[1].name = ' ';
        await nextTick();

        expect(wizard.environmentsFilled.value).toBe(false);

        wizard.removeEnvironment(1);

        expect(wizard.environmentsFilled.value).toBe(true);
        expect(wizard.rows.value).toHaveLength(1);
        expect(inertia.clearErrors).toHaveBeenCalledOnce();
    });

    it('tests again on the last step only what changed since the last test', async () => {
        const { form, rows, step, addEnvironment } = await wizardFor();

        addEnvironment();
        step.value = 3;
        await nextTick();

        expect(rows.value.every((row) => row.autoTest)).toBe(true);

        rows.value[0].outcome = reachable(true);
        rows.value[1].outcome = { state: 'testing' };
        step.value = 2;
        await nextTick();

        form.environments[0].basicAuthUser = 'ops';
        step.value = 3;
        await nextTick();

        expect(rows.value[0].outcome).toEqual({ state: 'idle' });
        expect(rows.value[0].autoTest).toBe(true);
        expect(rows.value[1].outcome).toEqual({ state: 'idle' });
        expect(rows.value[1].autoTest).toBe(false);

        rows.value[0].outcome = reachable(true);
        step.value = 2;
        await nextTick();
        step.value = 3;
        await nextTick();

        expect(rows.value[0].outcome).toEqual(reachable(true));
    });

    it('runs the connection test again only where it failed', async () => {
        const { rows, rememberTest, testAgain, addEnvironment, testing } =
            await wizardFor();

        addEnvironment();
        const first = { run: vi.fn(async () => {}) };
        const second = { run: vi.fn(async () => {}) };

        rememberTest(rows.value[0].key, first);
        rememberTest(rows.value[1].key, second);
        rows.value[0].outcome = reachable(true);
        rows.value[1].outcome = reachable(false);

        await testAgain();

        expect(first.run).not.toHaveBeenCalled();
        expect(second.run).toHaveBeenCalledOnce();

        rememberTest(rows.value[1].key, null);
        await testAgain();

        expect(second.run).toHaveBeenCalledOnce();

        rows.value[1].outcome = { state: 'testing' };

        expect(testing.value).toBe(true);
    });

    it('posts to the team and returns to the step with the first error', async () => {
        const { submit, step, rows, addEnvironment } = await wizardFor();

        addEnvironment();
        step.value = 3;
        submit();

        expect(inertia.post).toHaveBeenCalledWith(
            '/acme/applications',
            expect.any(Object),
        );

        const { onError } = inertia.post.mock.calls[0][1] as {
            onError: (bag: Record<string, string>) => void;
        };

        onError({ 'environments.1.basicAuthPassword': 'Required.' });

        expect(step.value).toBe(2);
        expect(rows.value.map((row) => row.auth)).toEqual([false, true]);

        onError({ 'application.host': 'Taken.', 'environments.0.name': 'x' });

        expect(step.value).toBe(1);

        onError({ limit: 'Too many environments.' });

        expect(step.value).toBe(3);
    });

    it('lists the errors no field shows', async () => {
        const { form, unplacedErrors } = await wizardFor();

        Object.assign(form.errors, {
            'application.name': 'Required.',
            'environments.0.horizonUrl': 'Invalid.',
            limit: 'Too many environments.',
        });

        expect(unplacedErrors.value).toEqual(['Too many environments.']);
    });
});
