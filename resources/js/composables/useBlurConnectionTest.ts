import { tryOnScopeDispose } from '@vueuse/core';

export type BlurConnectionTest = {
    onUrlBlur: () => void;
    noteTested: () => void;
};

const COMPLETE_ADDRESS = /^https?:\/\/[^\s/?#]+/i;

export function useBlurConnectionTest(
    payload: () => App.Data.Applications.TestConnectionData,
    run: () => Promise<void> | void,
    delayMs = 400,
): BlurConnectionTest {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let tested = '';

    const noteTested = (): void => {
        clearTimeout(timer);
        tested = JSON.stringify(payload());
    };

    const onUrlBlur = (): void => {
        clearTimeout(timer);

        timer = setTimeout(() => {
            const current = payload();

            if (!COMPLETE_ADDRESS.test(current.horizonUrl.trim())) {
                return;
            }

            if (JSON.stringify(current) === tested) {
                return;
            }

            noteTested();
            void run();
        }, delayMs);
    };

    tryOnScopeDispose(() => clearTimeout(timer));

    return { onUrlBlur, noteTested };
}
