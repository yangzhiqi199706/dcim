import {
    createParameterReplacementRequestGuard,
    createDeviceParameterOptions,
    createParameterReplacementContext,
    isParameterReplacementSelectionCurrent,
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
