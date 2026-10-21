import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { useBlurConnectionTest } from '@/composables/useBlurConnectionTest';

const payload = (
    horizonUrl: string,
    basicAuthUser: string | null = null,
): App.Data.Applications.TestConnectionData => ({
    horizonUrl,
    basicAuthUser,
    basicAuthPassword: null,
});

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('useBlurConnectionTest', () => {
    it('tests once after the URL field loses focus', () => {
        let current = payload('https://shop.example.com/horizon');
        const run = vi.fn();
        const { onUrlBlur } = useBlurConnectionTest(() => current, run);

        onUrlBlur();
        expect(run).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        expect(run).toHaveBeenCalledOnce();

        onUrlBlur();
        vi.advanceTimersByTime(400);
        expect(run).toHaveBeenCalledOnce();

        current = payload('https://shop.example.com/horizon', 'ops');
        onUrlBlur();
        vi.advanceTimersByTime(400);
        expect(run).toHaveBeenCalledTimes(2);
    });

    it('waits for the last of several quick blurs', () => {
        const run = vi.fn();
        const { onUrlBlur } = useBlurConnectionTest(
            () => payload('https://shop.example.com/horizon'),
            run,
        );

        onUrlBlur();
        vi.advanceTimersByTime(200);
        onUrlBlur();
        vi.advanceTimersByTime(200);
        expect(run).not.toHaveBeenCalled();

        vi.advanceTimersByTime(200);
        expect(run).toHaveBeenCalledOnce();
    });

    it('skips the pending test when the connection was just tested by hand', () => {
        const run = vi.fn();
        const { onUrlBlur, noteTested } = useBlurConnectionTest(
            () => payload('https://shop.example.com/horizon'),
            run,
        );

        onUrlBlur();
        noteTested();
        vi.advanceTimersByTime(400);
        expect(run).not.toHaveBeenCalled();

        onUrlBlur();
        vi.advanceTimersByTime(400);
        expect(run).not.toHaveBeenCalled();
    });

    it('leaves an empty or incomplete address alone', () => {
        const run = vi.fn();

        for (const url of ['', '   ', 'shop.example.com', 'https://']) {
            useBlurConnectionTest(() => payload(url), run).onUrlBlur();
        }
        vi.advanceTimersByTime(1000);

        expect(run).not.toHaveBeenCalled();
    });
});
