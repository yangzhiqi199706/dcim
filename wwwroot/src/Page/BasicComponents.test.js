import BasicComponents from './Data/BasicComponents.json';

describe('BasicComponents chart templates', () => {
    test('includes horizontal bar chart with the same bar data interface', () => {
        const horizontalBar = BasicComponents.find(item => item.moduleName === '横向柱状图');
        const chartAttrs = horizontalBar.moduleJson.children[0].attrs;

        expect(horizontalBar).toBeTruthy();
        expect(chartAttrs.cat).toBe('bar');
        expect(chartAttrs.barDirection).toBe('horizontal');
        expect(chartAttrs.xdata).toEqual(['UPS1', 'UPS2', 'UPS3']);
        expect(chartAttrs.data).toEqual([{
            name: 'UPS负载',
            data: [120, 80, 50],
            type: 'bar'
        }]);
    });

    test('includes pie data label and transparent center controls', () => {
        const pie = BasicComponents.find(item => item.moduleJson.children[0].attrs.cat === 'pie');
        const chartAttrs = pie.moduleJson.children[0].attrs;
        const chartControls = pie.moduleJson.attrs.moduleAttr
            .flatMap(group => group.attrGroupContent)
            .map(item => item.attrCode);

        expect(chartAttrs.dataSwitch).toBe('1');
        expect(chartAttrs.centerBlankSwitch).toBe('1');
        expect(chartAttrs.centerBlankDiameter).toBe(80);
        expect(chartControls).toContain('dataSwitch');
        expect(chartControls).toContain('centerBlankSwitch');
        expect(chartControls).toContain('centerBlankDiameter');
    });

    test('includes water ball chart with unchanged data binding interface', () => {
        const waterBall = BasicComponents.find(item => item.moduleJson.children[0].attrs.cat === 'waterBall');
        const chartAttrs = waterBall.moduleJson.children[0].attrs;
        const chartControls = waterBall.moduleJson.attrs.moduleAttr
            .flatMap(group => group.attrGroupContent)
            .map(item => item.attrCode);

        expect(waterBall.moduleName).toBe('水球');
        expect(chartAttrs.name).toBe('Echart');
        expect(chartAttrs.chartStyle).toBe('original');
        expect(chartAttrs.chartAnimation).toBe('off');
        expect(chartAttrs.waterBallFixedValue).toBe(100);
        expect(chartAttrs.waterBallShape).toBe('circle');
        expect(chartAttrs.waterBallBackgroundColor).toBe('rgba(68, 181, 226, 0.3)');
        expect(chartAttrs.waterBallWaveColor).toBe('#4992FF');
        expect(chartAttrs.waterBallWaveColor2).toBe('#7CFFB2');
        expect(chartControls).toContain('dataParamsKey');
        expect(chartControls).toContain('chartStyle');
        expect(chartControls).toContain('chartAnimation');
        expect(chartControls).toContain('waterBallFixedValue');
        expect(chartControls).toContain('waterBallShape');
        expect(chartControls).toContain('waterBallBackgroundColor');
        expect(chartControls).toContain('waterBallWaveColor');
        expect(chartControls).toContain('waterBallWaveColor2');
        expect(chartControls).toContain('dataFontSize');
        expect(chartControls).toContain('dataColor');
    });
});
