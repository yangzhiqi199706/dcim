import { ensureChartAttributeControls } from './chartAttributeControls';

describe('ensureChartAttributeControls', () => {
    test('adds chart style and animation controls for old Echart module attributes', () => {
        const moduleAttr = [
            {
                attrGroupName: 'Style',
                attrGroupContent: [
                    {
                        attrName: 'Title',
                        attrCode: 'titleSwitch',
                        attrType: 'showSelect',
                        attrWhere: 'Echart'
                    }
                ]
            }
        ];

        const next = ensureChartAttributeControls(moduleAttr, true);
        const controls = next[0].attrGroupContent;

        expect(controls.some(item => item.attrCode === 'chartStyle')).toBe(true);
        expect(controls.some(item => item.attrCode === 'chartAnimation')).toBe(true);
        expect(controls.find(item => item.attrCode === 'chartAnimation').attrType).toBe('chartAnimationSelect');
    });

    test('does not duplicate chart controls', () => {
        const moduleAttr = [
            {
                attrGroupName: 'Style',
                attrGroupContent: [
                    {
                        attrName: 'Chart appearance',
                        attrCode: 'chartStyle',
                        attrType: 'chartStyleSelect',
                        attrWhere: 'Echart'
                    },
                    {
                        attrName: 'Chart animation',
                        attrCode: 'chartAnimation',
                        attrType: 'chartAnimationSelect',
                        attrWhere: 'Echart'
                    }
                ]
            }
        ];

        const next = ensureChartAttributeControls(moduleAttr, true);
        const controls = next[0].attrGroupContent;

        expect(controls.filter(item => item.attrCode === 'chartStyle')).toHaveLength(1);
        expect(controls.filter(item => item.attrCode === 'chartAnimation')).toHaveLength(1);
    });

    test('adds chart bar style control only for bar charts after animation control', () => {
        const moduleAttr = [
            {
                attrGroupName: 'Style',
                attrGroupContent: [
                    {
                        attrName: 'Chart appearance',
                        attrCode: 'chartStyle',
                        attrType: 'chartStyleSelect',
                        attrWhere: 'Echart'
                    },
                    {
                        attrName: 'Chart animation',
                        attrCode: 'chartAnimation',
                        attrType: 'chartAnimationSelect',
                        attrWhere: 'Echart'
                    }
                ]
            }
        ];

        const next = ensureChartAttributeControls(moduleAttr, true, 'bar');
        const controls = next[0].attrGroupContent;
        const animationIndex = controls.findIndex(item => item.attrCode === 'chartAnimation');
        const barStyleIndex = controls.findIndex(item => item.attrCode === 'chartBarStyle');

        expect(controls.filter(item => item.attrCode === 'chartBarStyle')).toHaveLength(1);
        expect(controls[barStyleIndex].attrType).toBe('chartBarStyleSelect');
        expect(barStyleIndex).toBe(animationIndex + 1);
    });

    test('does not add chart bar style control for non-bar charts', () => {
        const moduleAttr = [
            {
                attrGroupName: 'Style',
                attrGroupContent: [
                    {
                        attrName: 'Chart animation',
                        attrCode: 'chartAnimation',
                        attrType: 'chartAnimationSelect',
                        attrWhere: 'Echart'
                    }
                ]
            }
        ];

        const next = ensureChartAttributeControls(moduleAttr, true, 'line');

        expect(next[0].attrGroupContent.some(item => item.attrCode === 'chartBarStyle')).toBe(false);
    });

    test('does not add chart controls to non-chart module attributes', () => {
        const moduleAttr = [
            {
                attrGroupName: 'Style',
                attrGroupContent: [
                    {
                        attrName: 'Width',
                        attrCode: 'width',
                        attrType: 'number',
                        attrWhere: 'Text'
                    }
                ]
            }
        ];

        const next = ensureChartAttributeControls(moduleAttr, false);

        expect(next[0].attrGroupContent.some(item => item.attrCode === 'chartAnimation')).toBe(false);
    });
});
