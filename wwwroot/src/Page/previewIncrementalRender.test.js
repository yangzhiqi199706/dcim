import {
    PREVIEW_REALTIME_INTERVAL_MS,
    createPreparedPreviewModel,
    reconcilePreviewElements,
    selectPreviewSources
} from './previewIncrementalRender';

const createShape = ({ id, bindingType, dataKey = [{}], className = 'Text', cat }) => ({
    id,
    moduleJson: {
        attrs: {
            dataKey,
            moduleAttr: [{
                attrGroupContent: [{ attrCode: bindingType }]
            }]
        },
        children: [{ className, attrs: { cat } }]
    }
});

describe('createPreparedPreviewModel', () => {
    test('classifies each preview source once by its refresh dependencies', () => {
        const model = createPreparedPreviewModel([
            createShape({ id: 'live', bindingType: 'dataKey' }),
            createShape({ id: 'parameter', bindingType: 'dataKey', dataKey: [{ parkey: 'p-1' }] }),
            createShape({ id: 'history', bindingType: 'dataParamsKey', className: 'Echart', cat: 'line' }),
            createShape({ id: 'alarm-list', bindingType: null, className: 'alarmList' })
        ]);

        expect(PREVIEW_REALTIME_INTERVAL_MS).toBe(5000);
        expect(selectPreviewSources(model, ['realtime']).map(item => item.id)).toEqual(['live']);
        expect(selectPreviewSources(model, ['param']).map(item => item.id)).toEqual(['parameter']);
        expect(selectPreviewSources(model, ['history']).map(item => item.id)).toEqual(['history']);
        expect(selectPreviewSources(model, ['alarm']).map(item => item.id)).toEqual(['live', 'alarm-list']);
    });

    test('uses the full prepared source set for the initial batch and unknown bindings', () => {
        const known = createShape({ id: 'known', bindingType: 'dataKey' });
        const unknown = createShape({ id: 'unknown', bindingType: 'futureBinding' });
        const model = createPreparedPreviewModel([known, unknown]);

        expect(selectPreviewSources(model, ['initial']).map(item => item.id)).toEqual(['known', 'unknown']);
        expect(selectPreviewSources(model, ['realtime']).map(item => item.id)).toEqual(['known', 'unknown']);
    });
});

describe('reconcilePreviewElements', () => {
    test('preserves unchanged element references and reports only changed chart ids', () => {
        const model = createPreparedPreviewModel([
            createShape({ id: 'label', bindingType: 'dataKey' }),
            createShape({ id: 'chart', bindingType: 'dataKey', className: 'Echart', cat: 'gauge' })
        ]);
        const previousLabel = { ...model.sources[0], renderedValue: 'same' };
        const previousChart = { ...model.sources[1], renderedValue: 10 };
        const candidateLabel = JSON.parse(JSON.stringify(previousLabel));
        const candidateChart = { ...previousChart, renderedValue: 20 };

        const result = reconcilePreviewElements(model, [previousLabel, previousChart], [candidateLabel, candidateChart]);

        expect(result.elements[0]).toBe(previousLabel);
        expect(result.elements[1]).toBe(candidateChart);
        expect(result.changedIds).toEqual(['chart']);
        expect(result.changedChartIds).toEqual(['chart']);
    });
});
