import { applyChartVisualStyle, buildBarAxisOption, getBarDataLabelPosition } from './SetChart';

describe('applyChartVisualStyle', () => {
    test('builds horizontal bar axes with value x-axis and category y-axis', () => {
        const axes = buildBarAxisOption({
            barDirection: 'horizontal',
            xshow: '2',
            xTextShow: '2',
            xaxisLine: '#111111',
            xColor: '#222222',
            yshow: '2',
            yaxisLine: '#333333',
            yColor: '#444444',
            markColor: '#555555',
            splitSwitch: '2',
            splitColor: '#666666'
        }, ['UPS1', 'UPS2', 'UPS3']);

        expect(axes.xAxis.type).toBe('value');
        expect(axes.xAxis.data).toBeUndefined();
        expect(axes.yAxis.type).toBe('category');
        expect(axes.yAxis.data).toEqual(['UPS1', 'UPS2', 'UPS3']);
        expect(axes.yAxis.axisLabel.show).toBe(true);
    });

    test('builds vertical bar axes with category x-axis and value y-axis by default', () => {
        const axes = buildBarAxisOption({
            xshow: '2',
            xTextShow: '2',
            xaxisLine: '#111111',
            xColor: '#222222',
            yshow: '2',
            yaxisLine: '#333333',
            yColor: '#444444',
            markColor: '#555555',
            splitSwitch: '2',
            splitColor: '#666666'
        }, ['Mon', 'Tue']);

        expect(axes.xAxis.type).toBe('category');
        expect(axes.xAxis.data).toEqual(['Mon', 'Tue']);
        expect(axes.yAxis.type).toBe('value');
        expect(axes.yAxis.data).toBeUndefined();
    });

    test('places horizontal bar data labels at the bar end', () => {
        expect(getBarDataLabelPosition({ barDirection: 'horizontal' })).toBe('right');
        expect(getBarDataLabelPosition({ barDirection: 'vertical' })).toBe('top');
        expect(getBarDataLabelPosition({})).toBe('top');
    });

    test('keeps original chart option unchanged when style is original', () => {
        const option = {
            grid: { top: 20 },
            xAxis: { axisLabel: { textStyle: { color: '#000000' } } },
            yAxis: { splitLine: { lineStyle: { color: '#cccccc' } } },
            series: [{ type: 'bar', data: [1, 2, 3] }]
        };

        const styled = applyChartVisualStyle(option, { cat: 'bar', chartStyle: 'original' });

        expect(styled).toEqual(option);
    });

    test('adds neon visual treatment to bar charts', () => {
        const styled = applyChartVisualStyle({
            series: [{ type: 'bar', data: [1, 2, 3] }]
        }, { cat: 'bar', chartStyle: 'neon' });

        expect(styled.backgroundColor).toBe('transparent');
        expect(styled.series[0].itemStyle.borderRadius).toEqual([8, 8, 2, 2]);
        expect(styled.series[0].itemStyle.shadowBlur).toBeGreaterThan(0);
        expect(styled.color.length).toBeGreaterThanOrEqual(3);
    });

    test('adds big-screen glow treatment to line charts', () => {
        const styled = applyChartVisualStyle({
            series: [{ type: 'line', data: [1, 2, 3] }]
        }, { cat: 'line', chartStyle: 'aurora' });

        expect(styled.series[0].smooth).toBe(true);
        expect(styled.series[0].showSymbol).toBe(false);
        expect(styled.series[0].areaStyle.opacity).toBeGreaterThan(0);
        expect(styled.series[0].lineStyle.shadowBlur).toBeGreaterThan(0);
    });

    test('adds dimensional treatment to pie charts', () => {
        const styled = applyChartVisualStyle({
            series: [{ type: 'pie', data: [{ name: 'A', value: 10 }] }]
        }, { cat: 'pie', chartStyle: 'amber' });

        expect(styled.series[0].itemStyle.borderWidth).toBeGreaterThan(0);
        expect(styled.series[0].itemStyle.shadowBlur).toBeGreaterThan(0);
        expect(styled.series[0].label.color).toBe('#fff7dc');
    });

    test('adds big-screen background and axis pointer for styled axis charts', () => {
        const styled = applyChartVisualStyle({
            tooltip: { trigger: 'axis' },
            series: [{ type: 'bar', data: [4, 8, 12] }]
        }, { cat: 'bar', chartStyle: 'neon' });

        expect(styled.tooltip.axisPointer.type).toBe('shadow');
        expect(styled.graphic.some(item => item.type === 'rect')).toBe(true);
        expect(styled.graphic.some(item => item.type === 'text')).toBe(false);
    });

    test('shows no-data visual hint without changing source series values', () => {
        const option = {
            series: [{ type: 'line', data: [0, null, undefined, ''] }]
        };

        const styled = applyChartVisualStyle(option, { cat: 'line', chartStyle: 'aurora' });

        expect(styled.series[0].data).toEqual([0, null, null, '']);
        expect(styled.graphic.some(item => item.type === 'text' && item.style.text === '暂无数据')).toBe(true);
    });

    test('adds sci-fi gauge treatment without changing gauge value', () => {
        const styled = applyChartVisualStyle({
            series: [{
                type: 'gauge',
                data: [{ value: 72, name: 'PUE' }],
                axisLine: { lineStyle: { color: [[1, '#999999']] } },
                detail: { color: '#000000' }
            }]
        }, { cat: 'gauge', chartStyle: 'neon' });

        expect(styled.series[0].data[0].value).toBe(72);
        expect(styled.series[0].progress.show).toBe(true);
        expect(styled.series[0].axisLine.lineStyle.width).toBeGreaterThan(10);
        expect(styled.series[0].detail.textShadowBlur).toBeGreaterThan(0);
        expect(styled.graphic.some(item => item.type === 'ring')).toBe(true);
    });

    test('adds ranking cues to sorted bar charts without forcing data labels', () => {
        const styled = applyChartVisualStyle({
            series: [{ type: 'bar', data: [90, 60, 30] }]
        }, { cat: 'bar', sortEnable: '2', chartStyle: 'amber' });

        expect(styled.series[0].label).toBeUndefined();
        expect(styled.series[0].barMinHeight).toBeGreaterThan(0);
    });

    test('keeps configured data labels visible when chart style is applied to sorted bars', () => {
        const styled = applyChartVisualStyle({
            series: [{
                type: 'bar',
                data: [90, 60, 30],
                label: {
                    show: true,
                    position: 'top',
                    color: '#ff0000',
                    fontSize: 18
                }
            }]
        }, { cat: 'bar', sortEnable: '2', chartStyle: 'amber' });

        expect(styled.series[0].label.show).toBe(true);
        expect(styled.series[0].label.position).toBe('top');
        expect(styled.series[0].label.color).toBe('#ff0000');
        expect(styled.series[0].label.fontSize).toBe(18);
    });

    test('uses themed label color when styled bar charts would otherwise keep default black labels', () => {
        const styled = applyChartVisualStyle({
            series: [{
                type: 'bar',
                data: [10, 20, 30],
                label: {
                    show: true,
                    position: 'top',
                    color: '#000000',
                    fontSize: 12
                }
            }]
        }, { cat: 'bar', chartStyle: 'neon' });

        expect(styled.series[0].label.show).toBe(true);
        expect(styled.series[0].label.position).toBe('top');
        expect(styled.series[0].label.color).toBe('#d8f7ff');
        expect(styled.series[0].label.textShadowColor).not.toBe('#000000');
        expect(styled.series[0].label.textShadowColor).toBeTruthy();
        expect(styled.series[0].label.textShadowBlur).toBeGreaterThan(0);
    });

    test('does not add data label carrier when labels are hidden for custom bar shapes', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['A', 'B', 'C'] },
            yAxis: { type: 'value' },
            series: [{ type: 'bar', data: [10, 20, 30] }]
        }, { cat: 'bar', chartStyle: 'neon', chartBarStyle: 'prism' });

        expect(styled.series[0].label).toBeUndefined();
        expect(styled.series.some(item => item.name === 'bar-data-labels')).toBe(false);
    });

    test('adds rounded bar body overlay without changing original data', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['A', 'B', 'C'] },
            yAxis: { type: 'value' },
            series: [{ type: 'bar', data: [10, 20, 30] }]
        }, { cat: 'bar', chartStyle: 'original', chartBarStyle: 'rounded' });

        const shapeSeries = styled.series.find(item => item.type === 'custom' && item.name === 'bar-rounded-shape');
        expect(styled.series[0].data).toEqual([10, 20, 30]);
        expect(shapeSeries).toBeTruthy();
        expect(shapeSeries.silent).toBe(true);
        expect(shapeSeries.data).toEqual([['A', 10, 0], ['B', 20, 1], ['C', 30, 2]]);
        expect(typeof shapeSeries.renderItem).toBe('function');
    });

    test('adds horizontal pyramid bar body overlay for sorted bar charts', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'value' },
            yAxis: { type: 'category', data: ['A', 'B', 'C'] },
            series: [{ type: 'bar', data: [90, 60, 30] }]
        }, { cat: 'bar', sortEnable: '2', chartStyle: 'neon', chartBarStyle: 'pyramid' });

        const shapeSeries = styled.series.find(item => item.type === 'custom' && item.name === 'bar-pyramid-shape');
        expect(styled.series[0].data).toEqual([90, 60, 30]);
        expect(shapeSeries).toBeTruthy();
        expect(shapeSeries.data).toEqual([[90, 'A', 0], [60, 'B', 1], [30, 'C', 2]]);
    });

    test('adds stereo group bar body with front side and top faces', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['A'] },
            yAxis: { type: 'value' },
            series: [{ type: 'bar', data: [10] }]
        }, { cat: 'bar', chartStyle: 'neon', chartBarStyle: 'stereoGroup' });

        const shapeSeries = styled.series.find(item => item.type === 'custom' && item.name === 'bar-stereoGroup-shape');
        const rendered = shapeSeries.renderItem({
            coordSys: { x: 0, y: 0, width: 200, height: 200 }
        }, {
            value: index => ['A', 10, 0][index],
            coord: ([, value]) => [60, 180 - value],
            size: () => [36, 20]
        });

        expect(shapeSeries).toBeTruthy();
        expect(rendered.type).toBe('group');
        expect(rendered.clipPath).toBeUndefined();
        expect(rendered.children.filter(child => child.type === 'polygon')).toHaveLength(3);
        expect(rendered.children.map(child => child.name)).toEqual(['stereo-front', 'stereo-side', 'stereo-top']);
    });

    test('hides original bar body when custom prism bar style is selected', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['A', 'B', 'C'] },
            yAxis: { type: 'value' },
            series: [{ type: 'bar', data: [10, 20, 30] }]
        }, { cat: 'bar', chartStyle: 'neon', chartBarStyle: 'prism' });

        expect(styled.series[0].data).toEqual([10, 20, 30]);
        expect(styled.series[0].itemStyle.opacity).toBe(0);
        expect(styled.series[0].itemStyle.color).toBe('rgba(0, 0, 0, 0)');
        expect(styled.series[0].itemStyle.shadowBlur).toBe(0);
        expect(styled.series[0].emphasis.itemStyle.opacity).toBe(0);
        expect(styled.series.some(item => item.type === 'custom' && item.name === 'bar-prism-shape')).toBe(true);
    });

    test('keeps configured data labels above custom bar shapes', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['A', 'B', 'C'] },
            yAxis: { type: 'value' },
            series: [{
                type: 'bar',
                data: [10, 20, 30],
                label: {
                    show: true,
                    position: 'top',
                    color: '#00ffcc',
                    fontSize: 16
                }
            }]
        }, { cat: 'bar', chartStyle: 'neon', chartBarStyle: 'prism' });

        const customSeriesZ = Math.max(...styled.series.filter(item => item.type === 'custom').map(item => item.z || 0));
        const labelCarrier = styled.series.find(item => item.name === 'bar-data-labels');
        expect(styled.series[0].label.show).toBe(true);
        expect(styled.series[0].label.position).toBe('top');
        expect(styled.series[0].label.color).toBe('#00ffcc');
        expect(styled.series[0].label.fontSize).toBe(16);
        expect(labelCarrier).toBeTruthy();
        expect(labelCarrier.label).toEqual(styled.series[0].label);
        expect(labelCarrier.itemStyle.color).toBe('rgba(0, 0, 0, 0)');
        expect(labelCarrier.itemStyle.opacity).toBeUndefined();
        expect(labelCarrier.z).toBeGreaterThan(customSeriesZ);
    });

    test('keeps configured data labels above custom bar motion layers', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['A', 'B', 'C'] },
            yAxis: { type: 'value' },
            series: [{
                type: 'bar',
                data: [10, 20, 30],
                label: {
                    show: true,
                    position: 'top',
                    color: '#f5ff00',
                    fontSize: 14
                }
            }]
        }, { cat: 'bar', chartStyle: 'original', chartAnimation: 'flow' });

        const customSeriesZ = Math.max(...styled.series.filter(item => item.type === 'custom').map(item => item.z || 0));
        const labelCarrier = styled.series.find(item => item.name === 'bar-data-labels');
        expect(styled.series[0].label.show).toBe(true);
        expect(styled.series[0].label.color).toBe('#f5ff00');
        expect(labelCarrier).toBeTruthy();
        expect(labelCarrier.label).toEqual(styled.series[0].label);
        expect(labelCarrier.z).toBeGreaterThan(customSeriesZ);
    });

    test('hides original bar background when custom prism style is selected', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['A', 'B', 'C'] },
            yAxis: { type: 'value' },
            series: [{
                type: 'bar',
                showBackground: true,
                backgroundStyle: {
                    color: 'rgba(255, 255, 255, 0.35)',
                    shadowBlur: 12
                },
                data: [
                    {
                        value: 10,
                        showBackground: true,
                        backgroundStyle: {
                            color: 'rgba(255, 255, 255, 0.35)',
                            shadowBlur: 12
                        }
                    },
                    20,
                    30
                ]
            }]
        }, { cat: 'bar', chartStyle: 'neon', chartBarStyle: 'prism' });

        expect(styled.series[0].showBackground).toBe(false);
        expect(styled.series[0].backgroundStyle.opacity).toBe(0);
        expect(styled.series[0].backgroundStyle.color).toBe('rgba(0, 0, 0, 0)');
        expect(styled.series[0].backgroundStyle.shadowBlur).toBe(0);
        expect(styled.series[0].data[0].value).toBe(10);
        expect(styled.series[0].data[0].showBackground).toBe(false);
        expect(styled.series[0].data[0].backgroundStyle.opacity).toBe(0);
        expect(styled.series[0].data[0].backgroundStyle.color).toBe('rgba(0, 0, 0, 0)');
    });

    test('does not add a rectangular clip path to custom prism bar bodies', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['A'] },
            yAxis: { type: 'value' },
            series: [{ type: 'bar', data: [10] }]
        }, { cat: 'bar', chartStyle: 'neon', chartBarStyle: 'prism' });

        const shapeSeries = styled.series.find(item => item.type === 'custom' && item.name === 'bar-prism-shape');
        const rendered = shapeSeries.renderItem({
            coordSys: { x: 0, y: 0, width: 200, height: 200 }
        }, {
            value: index => ['A', 10, 0][index],
            coord: ([, value]) => [60, 180 - value],
            size: () => [36, 20]
        });

        expect(rendered.type).toBe('group');
        expect(rendered.clipPath).toBeUndefined();
        expect(rendered.children.every(child => child.type === 'polygon')).toBe(true);
        expect(rendered.children[0].style.shadowBlur).toBe(0);
    });

    test('keeps original bar body hidden when custom prism style is combined with animation', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['A', 'B', 'C'] },
            yAxis: { type: 'value' },
            series: [{ type: 'bar', data: [10, 20, 30] }]
        }, { cat: 'bar', chartStyle: 'neon', chartBarStyle: 'prism', chartAnimation: 'pulse' });

        expect(styled.series[0].itemStyle.opacity).toBe(0);
        expect(styled.series[0].itemStyle.shadowBlur).toBe(0);
        expect(styled.series.some(item => item.type === 'custom' && item.name === 'bar-prism-shape')).toBe(true);
        expect(styled.series.some(item => item.type === 'custom' && item.name === 'bar-pulse-body')).toBe(true);
    });

    test('uses prism geometry for custom bar motion instead of rectangular motion bodies', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['A'] },
            yAxis: { type: 'value' },
            series: [{ type: 'bar', data: [10] }]
        }, { cat: 'bar', chartStyle: 'neon', chartBarStyle: 'prism', chartAnimation: 'pulse' });

        const motionSeries = styled.series.find(item => item.type === 'custom' && item.name === 'bar-pulse-body');
        const rendered = motionSeries.renderItem({
            coordSys: { x: 0, y: 0, width: 200, height: 200 }
        }, {
            value: index => ['A', 10, 0][index],
            coord: ([, value]) => [60, 180 - value],
            size: () => [36, 20]
        });

        expect(rendered.type).toBe('group');
        expect(rendered.clipPath).toBeUndefined();
        expect(rendered.children.every(child => child.type === 'polygon')).toBe(true);
        expect(rendered.children[0].keyframeAnimation.loop).toBe(true);
    });

    test('adds a sweep highlight for flow animation when a custom bar style is selected', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['A'] },
            yAxis: { type: 'value' },
            series: [{ type: 'bar', data: [10] }]
        }, { cat: 'bar', chartStyle: 'neon', chartBarStyle: 'prism', chartAnimation: 'flow' });

        const motionSeries = styled.series.find(item => item.type === 'custom' && item.name === 'bar-flow-body');
        const rendered = motionSeries.renderItem({
            coordSys: { x: 0, y: 0, width: 200, height: 200 }
        }, {
            value: index => ['A', 10, 0][index],
            coord: ([, value]) => [60, 180 - value],
            size: () => [36, 20]
        });

        const highlight = rendered.children.find(child => child.name === 'bar-flow-highlight');
        expect(highlight).toBeTruthy();
        expect(highlight.clipPath.type).toBe('rect');
        expect(highlight.clipPath.shape.height).toBeGreaterThan(0);
        expect(highlight.keyframeAnimation.keyframes[0].y).toBe(0);
        expect(highlight.keyframeAnimation.loop).toBe(true);
        expect(highlight.style.opacity).toBeGreaterThan(0.5);
    });

    test('keeps animations disabled when animation style is off', () => {
        const styled = applyChartVisualStyle({
            series: [{ type: 'line', data: [1, 2, 3] }]
        }, { cat: 'line', chartStyle: 'neon', chartAnimation: 'off' });

        expect(styled.animation).toBe(false);
        expect(styled.series[0].effect).toBeUndefined();
    });

    test('adds flowing line effect when animation style is flow', () => {
        const styled = applyChartVisualStyle({
            xAxis: { data: ['A', 'B', 'C'] },
            series: [{ type: 'line', data: [1, 2, 3] }]
        }, { cat: 'line', chartStyle: 'neon', chartAnimation: 'flow' });

        expect(styled.animation).toBe(true);
        expect(styled.series.some(item => item.type === 'lines' && item.effect.show)).toBe(true);
    });

    test('adds breathing pulse to gauge charts', () => {
        const styled = applyChartVisualStyle({
            series: [{ type: 'gauge', data: [{ value: 55 }] }]
        }, { cat: 'gauge', chartStyle: 'aurora', chartAnimation: 'pulse' });

        expect(styled.animation).toBe(true);
        expect(styled.series[0].detail.valueAnimation).toBe(true);
        expect(styled.series[0].progress.itemStyle.shadowBlur).toBeGreaterThan(20);
        expect(styled.graphic.some(item => item.keyframeAnimation && item.keyframeAnimation.loop)).toBe(true);
    });

    test('applies animation even when chart style remains original', () => {
        const styled = applyChartVisualStyle({
            series: [{ type: 'bar', data: [10, 20, 30] }]
        }, { cat: 'bar', chartStyle: 'original', chartAnimation: 'entrance' });

        expect(styled.animation).toBe(true);
        expect(styled.series[0].data).toEqual([10, 20, 30]);
        expect(styled.backgroundColor).toBeUndefined();
    });

    test('adds per-bar entrance overlay for bar charts', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['A', 'B', 'C'] },
            yAxis: { type: 'value' },
            series: [{ type: 'bar', data: [10, 20, 30] }]
        }, { cat: 'bar', chartStyle: 'original', chartAnimation: 'entrance' });

        const entranceSeries = styled.series.find(item => item.type === 'custom' && item.name === 'bar-entrance-body');
        expect(styled.series[0].data).toEqual([10, 20, 30]);
        expect(entranceSeries).toBeTruthy();
        expect(entranceSeries.silent).toBe(true);
        expect(entranceSeries.data).toEqual([['A', 10, 0], ['B', 20, 1], ['C', 30, 2]]);
        expect(typeof entranceSeries.renderItem).toBe('function');
    });

    test('adds visible looping motion layer for pulse animation on axis charts', () => {
        const styled = applyChartVisualStyle({
            series: [{ type: 'line', data: [10, 20, 30] }]
        }, { cat: 'line', chartStyle: 'original', chartAnimation: 'pulse' });

        expect(styled.animation).toBe(true);
        const pulseLayer = styled.graphic.find(item => item.name === 'chart-motion-pulse');
        expect(pulseLayer.keyframeAnimation.loop).toBe(true);
        expect(pulseLayer.z).toBeGreaterThan(20);
        expect(pulseLayer.style.fill).not.toBe('transparent');
    });

    test('adds per-bar breathing body overlay without changing original bar data', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['A', 'B', 'C'] },
            yAxis: { type: 'value' },
            series: [{ type: 'bar', data: [10, 20, 30] }]
        }, { cat: 'bar', chartStyle: 'original', chartAnimation: 'pulse' });

        const pulseSeries = styled.series.find(item => item.type === 'custom' && item.name === 'bar-pulse-body');
        expect(styled.series[0].data).toEqual([10, 20, 30]);
        expect(pulseSeries).toBeTruthy();
        expect(pulseSeries.silent).toBe(true);
        expect(pulseSeries.data).toEqual([['A', 10, 0], ['B', 20, 1], ['C', 30, 2]]);
        expect(typeof pulseSeries.renderItem).toBe('function');
    });

    test('adds visible looping sweep layer for flow animation on bar charts', () => {
        const styled = applyChartVisualStyle({
            series: [{ type: 'bar', data: [10, 20, 30] }]
        }, { cat: 'bar', chartStyle: 'original', chartAnimation: 'flow' });

        expect(styled.animation).toBe(true);
        expect(styled.series.some(item => item.type === 'custom' && item.name === 'bar-flow-body')).toBe(true);
        expect(styled.graphic.some(item => item.name === 'chart-motion-flow' && item.keyframeAnimation.loop)).toBe(true);
    });

    test('adds horizontal per-bar flow overlay for sorted bar charts', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'value' },
            yAxis: { type: 'category', data: ['A', 'B', 'C'] },
            series: [{ type: 'bar', data: [90, 60, 30] }]
        }, { cat: 'bar', sortEnable: '2', chartStyle: 'amber', chartAnimation: 'flow' });

        const flowSeries = styled.series.find(item => item.type === 'custom' && item.name === 'bar-flow-body');
        expect(styled.series[0].data).toEqual([90, 60, 30]);
        expect(flowSeries).toBeTruthy();
        expect(flowSeries.data).toEqual([[90, 'A', 0], [60, 'B', 1], [30, 'C', 2]]);
    });

    test('adds horizontal per-bar entrance overlay for sorted bar charts', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'value' },
            yAxis: { type: 'category', data: ['A', 'B', 'C'] },
            series: [{ type: 'bar', data: [90, 60, 30] }]
        }, { cat: 'bar', sortEnable: '2', chartStyle: 'neon', chartAnimation: 'entrance' });

        const entranceSeries = styled.series.find(item => item.type === 'custom' && item.name === 'bar-entrance-body');
        expect(styled.series[0].data).toEqual([90, 60, 30]);
        expect(entranceSeries).toBeTruthy();
        expect(entranceSeries.data).toEqual([[90, 'A', 0], [60, 'B', 1], [30, 'C', 2]]);
    });
});
