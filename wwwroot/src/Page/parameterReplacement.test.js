import {
    createParameterReplacementRequestGuard,
    createDeviceParameterOptions,
    createParameterReplacementContext,
    createParameterReplacementMappingPlan,
    isParameterReplacementSelectionCurrent,
    replaceSelectedParameterBindingMappings,
    replaceSelectedParameterBindings,
} from './parameterReplacement';

const createShape = (id, deviceId, parameterName, extra = {}) => ({
    id,
    moduleJson: {
        attrs: {
            dataKey: [{
                key: deviceId,
                name: parameterName,
                type: 'mqtt',
                cmdtype: 'telemetry',
                src: '10.0.0.1',
                ...extra,
            }],
        },
        children: [],
    },
});

describe('parameter replacement', () => {
    test('accepts only a multi-selection bound to one device', () => {
        const sameDevice = createParameterReplacementContext([
            createShape('one', 'device-1', 'temperature'),
            createShape('two', 'device-1', 'humidity'),
        ], ['one', 'two']);
        const mixedDevices = createParameterReplacementContext([
            createShape('one', 'device-1', 'temperature'),
            createShape('two', 'device-2', 'humidity'),
        ], ['one', 'two']);

        expect(sameDevice).toMatchObject({
            valid: true,
            deviceId: 'device-1',
            originalOptions: [
                { label: 'temperature', value: 'temperature' },
                { label: 'humidity', value: 'humidity' },
            ],
        });
        expect(mixedDevices).toMatchObject({ valid: false, reasonKey: 'parameterReplacement.sameDeviceRequired' });
    });

    test('requires the same data source when device IDs are equal', () => {
        const mixedSources = createParameterReplacementContext([
            createShape('one', 'device-1', 'temperature'),
            createShape('two', 'device-1', 'humidity', { sourceHost: '192.168.0.60' }),
        ], ['one', 'two']);
        const remoteSource = createParameterReplacementContext([
            createShape('one', 'device-1', 'temperature', { sourceHost: '192.168.0.60' }),
            createShape('two', 'device-1', 'humidity', { sourceHost: '192.168.0.60:8086' }),
        ], ['one', 'two']);

        expect(mixedSources).toMatchObject({ valid: false, reasonKey: 'parameterReplacement.sameDeviceRequired' });
        expect(remoteSource).toMatchObject({
            valid: true,
            deviceId: 'device-1',
            sourceHost: '192.168.0.60:8086',
        });
    });

    test('derives replacement parameters from the selected device payload', () => {
        const options = createDeviceParameterOptions({
            LinkMode: 'mqtt',
            DeviceLastDataArr: [
                { cmdType: 'telemetry', data: "{'temperature': 22.5, 'pressure': 101.3}" },
            ],
        });

        expect(options).toEqual([
            { label: 'temperature', value: 'temperature|telemetry', name: 'temperature', cmdtype: 'telemetry', type: 'mqtt' },
            { label: 'pressure', value: 'pressure|telemetry', name: 'pressure', cmdtype: 'telemetry', type: 'mqtt' },
        ]);
    });

    test('replaces only selected matching bindings and preserves all other shapes', () => {
        const shapes = [
            createShape('one', 'device-1', 'temperature'),
            createShape('two', 'device-1', 'temperature'),
            createShape('three', 'device-1', 'temperature'),
        ];
        const context = createParameterReplacementContext(shapes, ['one', 'two']);
        const result = replaceSelectedParameterBindings(
            shapes,
            context,
            'temperature',
            { name: 'pressure', cmdtype: 'telemetry-2', type: 'mqtt' },
        );

        expect(result.changedCount).toBe(2);
        expect(result.shapes[0].moduleJson.attrs.dataKey[0]).toMatchObject({
            name: 'pressure',
            cmdtype: 'telemetry-2',
            src: '10.0.0.1',
        });
        expect(result.shapes[1].moduleJson.attrs.dataKey[0].name).toBe('pressure');
        expect(result.shapes[2]).toBe(shapes[2]);
        expect(shapes[0].moduleJson.attrs.dataKey[0].name).toBe('temperature');
    });

    test('replaces every configured original parameter without cascading into another mapping', () => {
        const shapes = [
            createShape('one', 'device-1', 'temperature'),
            createShape('two', 'device-1', 'humidity'),
            createShape('three', 'device-1', 'pressure'),
            createShape('four', 'device-1', 'humidity'),
        ];
        const context = createParameterReplacementContext(shapes, ['one', 'two', 'three']);
        const result = replaceSelectedParameterBindingMappings(shapes, context, [
            {
                originalName: 'temperature',
                replacement: { name: 'humidity', cmdtype: 'telemetry-2', type: 'mqtt' },
            },
            {
                originalName: 'humidity',
                replacement: { name: 'temperature', cmdtype: 'telemetry-3', type: 'mqtt' },
            },
        ]);

        expect(result.changedCount).toBe(2);
        expect(result.shapes.map((shape) => shape.moduleJson.attrs.dataKey[0].name)).toEqual([
            'humidity',
            'temperature',
            'pressure',
            'humidity',
        ]);
        expect(result.shapes[0].moduleJson.attrs.dataKey[0].cmdtype).toBe('telemetry-2');
        expect(result.shapes[1].moduleJson.attrs.dataKey[0].cmdtype).toBe('telemetry-3');
        expect(result.shapes[2]).toBe(shapes[2]);
        expect(result.shapes[3]).toBe(shapes[3]);
    });

    test('creates a common-keyword mapping plan only for replacement parameters on the selected device', () => {
        const plan = createParameterReplacementMappingPlan(
            [
                { label: 'active energy', value: 'active energy' },
                { label: 'reactive energy', value: 'reactive energy' },
            ],
            [
                { label: 'active power', value: 'active power|telemetry', name: 'active power', cmdtype: 'telemetry', type: 'mqtt' },
                { label: 'reactive energy', value: 'reactive energy|telemetry', name: 'reactive energy', cmdtype: 'telemetry', type: 'mqtt' },
            ],
            {},
            'energy',
            'power'
        );

        expect(plan.mappings).toEqual([
            {
                originalName: 'active energy',
                replacement: { label: 'active power', value: 'active power|telemetry', name: 'active power', cmdtype: 'telemetry', type: 'mqtt' },
            },
        ]);
        expect(plan.missingNames).toEqual(['reactive power']);
    });

    test('keeps manually selected replacements ahead of common-keyword mappings', () => {
        const plan = createParameterReplacementMappingPlan(
            [
                { label: 'active energy', value: 'active energy' },
                { label: 'reactive energy', value: 'reactive energy' },
            ],
            [
                { label: 'voltage', value: 'voltage|telemetry', name: 'voltage', cmdtype: 'telemetry', type: 'mqtt' },
                { label: 'reactive power', value: 'reactive power|telemetry', name: 'reactive power', cmdtype: 'telemetry', type: 'mqtt' },
            ],
            { 'active energy': 'voltage|telemetry' },
            'energy',
            'power'
        );

        expect(plan.mappings.map((mapping) => [mapping.originalName, mapping.replacement.name])).toEqual([
            ['active energy', 'voltage'],
            ['reactive energy', 'reactive power'],
        ]);
        expect(plan.missingNames).toEqual([]);
    });

    test('recognizes the current selection regardless of selection order', () => {
        const context = createParameterReplacementContext([
            createShape('one', 'device-1', 'temperature'),
            createShape('two', 'device-1', 'temperature'),
        ], ['one', 'two']);

        expect(isParameterReplacementSelectionCurrent(context, ['two', 'one'])).toBe(true);
        expect(isParameterReplacementSelectionCurrent(context, ['one', 'three'])).toBe(false);
    });

    test('accepts only the latest parameter request response', () => {
        const guard = createParameterReplacementRequestGuard();
        const firstRequest = guard.begin();
        const secondRequest = guard.begin();

        expect(guard.isCurrent(firstRequest)).toBe(false);
        expect(guard.isCurrent(secondRequest)).toBe(true);
        guard.invalidate();
        expect(guard.isCurrent(secondRequest)).toBe(false);
    });
});
