import { validatePageElements } from './pageValidation';

const shape = (overrides = {}) => ({
    id: 'shape-1',
    x: 20,
    y: 20,
    width: 120,
    height: 60,
    moduleJson: {
        attrs: {},
        children: [{ className: 'Text', attrs: { text: 'ok' } }],
    },
    ...overrides,
});

describe('validatePageElements', () => {
    test('reports duplicate ids before geometry findings', () => {
        const findings = validatePageElements([
            shape({ id: 'meter', x: 1900, width: 80 }),
            shape({ id: 'meter' }),
        ], { stageWidth: 1920, stageHeight: 1080 });

        expect(findings.map((finding) => finding.code)).toEqual([
            'duplicate-id',
            'out-of-bounds',
        ]);
        expect(findings[0].severity).toBe('error');
        expect(findings[1].elementId).toBe('meter');
    });

    test('reports missing or invalid geometry', () => {
        const findings = validatePageElements([
            shape({ id: '', width: 0, height: -1 }),
        ], { stageWidth: 1920, stageHeight: 1080 });

        expect(findings.map((finding) => finding.code)).toEqual([
            'missing-id',
            'invalid-geometry',
        ]);
    });

    test('uses the component dimensions when the persisted wrapper dimensions are zero', () => {
        const findings = validatePageElements([
            shape({
                id: 'date-text',
                width: 0,
                height: 0,
                moduleJson: {
                    attrs: {},
                    width: 180,
                    height: 42,
                    children: [{ className: 'Text', attrs: { text: '2026/07/31 14:27:46' } }],
                },
            }),
        ], { stageWidth: 1920, stageHeight: 1080 });

        expect(findings).toEqual([]);
    });

    test('reports only incomplete configured data bindings', () => {
        const findings = validatePageElements([
            shape({
                id: 'incomplete-binding',
                moduleJson: {
                    attrs: { dataKey: [{}] },
                    children: [{ className: 'Text', attrs: {} }],
                },
            }),
            shape({
                id: 'complete-binding',
                moduleJson: {
                    attrs: { dataKey: [{ key: '42', name: 'Load' }] },
                    children: [{ className: 'Text', attrs: {} }],
                },
            }),
        ], { stageWidth: 1920, stageHeight: 1080 });

        expect(findings.map((finding) => finding.code)).toEqual(['incomplete-data-binding']);
        expect(findings[0].elementId).toBe('incomplete-binding');
    });

    test('blocks publishing when a declared data point has no binding', () => {
        const findings = validatePageElements([
            shape({
                id: 'missing-data-point',
                moduleJson: {
                    attrs: {
                        dataKey: [],
                        moduleAttr: [{
                            attrGroupContent: [{
                                attrCode: 'dataKey',
                                attrType: 'hardwareInputNew',
                            }],
                        }],
                    },
                    children: [{ className: 'Text', attrs: {} }],
                },
            }),
        ], { stageWidth: 1920, stageHeight: 1080 });

        expect(findings).toEqual([
            expect.objectContaining({
                code: 'missing-data-binding',
                severity: 'error',
                elementId: 'missing-data-point',
            }),
        ]);
    });

    test('blocks publishing when a declared data point has an invalid binding', () => {
        const findings = validatePageElements([
            shape({
                id: 'invalid-data-point',
                moduleJson: {
                    attrs: {
                        dataKey: [{
                            key: '1588',
                            name: '',
                            type: '3',
                            cmdtype: '1',
                            src: '1',
                        }],
                        moduleAttr: [{
                            attrGroupContent: [{
                                attrCode: 'dataKey',
                                attrType: 'hardwareInputNew',
                            }],
                        }],
                    },
                    children: [{ className: 'Text', attrs: {} }],
                },
            }),
        ], { stageWidth: 1920, stageHeight: 1080 });

        expect(findings).toEqual([
            expect.objectContaining({
                code: 'invalid-data-binding',
                severity: 'error',
                elementId: 'invalid-data-point',
            }),
        ]);
    });

    test('reports chart data lengths that do not match category labels', () => {
        const findings = validatePageElements([
            shape({
                id: 'bar-chart',
                moduleJson: {
                    attrs: {},
                    children: [{
                        className: 'Echart',
                        attrs: {
                            cat: 'bar',
                            xdata: ['A', 'B', 'C'],
                            data: [10, 20],
                        },
                    }],
                },
            }),
            shape({
                id: 'valid-chart',
                moduleJson: {
                    attrs: {},
                    children: [{
                        className: 'Echart',
                        attrs: {
                            cat: 'line',
                            xdata: ['A', 'B'],
                            data: [{ name: 'Load', data: [10, 20] }],
                        },
                    }],
                },
            }),
        ], { stageWidth: 1920, stageHeight: 1080 });

        expect(findings.map((finding) => finding.code)).toEqual(['chart-data-mismatch']);
        expect(findings[0].elementId).toBe('bar-chart');
    });

    test('ignores persisted Konva runtime nodes without a module model', () => {
        const findings = validatePageElements([
            shape({ id: 'real-element' }),
            {},
            {},
            {},
        ], { stageWidth: 1920, stageHeight: 1080 });

        expect(findings).toEqual([]);
    });
});
