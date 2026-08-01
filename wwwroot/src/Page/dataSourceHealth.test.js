import { getDataSourceHealthReport } from './dataSourceHealth';

const dataPointShape = (id, binding, attrCode = 'dataKey') => ({
    id,
    moduleJson: {
        attrs: {
            [attrCode]: binding === undefined ? [] : [binding],
            moduleAttr: [{
                attrGroupContent: [{
                    attrCode,
                    attrType: 'hardwareInputNew',
                }],
            }],
        },
        children: [{ className: 'Text', attrs: { title: `${id} title` } }],
    },
});

const device = (id, data, cmdType = '1') => ({
    id,
    DeviceLastDataArr: [{ cmdType, data }],
});

describe('data source health report', () => {
    test('classifies present and absent device metrics from the current snapshot', () => {
        const report = getDataSourceHealthReport([
            dataPointShape('load', {
                key: '42', name: 'Load', type: '3', cmdtype: '1', src: '1',
            }),
            dataPointShape('pressure', {
                key: '42', name: 'Pressure', type: '3', cmdtype: '1', src: '1',
            }),
        ], [device('42', "{'Load':'12'}")]);

        expect(report.items.map((item) => item.status)).toEqual(['available', 'unavailable']);
        expect(report.items[0]).toMatchObject({
            elementId: 'load',
            label: 'Load',
            bindingSummary: 'Load',
        });
        expect(report.counts).toEqual({
            available: 1,
            missing: 0,
            invalid: 0,
            unavailable: 1,
            unknown: 0,
        });
    });

    test('distinguishes missing and structurally invalid declared data point bindings', () => {
        const report = getDataSourceHealthReport([
            dataPointShape('missing', undefined),
            dataPointShape('invalid', {
                key: '42', name: '', type: '3', cmdtype: '1', src: '1',
            }),
        ], [device('42', "{'Load':'12'}")]);

        expect(report.items.map((item) => item.status)).toEqual(['missing', 'invalid']);
        expect(report.counts).toMatchObject({ missing: 1, invalid: 1 });
    });

    test('keeps remote data sources unknown instead of marking them unavailable locally', () => {
        const report = getDataSourceHealthReport([
            dataPointShape('remote-load', {
                key: 'remote-device', name: 'Load', type: '3', cmdtype: '1', src: 'remote@site',
            }),
        ], []);

        expect(report.items).toEqual([
            expect.objectContaining({
                elementId: 'remote-load',
                status: 'unknown',
            }),
        ]);
        expect(report.counts).toMatchObject({ unknown: 1, unavailable: 0 });
    });

    test('keeps local data sources unknown when no device snapshot is available', () => {
        const report = getDataSourceHealthReport([
            dataPointShape('load', {
                key: '42', name: 'Load', type: '3', cmdtype: '1', src: '1',
            }),
        ]);

        expect(report.items).toEqual([
            expect.objectContaining({
                elementId: 'load',
                status: 'unknown',
            }),
        ]);
        expect(report.counts).toMatchObject({ unknown: 1, unavailable: 0 });
    });

    test('reports device-only bindings as available when their device is present', () => {
        const report = getDataSourceHealthReport([
            dataPointShape('device-status', {
                key: '42', type: '3', src: '1',
            }, 'dataDevKey'),
        ], [device('42', "{'Load':'12'}")]);

        expect(report.items).toEqual([
            expect.objectContaining({
                elementId: 'device-status',
                status: 'available',
            }),
        ]);
    });

    test('omits elements that do not declare a device data point', () => {
        const report = getDataSourceHealthReport([{
            id: 'static-title',
            moduleJson: {
                attrs: { dataKey: [{ key: '42', name: 'Load' }] },
                children: [{ className: 'Text', attrs: {} }],
            },
        }], [device('42', "{'Load':'12'}")]);

        expect(report).toEqual({
            items: [],
            counts: {
                available: 0,
                missing: 0,
                invalid: 0,
                unavailable: 0,
                unknown: 0,
            },
        });
    });
});
