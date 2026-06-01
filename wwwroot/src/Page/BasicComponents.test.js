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
});
