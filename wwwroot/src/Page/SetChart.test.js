import {
    applyChartVisualStyle,
    buildBarAxisOption,
    buildPieLegendOption,
    buildPieSeriesOption,
    buildWaterBallOption,
    getWaterBallRatio,
    getBarDataLabelPosition
} from './SetChart';
import * as echarts from 'echarts';

describe('applyChartVisualStyle', () => {
    test('calculates water ball percent from collected value and fixed value', () => {
        expect(getWaterBallRatio({ data: [{ value: 20 }], waterBallFixedValue: 100 })).toBe(0.2);
        expect(getWaterBallRatio({ data: [{ value: 150 }], waterBallFixedValue: 100 })).toBe(1);
        expect(getWaterBallRatio({ data: [{ value: -5 }], waterBallFixedValue: 100 })).toBe(0);
    });

    test('builds water ball option with configurable shape, colors, and label text', () => {
        const option = buildWaterBallOption({
            width: 200,
            height: 160,
            data: [{ value: 20 }],
            waterBallFixedValue: 100,
            waterBallShape: 'circle',
            waterBallBackgroundColor: '#1b3b46',
            waterBallWaveColor: '#65f0bc',
            waterBallWaveColor2: '#7cffb2',
            dataColor: '#ffffff',
            dataFontSize: 26,
            dataFontFamily: 'Arial',
            fontStyle: 'bold'
        });

        const clipShape = option.graphic.find(item => item.name === 'water-ball-clip');
        const background = option.graphic.find(item => item.name === 'water-ball-background');
        const waveGroup = option.graphic.find(item => item.name === 'water-ball-wave-group');
        const wave = waveGroup.children.find(item => item.name === 'water-ball-wave');
        const highlight = waveGroup.children.find(item => item.name === 'water-ball-wave-highlight');
        const label = option.graphic.find(item => item.name === 'water-ball-label');

        expect(option.series[0].name).toBe('water-ball-value');
        expect(option.series[0].data[0]).toBe(0.2);
        expect(clipShape.type).toBe('circle');
        expect(waveGroup.clipPath.name).toBe('water-ball-clip');
        expect(wave.z).toBeGreaterThan(background.z);
        expect(wave.style.fill).toBe('#65f0bc');
        expect(highlight.style.fill).toBe('#7cffb2');
        expect(label.style.text).toBe('20%');
        expect(label.style.fill).toBe('#ffffff');
        expect(label.style.fontSize).toBe(26);
    });

    test('keeps animated water waves clipped inside the fixed water ball shape', () => {
        const option = buildWaterBallOption({
            width: 200,
            height: 160,
            data: [{ value: 50 }],
            waterBallFixedValue: 100,
            waterBallShape: 'circle',
            chartAnimation: 'flow'
        });

        const waveGroup = option.graphic.find(item => item.name === 'water-ball-wave-group');
        const wave = waveGroup.children.find(item => item.name === 'water-ball-wave');
        const highlight = waveGroup.children.find(item => item.name === 'water-ball-wave-highlight');

        expect(waveGroup.clipPath.name).toBe('water-ball-clip');
        expect(waveGroup.keyframeAnimation).toBeUndefined();
        expect(wave.clipPath).toBeUndefined();
        expect(highlight.clipPath).toBeUndefined();
        expect(wave.keyframeAnimation.loop).toBe(true);
        expect(highlight.keyframeAnimation.loop).toBe(true);
        expect(Math.abs(wave.keyframeAnimation.keyframes[0].x)).toBeGreaterThanOrEqual(10);
        expect(Math.abs(highlight.keyframeAnimation.keyframes[0].x)).toBeGreaterThanOrEqual(10);
    });

    test('applies water ball visual presets from chart appearance', () => {
        const option = buildWaterBallOption({
            width: 200,
            height: 160,
            data: [{ value: 20 }],
            waterBallFixedValue: 100,
            chartStyle: 'neon',
            dataColor: '#000000'
        });

        const background = option.graphic.find(item => item.name === 'water-ball-background');
        const waveGroup = option.graphic.find(item => item.name === 'water-ball-wave-group');
        const wave = waveGroup.children.find(item => item.name === 'water-ball-wave');
        const label = option.graphic.find(item => item.name === 'water-ball-label');

        expect(background.style.fill.type).toBe('linear');
        expect(wave.style.fill).toBe('#20f7ff');
        expect(label.style.fill).toBe('#d8f7ff');
        expect(label.style.textShadowColor).toBe('#20f7ff');
    });

    test('adds distinct water ball motion layers for chart animation modes', () => {
        const entrance = buildWaterBallOption({ width: 200, height: 160, data: [{ value: 20 }], chartAnimation: 'entrance' });
        const pulse = buildWaterBallOption({ width: 200, height: 160, data: [{ value: 20 }], chartAnimation: 'pulse' });
        const flow = buildWaterBallOption({ width: 200, height: 160, data: [{ value: 20 }], chartAnimation: 'flow' });

        expect(entrance.graphic.some(item => item.name === 'water-ball-motion-entrance')).toBe(true);
        expect(pulse.graphic.some(item => item.name === 'water-ball-motion-pulse')).toBe(true);
        expect(flow.graphic.some(item => item.name === 'water-ball-motion-flow')).toBe(true);
        expect(flow.graphic.find(item => item.name === 'water-ball-motion-flow').keyframeAnimation.loop).toBe(true);
    });

    test('renders water ball graphics without requiring chart axes', () => {
        const originalGetContext = HTMLCanvasElement.prototype.getContext;
        HTMLCanvasElement.prototype.getContext = jest.fn(() => ({
            measureText: () => ({ width: 32 })
        }));
        const chart = echarts.init(null, null, { renderer: 'svg', ssr: true, width: 200, height: 160 });
        const option = buildWaterBallOption({
            width: 200,
            height: 160,
            data: [{ value: 20 }],
            waterBallFixedValue: 100,
            dataColor: '#ffffff',
            dataFontSize: 24
        });

        try {
            expect(() => chart.setOption(option)).not.toThrow();
            const svg = chart.renderToSVGString();
            expect(svg).toContain('fill="rgb(68,181,226)"');
            expect(svg).toContain('fill-opacity="0.285"');
            expect(svg).toContain('#4992FF');
            expect(svg).toContain('#7CFFB2');
            expect(svg).toContain('20%');
        } finally {
            chart.dispose();
            HTMLCanvasElement.prototype.getContext = originalGetContext;
        }
    });

    test('places pie legends below the chart when legend display is enabled', () => {
        const legend = buildPieLegendOption({
            iconSwitch: '2',
            iconColor: '#20f7ff',
            orient: 'vertical',
            algin: 'right'
        });

        expect(legend.show).toBe(true);
        expect(legend.left).toBe('center');
        expect(legend.bottom).toBe(0);
        expect(legend.orient).toBe('horizontal');
        expect(legend.right).toBeUndefined();
    });

    test('uses themed legend text when styled pie legends would otherwise stay black', () => {
        const styled = applyChartVisualStyle({
            legend: buildPieLegendOption({
                iconSwitch: '2',
                iconColor: '#000000'
            }),
            series: [buildPieSeriesOption({
                dataSwitch: '1',
                dataColor: '#000000',
                data: [{ name: 'A', value: 10 }]
            })]
        }, { cat: 'pie', chartStyle: 'neon' });

        expect(styled.legend.textStyle.color).toBe('#d8f7ff');
        expect(styled.legend.textStyle.textShadowColor).toBe('#20f7ff');
        expect(styled.legend.textStyle.textShadowBlur).toBeGreaterThan(0);
    });

    test('builds pie labels from data display switch', () => {
        const hidden = buildPieSeriesOption({
            dataSwitch: '1',
            dataColor: '#000000',
            dataFontSize: 12,
            data: [{ name: 'A', value: 10 }]
        });
        const visible = buildPieSeriesOption({
            dataSwitch: '2',
            dataColor: '#20f7ff',
            dataFontSize: 16,
            data: [{ name: 'A', value: 10 }]
        });

        expect(hidden.label.show).toBe(false);
        expect(visible.label.show).toBe(true);
        expect(visible.label.color).toBe('#20f7ff');
        expect(visible.label.fontSize).toBe(16);
    });

    test('uses transparent center diameter as pie inner radius without changing data', () => {
        const series = buildPieSeriesOption({
            centerBlankSwitch: '2',
            centerBlankDiameter: 90,
            dataSwitch: '2',
            dataColor: '#ffffff',
            dataFontSize: 14,
            data: [{ name: 'A', value: 10 }]
        });

        expect(series.radius).toEqual([45, '70%']);
        expect(series.data).toEqual([{ name: 'A', value: 10 }]);
    });

    test('moves pie upward and reduces outer radius when bottom legend is enabled', () => {
        const series = buildPieSeriesOption({
            iconSwitch: '2',
            dataSwitch: '1',
            dataColor: '#ffffff',
            data: [{ name: 'A', value: 10 }]
        });

        expect(series.center).toEqual(['50%', '42%']);
        expect(series.radius).toBe('58%');
    });

    test('keeps transparent center while reducing pie radius for bottom legend', () => {
        const series = buildPieSeriesOption({
            iconSwitch: '2',
            centerBlankSwitch: '2',
            centerBlankDiameter: 80,
            dataSwitch: '1',
            dataColor: '#ffffff',
            data: [{ name: 'A', value: 10 }]
        });

        expect(series.center).toEqual(['50%', '42%']);
        expect(series.radius).toEqual([40, '58%']);
    });

    test('uses transparent center diameter as rose pie inner radius', () => {
        const series = buildPieSeriesOption({
            roseSwitch: '2',
            centerBlankSwitch: '2',
            centerBlankDiameter: 80,
            dataSwitch: '1',
            dataColor: '#ffffff',
            data: [{ name: 'A', value: 10 }]
        });

        expect(series.radius).toEqual([40, 100]);
        expect(series.roseType).toBe('radius');
        expect(series.itemStyle.borderRadius).toBe(5);
    });

    test('aligns pie motion layers to the shifted pie center when bottom legend is enabled', () => {
        ['entrance', 'pulse', 'flow'].forEach((chartAnimation) => {
            const styled = applyChartVisualStyle({
                legend: buildPieLegendOption({
                    iconSwitch: '2',
                    iconColor: '#000000'
                }),
                series: [buildPieSeriesOption({
                    iconSwitch: '2',
                    dataSwitch: '1',
                    dataColor: '#000000',
                    width: 380,
                    height: 250,
                    data: [{ name: 'A', value: 10 }, { name: 'B', value: 20 }]
                })]
            }, { cat: 'pie', chartStyle: 'neon', chartAnimation, iconSwitch: '2', width: 380, height: 250 });

            const motionLayer = styled.graphic.find(item => item.name === `pie-motion-${chartAnimation === 'flow' ? 'flow' : chartAnimation}`);
            expect(styled.series[0].center).toEqual(['50%', '42%']);
            if (chartAnimation === 'flow') {
                const flowArc = motionLayer.children.find(item => item.name === 'pie-motion-flow-arc');
                expect(motionLayer.origin).toEqual([190, 105]);
                expect(flowArc.shape.cx).toBe(190);
                expect(flowArc.shape.cy).toBe(105);
                expect(flowArc.shape.r).toBe(73);
            } else {
                expect(motionLayer.origin).toEqual([190, 105]);
                expect(motionLayer.shape.cx).toBe(190);
                expect(motionLayer.shape.cy).toBe(105);
            }
        });
    });

    test('scales pie motion center offset with chart height when bottom legend is enabled', () => {
        const styled = applyChartVisualStyle({
            legend: buildPieLegendOption({
                iconSwitch: '2',
                iconColor: '#000000'
            }),
            series: [buildPieSeriesOption({
                iconSwitch: '2',
                width: 380,
                height: 400,
                dataSwitch: '1',
                dataColor: '#000000',
                data: [{ name: 'A', value: 10 }, { name: 'B', value: 20 }]
            })]
        }, { cat: 'pie', chartStyle: 'neon', chartAnimation: 'flow', iconSwitch: '2', width: 380, height: 400 });

        const motionLayer = styled.graphic.find(item => item.name === 'pie-motion-flow');
        const flowArc = motionLayer.children.find(item => item.name === 'pie-motion-flow-arc');
        expect(styled.series[0].center).toEqual(['50%', '42%']);
        expect(motionLayer.origin).toEqual([190, 168]);
        expect(flowArc.shape.cx).toBe(190);
        expect(flowArc.shape.cy).toBe(168);
        expect(flowArc.shape.r).toBe(110);
    });

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

    test('keeps line chart point symbols visible when data labels are enabled', () => {
        const styled = applyChartVisualStyle({
            series: [{
                type: 'line',
                data: [1, 2, 3],
                label: {
                    show: true,
                    position: 'top',
                    color: '#00ffcc',
                    fontSize: 14
                }
            }]
        }, { cat: 'line', chartStyle: 'neon', dataSwitch: '2' });

        expect(styled.series[0].label.show).toBe(true);
        expect(styled.series[0].showSymbol).toBe(true);
        expect(styled.series[0].symbolSize).toBeGreaterThanOrEqual(7);
    });

    test('uses themed label color when styled line charts would otherwise keep default black labels', () => {
        const styled = applyChartVisualStyle({
            series: [{
                type: 'line',
                data: [1, 2, 3],
                label: {
                    show: true,
                    position: 'top',
                    color: '#000000',
                    fontSize: 14
                }
            }]
        }, { cat: 'line', chartStyle: 'aurora', dataSwitch: '2' });

        expect(styled.series[0].label.show).toBe(true);
        expect(styled.series[0].label.color).toBe('#dffdf4');
        expect(styled.series[0].label.fontWeight).toBe(700);
        expect(styled.series[0].label.textShadowColor).toBe('#36f2b7');
        expect(styled.series[0].label.textShadowBlur).toBeGreaterThan(0);
    });

    test('adds dimensional treatment to pie charts', () => {
        const styled = applyChartVisualStyle({
            series: [{ type: 'pie', data: [{ name: 'A', value: 10 }] }]
        }, { cat: 'pie', chartStyle: 'amber' });

        expect(styled.series[0].itemStyle.borderWidth).toBeGreaterThan(0);
        expect(styled.series[0].itemStyle.borderColor).toBe('rgba(255, 209, 102, 0.46)');
        expect(styled.series[0].itemStyle.borderColor).not.toBe('rgba(5, 18, 36, 0.92)');
        expect(styled.series[0].itemStyle.shadowBlur).toBeGreaterThan(0);
        expect(styled.series[0].emphasis.itemStyle.borderColor).toBe('rgba(255, 209, 102, 0.68)');
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

    test('renders battery bars with shell, terminal and segmented charge blocks', () => {
        const styled = applyChartVisualStyle({
            xAxis: { type: 'category', data: ['UPS1'] },
            yAxis: { type: 'value' },
            series: [{ type: 'bar', data: [80] }]
        }, { cat: 'bar', chartStyle: 'neon', chartBarStyle: 'battery' });

        const shapeSeries = styled.series.find(item => item.type === 'custom' && item.name === 'bar-battery-shape');
        const rendered = shapeSeries.renderItem({
            coordSys: { x: 0, y: 0, width: 200, height: 220 }
        }, {
            value: index => ['UPS1', 80, 0][index],
            coord: ([, value]) => [80, 200 - value],
            size: () => [42, 20]
        });

        expect(shapeSeries).toBeTruthy();
        expect(rendered.type).toBe('group');
        expect(rendered.clipPath).toBeUndefined();
        expect(rendered.children.some(child => child.name === 'battery-shell')).toBe(true);
        expect(rendered.children.some(child => child.name === 'battery-terminal')).toBe(true);
        expect(rendered.children.some(child => child.name === 'battery-chamber')).toBe(true);
        expect(rendered.children.filter(child => child.name === 'battery-segment')).toHaveLength(4);
        expect(rendered.children.some(child => child.name === 'battery-gloss')).toBe(true);
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

    test('keeps line flow effect valid for 7-day and 30-day history data placeholders', () => {
        const styled = applyChartVisualStyle({
            xAxis: { data: ['2026-05-29', '2026-05-30', '2026-05-31', '2026-06-01'] },
            series: [{
                type: 'line',
                data: [[], '12.50', 'NaN', { value: '18.25' }]
            }]
        }, { cat: 'line', chartStyle: 'neon', chartAnimation: 'flow', dataType: 'day' });

        const flowSeries = styled.series.find(item => item.type === 'lines' && item.name === 'line-flow');
        expect(flowSeries).toBeTruthy();
        expect(flowSeries.effect.show).toBe(true);
        expect(flowSeries.data[0].coords).toEqual([
            ['2026-05-30', 12.5],
            ['2026-06-01', 18.25]
        ]);
    });

    test('adds visible line drawing layer when line animation style is entrance', () => {
        const styled = applyChartVisualStyle({
            xAxis: { data: ['A', 'B', 'C'] },
            yAxis: { type: 'value' },
            series: [{ type: 'line', data: [1, 2, 3] }]
        }, { cat: 'line', chartStyle: 'original', chartAnimation: 'entrance' });

        const entranceSeries = styled.series.find(item => item.type === 'custom' && item.name === 'line-entrance-motion');
        const rendered = entranceSeries.renderItem({
            coordSys: { x: 0, y: 0, width: 200, height: 120 }
        }, {
            coord: ([category, value]) => {
                const xMap = { A: 20, B: 100, C: 180 };
                return [xMap[category], 120 - value * 20];
            }
        });

        expect(styled.animation).toBe(true);
        expect(entranceSeries).toBeTruthy();
        expect(entranceSeries.silent).toBe(true);
        expect(rendered.type).toBe('group');
        expect(rendered.children.some(child => child.name === 'line-entrance-stroke')).toBe(true);
        expect(rendered.children.some(child => child.name === 'line-entrance-head')).toBe(true);
        expect(rendered.clipPath.keyframeAnimation.loop).toBe(false);
    });

    test('adds visible looping line glow layer when line animation style is pulse', () => {
        const styled = applyChartVisualStyle({
            xAxis: { data: ['A', 'B', 'C'] },
            yAxis: { type: 'value' },
            series: [{ type: 'line', data: [1, 2, 3] }]
        }, { cat: 'line', chartStyle: 'original', chartAnimation: 'pulse' });

        const pulseSeries = styled.series.find(item => item.type === 'custom' && item.name === 'line-pulse-motion');
        const rendered = pulseSeries.renderItem({
            coordSys: { x: 0, y: 0, width: 200, height: 120 }
        }, {
            coord: ([category, value]) => {
                const xMap = { A: 20, B: 100, C: 180 };
                return [xMap[category], 120 - value * 20];
            }
        });

        const glowLine = rendered.children.find(child => child.name === 'line-pulse-glow');
        expect(styled.animation).toBe(true);
        expect(pulseSeries).toBeTruthy();
        expect(glowLine).toBeTruthy();
        expect(glowLine.keyframeAnimation.loop).toBe(true);
        expect(glowLine.style.shadowBlur).toBeGreaterThan(10);
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

    test('adds visible entrance animation for pie charts', () => {
        const styled = applyChartVisualStyle({
            series: [{ type: 'pie', data: [{ name: 'A', value: 10 }, { name: 'B', value: 20 }] }]
        }, { cat: 'pie', chartStyle: 'neon', chartAnimation: 'entrance' });

        expect(styled.animation).toBe(true);
        expect(styled.series[0].animationType).toBe('scale');
        expect(styled.series[0].animationEasing).toBe('elasticOut');
        expect(styled.graphic.some(item => item.name === 'pie-motion-entrance' && item.keyframeAnimation.loop === false)).toBe(true);
    });

    test('adds visible breathing glow for pie charts', () => {
        const styled = applyChartVisualStyle({
            series: [{ type: 'pie', data: [{ name: 'A', value: 10 }, { name: 'B', value: 20 }] }]
        }, { cat: 'pie', chartStyle: 'aurora', chartAnimation: 'pulse' });

        expect(styled.animation).toBe(true);
        expect(styled.series[0].animationType).toBe('scale');
        expect(styled.series[0].animationEasing).toBe('elasticOut');
        expect(styled.graphic.some(item => item.name === 'pie-motion-pulse' && item.keyframeAnimation.loop)).toBe(true);
    });

    test('adds visible circular flow sweep for pie charts', () => {
        const styled = applyChartVisualStyle({
            series: [{ type: 'pie', data: [{ name: 'A', value: 10 }, { name: 'B', value: 20 }] }]
        }, { cat: 'pie', chartStyle: 'amber', chartAnimation: 'flow' });

        expect(styled.animation).toBe(true);
        expect(styled.series[0].animationType).toBe('expansion');
        const flowLayer = styled.graphic.find(item => item.name === 'pie-motion-flow');
        const flowArc = flowLayer.children.find(item => item.name === 'pie-motion-flow-arc');
        const flowBounds = flowLayer.children.find(item => item.name === 'pie-motion-flow-bounds');
        expect(flowLayer.type).toBe('group');
        expect(flowLayer.origin).toEqual([190, 125]);
        expect(flowLayer.keyframeAnimation.loop).toBe(true);
        expect(flowBounds).toBeTruthy();
        expect(flowBounds.shape.cx).toBe(flowArc.shape.cx);
        expect(flowBounds.shape.cy).toBe(flowArc.shape.cy);
        expect(flowBounds.shape.r).toBe(flowArc.shape.r);
        expect(flowBounds.style.opacity).toBe(0);
        expect(flowArc.shape.cx).toBe(190);
        expect(flowArc.shape.cy).toBe(125);
        expect(flowArc.shape.r).toBeLessThan(100);
        expect(styled.graphic.some(item => item.name === 'chart-motion-flow')).toBe(false);
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
