import { describe, expect, it } from 'vite-plus/test';
import {
    domainOf,
    followSuggestion,
    hostOf,
    isProductionType,
    leavesSuggestion,
    suggestHorizonUrl,
    suggestType,
    typeForName,
} from '@/lib/environments';

describe('suggestType', () => {
    it.each([
        ['production', 'production'],
        ['prod-eu', 'production'],
        ['prd', 'production'],
        ['live.west', 'production'],
        [' PROD_US ', 'production'],
        ['production 2', 'production'],
        ['preprod', 'preprod'],
        ['pre-production', 'preprod'],
        ['pre-production-2', 'preprod'],
        ['uat', 'preprod'],
        ['staging', 'staging'],
        ['stage-1', 'staging'],
        ['develop', 'development'],
        ['development', 'development'],
        ['dev', 'development'],
        ['test', 'testing'],
        ['testing', 'testing'],
        ['qa_2', 'testing'],
        ['demo-fair', 'demo'],
        ['products', null],
        ['eu-production', null],
        ['worker-batch', null],
        ['constructor', null],
        ['', null],
    ])('reads %s as %s', (name, type) => {
        expect(suggestType(name)).toBe(type);
    });
});

describe('typeForName', () => {
    it('falls back to staging when the name suggests nothing', () => {
        expect(typeForName('blue')).toBe('staging');
        expect(typeForName('')).toBe('staging');
        expect(typeForName('qa')).toBe('testing');
    });
});

describe('isProductionType', () => {
    it('is true for the production type only', () => {
        expect(isProductionType('production')).toBe(true);
        expect(isProductionType('preprod')).toBe(false);
        expect(isProductionType(null)).toBe(false);
    });
});

describe('hostOf', () => {
    it.each([
        ['https://Shop.Example.com:8443/horizon', 'shop.example.com'],
        ['http://staging.shop.example.com', 'staging.shop.example.com'],
        ['shop.example.com/horizon', null],
        ['ftp://shop.example.com', null],
        ['', null],
    ])('reads the host of %s', (url, host) => {
        expect(hostOf(url)).toBe(host);
    });
});

describe('domainOf', () => {
    it('takes the host of the production environment, else of the first one', () => {
        expect(
            domainOf([
                {
                    type: 'staging',
                    horizonUrl: 'https://staging.shop.example.com/horizon',
                },
                {
                    type: 'production',
                    horizonUrl: 'https://shop.example.com/horizon',
                },
            ]),
        ).toBe('shop.example.com');
        expect(
            domainOf([
                {
                    type: 'staging',
                    horizonUrl: 'https://staging.shop.example.com/horizon',
                },
                { type: 'demo', horizonUrl: 'https://demo.shop.example.com' },
            ]),
        ).toBe('staging.shop.example.com');
        expect(domainOf([])).toBeNull();
        expect(domainOf([{ type: 'production', horizonUrl: '' }])).toBeNull();
    });
});

describe('suggestHorizonUrl', () => {
    it.each<[string, string, string, App.Enums.EnvironmentType, string]>([
        [
            'https://shop.example.com/horizon',
            'production',
            'staging',
            'staging',
            'https://staging.shop.example.com/horizon',
        ],
        [
            'https://production.shop.example.com/horizon',
            'production',
            'staging',
            'staging',
            'https://staging.shop.example.com/horizon',
        ],
        [
            'https://staging.shop.example.com/ops/horizon/',
            'staging',
            'production',
            'production',
            'https://shop.example.com/ops/horizon',
        ],
        [
            'https://shop.example.com:8443/horizon',
            'production',
            'qa',
            'testing',
            'https://qa.shop.example.com:8443/horizon',
        ],
        [
            'http://shop.example.com',
            'production',
            'Demo',
            'demo',
            'http://demo.shop.example.com',
        ],
        [
            'https://shop.example.com/horizon',
            'production',
            'prod-eu',
            'production',
            'https://shop.example.com/horizon',
        ],
        [
            'https://shop.example.com/horizon',
            'production',
            'live-preview',
            'staging',
            'https://live-preview.shop.example.com/horizon',
        ],
        [
            'https://shop.example.com/horizon',
            'staging',
            'eu',
            'production',
            'https://shop.example.com/horizon',
        ],
        [
            'HTTPS://Shop.Example.com/horizon/api/stats/',
            'production',
            'staging',
            'staging',
            'https://staging.shop.example.com/horizon',
        ],
        [
            'https://shop.example.com/ops/horizon/api',
            'production',
            'qa',
            'testing',
            'https://qa.shop.example.com/ops/horizon',
        ],
        [
            'https://shop.example.com/api',
            'production',
            'staging',
            'staging',
            'https://staging.shop.example.com/api',
        ],
        [
            'https://shop.example.com/tools/api/horizon',
            'production',
            'staging',
            'staging',
            'https://staging.shop.example.com/tools/api/horizon',
        ],
        ['', 'production', 'staging', 'staging', ''],
        ['shop.example.com/horizon', 'production', 'staging', 'staging', ''],
        ['https://shop.example.com/horizon', 'production', ' ', 'staging', ''],
    ])(
        'from %s (%s) suggests for %s (%s): %s',
        (base, baseName, name, type, expected) => {
            expect(suggestHorizonUrl(base, baseName, name, type)).toBe(
                expected,
            );
        },
    );
});

describe('followSuggestion', () => {
    it('holds the suggestion until the field is edited by hand', () => {
        expect(
            followSuggestion(
                '',
                'https://staging.shop.example.com/horizon',
                false,
            ),
        ).toBe('https://staging.shop.example.com/horizon');
        expect(
            followSuggestion(
                'https://horizon.example.net',
                'https://staging.shop.example.com/horizon',
                true,
            ),
        ).toBe('https://horizon.example.net');
    });

    it('lets the type follow the name until the type is picked by hand', () => {
        expect(followSuggestion('staging', typeForName('prod-eu'), false)).toBe(
            'production',
        );
        expect(followSuggestion('demo', typeForName('prod-eu'), true)).toBe(
            'demo',
        );
    });
});

describe('leavesSuggestion', () => {
    it('is true only for a typed value other than the suggestion', () => {
        expect(
            leavesSuggestion(
                'https://horizon.example.net',
                'https://staging.shop.example.com/horizon',
            ),
        ).toBe(true);
        expect(
            leavesSuggestion(
                'https://staging.shop.example.com/horizon',
                'https://staging.shop.example.com/horizon',
            ),
        ).toBe(false);
    });
});
