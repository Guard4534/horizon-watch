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

const BASE_URL = 'https://shop.example.com/horizon';

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

async function wizardFor(baseUrl = BASE_URL) {
    const wizard = useApplicationWizard();

    wizard.form.application.name = 'Billing';
    wizard.form.environments[0].horizonUrl = baseUrl;
    await nextTick();

    return wizard;
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.clearErrors.mockReset();
});

describe('useApplicationWizard', () => {
    it('starts with a production environment whose URL is typed by hand', () => {
        const { form, rows } = useApplicationWizard();

        expect(form.application).toEqual({ name: '' });
        expect(form.environments).toEqual([
            {
                name: 'production',
                type: 'production',
                horizonUrl: '',
                basicAuthUser: null,
                basicAuthPassword: null,
                pollIntervalSeconds: 15,
                pollingEnabled: true,
            },
        ]);
        expect(rows.value[0]).toMatchObject({
            urlEdited: false,
            typeEdited: false,
        });
    });

    it('suggests the next usual environment with its type', async () => {
        const { form, addEnvironment } = await wizardFor();

        addEnvironment();
        addEnvironment();
        addEnvironment();

        expect(form.environments.map(({ name, type }) => [name, type])).toEqual(
            [
                ['production', 'production'],
                ['staging', 'staging'],
                ['preprod', 'preprod'],
                ['develop', 'development'],
            ],
        );
    });

    it('falls back to an unnamed staging environment once the usual names are taken', () => {
        const { form, addEnvironment } = useApplicationWizard();

        for (let added = 0; added < 6; added++) {
            addEnvironment();
        }

        expect(
            form.environments.slice(4).map(({ name, type }) => [name, type]),
        ).toEqual([
            ['demo', 'demo'],
            ['testing', 'testing'],
            ['', 'staging'],
        ]);
    });

    it('derives each further Horizon URL from the first row', async () => {
        const { form, addEnvironment } = await wizardFor();

        addEnvironment();
        await nextTick();

        expect(form.environments.map((env) => env.horizonUrl)).toEqual([
            'https://shop.example.com/horizon',
            'https://staging.shop.example.com/horizon',
        ]);

        form.environments[0].horizonUrl =
            'https://production.shop.example.com/ops/horizon/';
        await nextTick();

        expect(form.environments[1].horizonUrl).toBe(
            'https://staging.shop.example.com/ops/horizon',
        );

        form.environments[1].name = 'qa';
        await nextTick();

        expect(form.environments[1].horizonUrl).toBe(
            'https://qa.shop.example.com/ops/horizon',
        );
        expect(form.environments[0].horizonUrl).toBe(
            'https://production.shop.example.com/ops/horizon/',
        );
    });

    it('stops suggesting once a further URL is edited by hand', async () => {
        const { form, rows, onUrlInput, addEnvironment } = await wizardFor();

        addEnvironment();
        await nextTick();

        form.environments[1].horizonUrl = 'https://horizon.example.net';
        onUrlInput(1);
        form.environments[0].horizonUrl = 'https://example.org/horizon';
        await nextTick();

        expect(rows.value[1].urlEdited).toBe(true);
        expect(form.environments[1].horizonUrl).toBe(
            'https://horizon.example.net',
        );

        form.environments[1].horizonUrl = 'https://staging.example.org/horizon';
        onUrlInput(1);

        expect(rows.value[1].urlEdited).toBe(false);
    });

    it('needs the application name, and a name and a URL for every environment', async () => {
        const wizard = useApplicationWizard();

        expect(wizard.applicationFilled.value).toBe(false);
        expect(wizard.environmentsFilled.value).toBe(false);

        wizard.form.application.name = 'Billing';
        await nextTick();

        expect(wizard.applicationFilled.value).toBe(true);
        expect(wizard.environmentsFilled.value).toBe(false);

        wizard.form.environments[0].horizonUrl = BASE_URL;
        wizard.addEnvironment();
        await nextTick();

        expect(wizard.environmentsFilled.value).toBe(true);

        wizard.form.environments[1].name = ' ';
        await nextTick();

        expect(wizard.form.environments[1].horizonUrl).toBe('');
        expect(wizard.environmentsFilled.value).toBe(false);

        wizard.removeEnvironment(1);

        expect(wizard.environmentsFilled.value).toBe(true);
        expect(wizard.rows.value).toHaveLength(1);
        expect(inertia.clearErrors).toHaveBeenCalledOnce();
    });

    it('names the domain after the production row, else after the first row', async () => {
        const { form, domain, addEnvironment, onUrlInput } = await wizardFor();

        expect(domain.value).toBe('shop.example.com');

        form.environments[0].type = 'staging';
        addEnvironment();
        await nextTick();

        expect(domain.value).toBe('shop.example.com');

        form.environments[1].type = 'production';
        form.environments[1].horizonUrl = 'https://live.example.net/horizon';
        onUrlInput(1);

        expect(domain.value).toBe('live.example.net');
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

        onError({ 'application.name': 'Taken.', 'environments.0.name': 'x' });

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
