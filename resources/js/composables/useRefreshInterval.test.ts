import { afterEach, describe, expect, it, vi } from 'vite-plus/test';

const KEY = 'horizon-watch.refresh-interval';

function fakeStorage(initial: Record<string, string> = {}) {
    const values = new Map(Object.entries(initial));

    return {
        values,
        getItem: (key: string) => values.get(key) ?? null,
        setItem: (key: string, value: string) => {
            values.set(key, value);
        },
    };
}

async function load(storage: unknown) {
    vi.resetModules();
    vi.stubGlobal('window', { localStorage: storage });

    const { useRefreshInterval } =
        await import('@/composables/useRefreshInterval');

    return useRefreshInterval();
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('useRefreshInterval', () => {
    it('starts from the stored interval', async () => {
        const { interval } = await load(fakeStorage({ [KEY]: '60000' }));

        expect(interval.value).toBe(60000);
    });

    it('falls back to fifteen seconds for a value it does not offer', async () => {
        const { interval } = await load(fakeStorage({ [KEY]: '1234' }));

        expect(interval.value).toBe(15000);
    });

    it('falls back to fifteen seconds when storage is blocked', async () => {
        const { interval, set } = await load({
            getItem: () => {
                throw new Error('blocked');
            },
            setItem: () => {
                throw new Error('blocked');
            },
        });

        expect(interval.value).toBe(15000);

        set(30000);

        expect(interval.value).toBe(30000);
    });

    it('stores a valid choice and ignores any other', async () => {
        const storage = fakeStorage();
        const { interval, options, set } = await load(storage);

        set(5000);
        set(7000);

        expect(interval.value).toBe(5000);
        expect(storage.values.get(KEY)).toBe('5000');
        expect(options).toEqual([5000, 10000, 15000, 30000, 60000, 300000]);
    });

    it('shares one interval between every caller', async () => {
        const first = await load(fakeStorage());
        const { useRefreshInterval } =
            await import('@/composables/useRefreshInterval');

        useRefreshInterval().set(300000);

        expect(first.interval.value).toBe(300000);
    });
});
