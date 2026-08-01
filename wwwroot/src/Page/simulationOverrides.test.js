import {
    applySimulationOverrides,
    getSimulatableElements,
    getSimulationElementLabel,
} from './simulationOverrides';

const elements = [
    {
        id: 'load',
        moduleJson: {
            attrs: { dataKey: [{ key: '42', name: 'Load' }] },
            children: [{ className: 'Text', attrs: { text: '--' } }],
        },
    },
    {
        id: 'trend',
        moduleJson: {
            attrs: { dataKey: [{ key: '43', name: 'Trend' }] },
            children: [{ className: 'Echart', attrs: { data: [1, 2] } }],
        },
    },
    {
        id: 'static-title',
        moduleJson: {
            attrs: {},
            children: [{ className: 'Text', attrs: { text: 'Static' } }],
        },
    },
];

describe('simulation overrides', () => {
    test('lists only elements that have configured data bindings', () => {
        const targets = getSimulatableElements(elements);

        expect(targets.map((element) => element.id)).toEqual(['load', 'trend']);
        expect(getSimulationElementLabel(targets[0], 0)).toBe('Load');
    });

    test('derives text and chart values without mutating persisted elements', () => {
        const result = applySimulationOverrides(elements, {
            load: '42.5',
            trend: '[10, 20, 30]',
        });

        expect(result[0].moduleJson.children[0].attrs.text).toBe('42.5');
        expect(result[1].moduleJson.children[0].attrs.data).toEqual([10, 20, 30]);
        expect(result[2]).toBe(elements[2]);
        expect(elements[0].moduleJson.children[0].attrs.text).toBe('--');
        expect(elements[1].moduleJson.children[0].attrs.data).toEqual([1, 2]);
    });

    test('keeps invalid json as a text value and parses numeric chart values', () => {
        const result = applySimulationOverrides(elements, {
            load: '[not-json',
            trend: '18.2',
        });

        expect(result[0].moduleJson.children[0].attrs.text).toBe('[not-json');
        expect(result[1].moduleJson.children[0].attrs.data).toBe(18.2);
    });

    test('keeps chart option schemas while simulating water ball and axis chart values', () => {
        const chartElements = [
            {
                id: 'water-ball',
                moduleJson: {
                    attrs: { dataKey: [{ key: '44', name: 'Water level' }] },
                    children: [{
                        className: 'Echart',
                        attrs: {
                            cat: 'waterBall',
                            data: [{ value: 50, name: 'Water level' }],
                        },
                    }],
                },
            },
            {
                id: 'trend-line',
                moduleJson: {
                    attrs: { dataKey: [{ key: '45', name: 'Temperature' }] },
                    children: [{
                        className: 'Echart',
                        attrs: {
                            cat: 'line',
                            xdata: ['Mon', 'Tue', 'Wed'],
                            data: [{ name: 'Temperature', type: 'line', data: [10, 20, 30] }],
                        },
                    }],
                },
            },
            {
                id: 'load-bar',
                moduleJson: {
                    attrs: { dataKey: [{ key: '46', name: 'Load' }] },
                    children: [{
                        className: 'Echart',
                        attrs: {
                            cat: 'bar',
                            xdata: ['A', 'B'],
                            data: [{ name: 'Load', type: 'bar', data: [10, 20] }],
                        },
                    }],
                },
            },
        ];

        const result = applySimulationOverrides(chartElements, {
            'water-ball': '75',
            'trend-line': '2',
            'load-bar': '[3, 4]',
        });

        expect(result[0].moduleJson.children[0].attrs.data).toEqual([
            { value: 75, name: 'Water level' },
        ]);
        expect(result[1].moduleJson.children[0].attrs.data).toEqual([
            { name: 'Temperature', type: 'line', data: [2, 2, 2] },
        ]);
        expect(result[2].moduleJson.children[0].attrs.data).toEqual([
            { name: 'Load', type: 'bar', data: [3, 4] },
        ]);
    });
});
