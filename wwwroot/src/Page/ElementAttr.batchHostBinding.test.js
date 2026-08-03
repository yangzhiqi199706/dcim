jest.mock('../Assets/httpsend', () => ({
    getData: jest.fn(),
}));

import {
    applyHostBindingToSelection,
    getBatchHostBindingState,
    isHostBindingEditable,
    parseHostBindingOption,
} from './ElementAttr';

const createHostShape = (id, binding, attrCode = 'dataDevKey') => ({
    id,
    moduleJson: {
        attrs: {
            dataKey: binding ? [binding] : [],
            moduleAttr: [{
                attrGroupContent: [{
                    attrCode,
                    attrType: 'hardwareInputNew',
                }],
            }],
        },
    },
});

const createPlainShape = (id) => ({
    id,
    moduleJson: {
        attrs: {
            moduleAttr: [],
        },
    },
});

describe('ElementAttr batch host binding', () => {
    test('exposes the shared host binding for text elements using dataKey', () => {
        const first = createHostShape('text-first', {
            key: 'device-1',
            type: 'A',
            src: '1',
            name: 'temperature',
            cmdtype: 'read',
        }, 'dataKey');
        const second = createHostShape('text-second', {
            key: 'device-2',
            type: 'B',
            src: '10.0.0.2@2',
            name: 'humidity',
            cmdtype: 'read',
        }, 'dataKey');

        expect(isHostBindingEditable(first)).toBe(true);
        expect(getBatchHostBindingState([first, second])).toEqual({
            available: true,
            binding: null,
            mixed: true,
        });
    });

    test('keeps each text element parameter details when its host changes', () => {
        const first = createHostShape('text-first', {
            key: 'device-1',
            type: 'A',
            src: '1',
            name: 'temperature',
            cmdtype: 'read',
        }, 'dataKey');
        const second = createHostShape('text-second', {
            key: 'device-1',
            type: 'A',
            src: '1',
            name: 'humidity',
            cmdtype: 'read',
        }, 'dataKey');

        const updated = applyHostBindingToSelection([first, second], ['text-first', 'text-second'], {
            key: 'device-9',
            type: 'C',
            src: '10.0.0.9@9',
        });

        expect(updated[0].moduleJson.attrs.dataKey).toEqual([{
            key: 'device-9',
            type: 'C',
            src: '10.0.0.9@9',
            name: 'temperature',
            cmdtype: 'read',
        }]);
        expect(updated[1].moduleJson.attrs.dataKey).toEqual([{
            key: 'device-9',
            type: 'C',
            src: '10.0.0.9@9',
            name: 'humidity',
            cmdtype: 'read',
        }]);
    });

    test('exposes the shared host binding only when every selected element supports it', () => {
        const sharedBinding = { key: 'device-1', type: 'A', src: '1' };
        const first = createHostShape('first', sharedBinding);
        const second = createHostShape('second', sharedBinding);

        expect(isHostBindingEditable(first)).toBe(true);
        expect(getBatchHostBindingState([first, second])).toEqual({
            available: true,
            binding: sharedBinding,
            mixed: false,
        });
        expect(getBatchHostBindingState([first, createPlainShape('plain')]).available).toBe(false);
    });

    test('keeps a mixed host selection empty until the user chooses a replacement', () => {
        const state = getBatchHostBindingState([
            createHostShape('first', { key: 'device-1', type: 'A', src: '1' }),
            createHostShape('second', { key: 'device-2', type: 'B', src: '10.0.0.2@2' }),
        ]);

        expect(state).toEqual({
            available: true,
            binding: null,
            mixed: true,
        });
        expect(parseHostBindingOption('device-3&C/10.0.0.3@3')).toEqual({
            key: 'device-3',
            type: 'C',
            src: '10.0.0.3@3',
        });
    });

    test('applies a selected host binding to every selected compatible element without mutating other elements', () => {
        const first = createHostShape('first', { key: 'device-1', type: 'A', src: '1' });
        const second = createHostShape('second', { key: 'device-2', type: 'B', src: '1' });
        const untouched = createPlainShape('untouched');
        const nextBinding = { key: 'device-9', type: 'C', src: '10.0.0.9@9' };

        const updated = applyHostBindingToSelection([first, second, untouched], ['first', 'second'], nextBinding);

        expect(updated[0].moduleJson.attrs.dataKey).toEqual([nextBinding]);
        expect(updated[1].moduleJson.attrs.dataKey).toEqual([nextBinding]);
        expect(updated[2]).toBe(untouched);
        expect(first.moduleJson.attrs.dataKey).toEqual([{ key: 'device-1', type: 'A', src: '1' }]);
    });
});
