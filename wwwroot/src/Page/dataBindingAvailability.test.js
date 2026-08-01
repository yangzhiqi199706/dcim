import { validateDataBindingAvailability } from './dataBindingAvailability';

const dataPointShape = (binding) => ({
    id: 'data-text',
    moduleJson: {
        attrs: {
            dataKey: [binding],
            moduleAttr: [{
                attrGroupContent: [{
                    attrCode: 'dataKey',
                    attrType: 'hardwareInputNew',
                }],
            }],
        },
        children: [{ className: 'Text', attrs: {} }],
    },
});

describe('validateDataBindingAvailability', () => {
    test('reports a saved device data point that is no longer available', () => {
        const findings = validateDataBindingAvailability([
            dataPointShape({
                key: '1588',
                name: 'Missing metric',
                type: '3',
                cmdtype: '1',
                src: '1',
            }),
        ], [{
            id: '1588',
            LinkMode: '3',
            DeviceLastDataArr: [{
                cmdType: '1',
                data: "{'Existing metric':'1'}",
            }],
        }]);

        expect(findings).toEqual([
            expect.objectContaining({
                code: 'unavailable-data-binding',
                severity: 'warning',
                elementId: 'data-text',
            }),
        ]);
    });
});
