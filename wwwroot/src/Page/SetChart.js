import * as echarts from "echarts";
import { t } from '../i18n';
// Comment translated to English.
// import * as echarts from 'echarts/lib/echarts';
// import { LineChart, BarChart, PieChart } from 'echarts/charts';
// import { GridComponent, LegendComponent, TooltipComponent, TitleComponent } from 'echarts/components';
// import httpsend from '../Assets/httpsend';
// echarts.use([LineChart, BarChart, PieChart, GridComponent, LegendComponent, TooltipComponent, TitleComponent]);

const isFirefox = typeof navigator !== 'undefined' && /firefox/i.test(navigator.userAgent || '');

const initChart = (element) => {
    if (!element) return null;
    const existChart = echarts.getInstanceByDom(element);
    if (existChart) {
        try {
            const painterType = existChart.getZr() && existChart.getZr().painter && existChart.getZr().painter.getType
                ? existChart.getZr().painter.getType()
                : '';
            const wantType = isFirefox ? 'svg' : 'canvas';
            if (painterType === wantType || !painterType) {
                return existChart;
            }
        } catch (e) {
            // fall through to recreate
        }
        existChart.dispose();
    }
    return echarts.init(element, null, { renderer: isFirefox ? 'svg' : 'canvas' });
};

const normalizeOptionForStableRender = (option) => {
    if (!option || typeof option !== 'object') return option;
    if (option.animation !== true) {
        option.animation = false;
        option.animationDuration = 0;
        option.animationDurationUpdate = 0;
        option.animationEasing = 'linear';
        option.animationEasingUpdate = 'linear';
        option.stateAnimation = { duration: 0 };
    }
    return option;
};

const safeSetOption = (chart, option) => {
    if (!chart || !option) return;
    try {
        const stableOption = normalizeOptionForStableRender(option);
        chart.setOption(stableOption, {
            notMerge: true,
            lazyUpdate: true,
            silent: true
        });
    } catch (e) {
        console.error('echarts setOption failed', e);
    }
};

const buildAxisLine = (show, color) => ({
    show: show === '2',
    lineStyle: { color }
});

const buildAxisTick = (color) => ({
    lineStyle: { color }
});

const buildAxisLabel = (color, show) => ({
    ...(show === undefined ? {} : { show }),
    textStyle: { color }
});

const buildValueAxis = (chartInfo, axisName) => ({
    axisLine: buildAxisLine(chartInfo[`${axisName}show`], chartInfo[`${axisName}axisLine`]),
    axisTick: buildAxisTick(chartInfo.markColor),
    axisLabel: buildAxisLabel(chartInfo[`${axisName}Color`], axisName === 'x' ? chartInfo.xTextShow === '2' : undefined),
    splitLine: {
        show: chartInfo.splitSwitch === '2',
        lineStyle: {
            color: chartInfo.splitColor
        }
    },
    type: 'value'
});

const buildCategoryAxis = (chartInfo, axisName, categories) => ({
    axisLine: buildAxisLine(chartInfo[`${axisName}show`], chartInfo[`${axisName}axisLine`]),
    axisTick: buildAxisTick(chartInfo.markColor),
    axisLabel: buildAxisLabel(chartInfo[`${axisName}Color`], axisName === 'x' ? chartInfo.xTextShow === '2' : true),
    type: 'category',
    data: categories
});

export const buildBarAxisOption = (chartInfo, categories) => {
    if (chartInfo.barDirection === 'horizontal') {
        return {
            xAxis: buildValueAxis(chartInfo, 'x'),
            yAxis: buildCategoryAxis(chartInfo, 'y', categories)
        };
    }
    return {
        xAxis: buildCategoryAxis(chartInfo, 'x', categories),
        yAxis: buildValueAxis(chartInfo, 'y')
    };
};

export const getBarDataLabelPosition = (chartInfo = {}) => (
    chartInfo.barDirection === 'horizontal' ? 'right' : 'top'
);

const getPositiveNumber = (value, fallback) => {
    const numberValue = Number(value);
    return Number.isFinite(numberValue) && numberValue > 0 ? numberValue : fallback;
};

const pieBottomLegendCenter = ['50%', '42%'];

const getPieCenterBlankInnerRadius = (chartInfo = {}) => {
    if (chartInfo.centerBlankSwitch !== '2') return null;
    return getPositiveNumber(chartInfo.centerBlankDiameter, 80) / 2;
};

export const buildPieSeriesOption = (chartInfo = {}) => {
    const innerRadius = getPieCenterBlankInnerRadius(chartInfo);
    const isRose = chartInfo.roseSwitch === '2';
    const hasBottomLegend = chartInfo.iconSwitch === '2';
    const pieOuterRadius = hasBottomLegend ? '58%' : '70%';
    const series = {
        type: 'pie',
        radius: innerRadius === null ? pieOuterRadius : [innerRadius, pieOuterRadius],
        ...(hasBottomLegend ? { center: pieBottomLegendCenter } : {}),
        data: chartInfo.data,
        label: {
            show: chartInfo.dataSwitch === '2',
            position: 'inside',
            color: chartInfo.dataColor,
            fontSize: chartInfo.dataFontSize
        },
    };

    if (isRose) {
        series.radius = [innerRadius === null ? 20 : innerRadius, hasBottomLegend ? 82 : 100];
        series.roseType = 'radius';
        series.itemStyle = {
            borderRadius: 5
        };
    }

    return series;
};

export const buildPieLegendOption = (chartInfo = {}) => ({
    show: chartInfo.iconSwitch === '2',
    left: 'center',
    bottom: 0,
    orient: 'horizontal',
    textStyle: {
        color: chartInfo.iconColor
    },
    align: chartInfo.algin
});

const clampNumber = (value, min, max) => Math.max(min, Math.min(max, value));

const getNumericValue = (value, fallback = 0) => {
    if (value === null || value === undefined) return fallback;
    if (typeof value === 'number') return Number.isFinite(value) ? value : fallback;
    const match = String(value).match(/-?\d+(?:\.\d+)?/);
    if (!match) return fallback;
    const numberValue = Number(match[0]);
    return Number.isFinite(numberValue) ? numberValue : fallback;
};

const getFirstWaterBallValue = (chartInfo = {}) => {
    if (chartInfo.value !== undefined) return getNumericValue(chartInfo.value, 0);
    const data = chartInfo.data;
    if (Array.isArray(data) && data.length > 0) {
        const first = data[0];
        if (first && typeof first === 'object') {
            if (first.value !== undefined) return getNumericValue(first.value, 0);
            if (Array.isArray(first.data) && first.data.length > 0) return getNumericValue(first.data[0], 0);
        }
        return getNumericValue(first, 0);
    }
    return 0;
};

export const getWaterBallRatio = (chartInfo = {}) => {
    const fixedValue = getPositiveNumber(chartInfo.waterBallFixedValue, 100);
    return clampNumber(getFirstWaterBallValue(chartInfo) / fixedValue, 0, 1);
};

const buildWaterBallClipShape = (shapeKey, cx, cy, radius, name = 'water-ball-clip') => {
    const left = cx - radius;
    const top = cy - radius;
    const size = radius * 2;
    const base = { name, silent: true };
    if (shapeKey === 'rect') {
        return { ...base, type: 'rect', shape: { x: left, y: top, width: size, height: size } };
    }
    if (shapeKey === 'roundedRect') {
        return { ...base, type: 'rect', shape: { x: left, y: top, width: size, height: size, r: radius * 0.18 } };
    }
    if (shapeKey === 'triangle') {
        return { ...base, type: 'polygon', shape: { points: [[cx, top], [left, top + size], [left + size, top + size]] } };
    }
    if (shapeKey === 'diamond') {
        return { ...base, type: 'polygon', shape: { points: [[cx, top], [left + size, cy], [cx, top + size], [left, cy]] } };
    }
    if (shapeKey === 'drop') {
        return { ...base, type: 'polygon', shape: { points: [[cx, top], [left + size * 0.86, top + size * 0.42], [cx, top + size], [left + size * 0.14, top + size * 0.42]] } };
    }
    if (shapeKey === 'arrow') {
        return { ...base, type: 'polygon', shape: { points: [[cx, top], [left + size, cy], [left + size * 0.64, cy], [left + size * 0.64, top + size], [left + size * 0.36, top + size], [left + size * 0.36, cy], [left, cy]] } };
    }
    return { ...base, type: 'circle', shape: { cx, cy, r: radius } };
};

const buildWaterBallWavePoints = (cx, cy, radius, ratio, phase = 0, horizontalPadding = 0) => {
    const points = [];
    const left = cx - radius - horizontalPadding;
    const right = cx + radius + horizontalPadding;
    const bottom = cy + radius;
    const topY = bottom - radius * 2 * ratio;
    const amplitude = Math.max(3, radius * 0.075);
    const steps = 32;
    for (let index = 0; index <= steps; index += 1) {
        const percent = index / steps;
        const x = left + (radius * 2 + horizontalPadding * 2) * percent;
        const y = topY + Math.sin(percent * Math.PI * 2 + phase) * amplitude;
        points.push([x, y]);
    }
    points.push([right, bottom + radius * 0.12], [left, bottom + radius * 0.12]);
    return points;
};

const getWaterBallStyleTokens = (chartInfo = {}) => {
    const styleKey = chartInfo.chartStyle || chartInfo.chartThemeStyle || 'original';
    const preset = styleKey && styleKey !== 'original' ? chartStylePresets[styleKey] : null;
    const waveColor = preset ? preset.colors[0] : (chartInfo.waterBallWaveColor || '#4992FF');
    const waveColor2 = preset ? (preset.colors[2] || preset.colors[1] || preset.label) : (chartInfo.waterBallWaveColor2 || '#7CFFB2');
    const shadowColor = preset ? preset.shadow : waveColor;
    const labelColor = preset && isDefaultDarkLabelColor(chartInfo.dataColor)
        ? preset.label
        : (chartInfo.dataColor || '#ffffff');
    const backgroundFill = preset ? {
        type: 'linear',
        x: 0,
        y: 0,
        x2: 1,
        y2: 1,
        colorStops: [
            { offset: 0, color: hexToRgba(preset.colors[1] || preset.colors[0], 0.46) },
            { offset: 0.55, color: 'rgba(4, 20, 38, 0.92)' },
            { offset: 1, color: hexToRgba(preset.colors[0], 0.28) }
        ]
    } : (chartInfo.waterBallBackgroundColor || 'rgba(68, 181, 226, 0.3)');

    return {
        preset,
        waveColor,
        backgroundFill,
        labelColor,
        borderColor: preset ? hexToRgba(preset.axis, 0.72) : (chartInfo.waterBallBorderColor || 'rgba(255, 255, 255, 0.14)'),
        highlightColor: chartInfo.waterBallWaveHighlightColor || waveColor2,
        shadowColor,
        accentColor: preset ? (preset.colors[1] || preset.colors[0]) : waveColor
    };
};

const getWaterBallMotionGraphicLayer = (mode, tokens, cx, cy, radius) => {
    if (mode === 'entrance') {
        return [{
            name: 'water-ball-motion-entrance',
            type: 'ring',
            silent: true,
            z: 6,
            origin: [cx, cy],
            shape: { cx, cy, r: radius + 7, r0: radius + 3 },
            style: {
                fill: 'transparent',
                stroke: tokens.borderColor,
                lineWidth: 2,
                opacity: 0.68,
                shadowBlur: 20,
                shadowColor: tokens.shadowColor
            },
            keyframeAnimation: {
                duration: 1200,
                loop: false,
                keyframes: [
                    { percent: 0, scaleX: 0.82, scaleY: 0.82, style: { opacity: 0 } },
                    { percent: 0.55, scaleX: 1.08, scaleY: 1.08, style: { opacity: 0.78 } },
                    { percent: 1, scaleX: 1, scaleY: 1, style: { opacity: 0.42 } }
                ]
            }
        }];
    }
    if (mode === 'pulse') {
        return [{
            name: 'water-ball-motion-pulse',
            type: 'ring',
            silent: true,
            z: 6,
            origin: [cx, cy],
            shape: { cx, cy, r: radius + 8, r0: Math.max(0, radius - 2) },
            style: {
                fill: 'transparent',
                stroke: tokens.borderColor,
                lineWidth: 2,
                opacity: 0.28,
                shadowBlur: 16,
                shadowColor: tokens.shadowColor
            },
            keyframeAnimation: {
                duration: 1500,
                loop: true,
                keyframes: [
                    { percent: 0, scaleX: 0.98, scaleY: 0.98, style: { opacity: 0.18, shadowBlur: 10 } },
                    { percent: 0.5, scaleX: 1.08, scaleY: 1.08, style: { opacity: 0.62, shadowBlur: 28 } },
                    { percent: 1, scaleX: 0.98, scaleY: 0.98, style: { opacity: 0.18, shadowBlur: 10 } }
                ]
            }
        }];
    }
    if (mode === 'flow') {
        return [{
            name: 'water-ball-motion-flow',
            type: 'group',
            silent: true,
            z: 7,
            origin: [cx, cy],
            children: [
                {
                    name: 'water-ball-motion-flow-arc',
                    type: 'arc',
                    shape: {
                        cx,
                        cy,
                        r: radius + 7,
                        startAngle: -Math.PI * 0.22,
                        endAngle: Math.PI * 0.42,
                        clockwise: true
                    },
                    style: {
                        stroke: tokens.labelColor,
                        fill: null,
                        opacity: 0.78,
                        lineWidth: 4,
                        lineCap: 'round',
                        shadowBlur: 24,
                        shadowColor: tokens.shadowColor
                    }
                },
                {
                    name: 'water-ball-motion-flow-tail',
                    type: 'arc',
                    shape: {
                        cx,
                        cy,
                        r: radius + 4,
                        startAngle: -Math.PI * 0.38,
                        endAngle: -Math.PI * 0.06,
                        clockwise: true
                    },
                    style: {
                        stroke: tokens.accentColor,
                        fill: null,
                        opacity: 0.28,
                        lineWidth: 2,
                        lineCap: 'round',
                        shadowBlur: 12,
                        shadowColor: tokens.shadowColor
                    }
                }
            ],
            keyframeAnimation: {
                duration: 1800,
                loop: true,
                keyframes: [
                    { percent: 0, rotation: 0 },
                    { percent: 1, rotation: Math.PI * 2 }
                ]
            }
        }];
    }
    return [];
};

export const buildWaterBallOption = (chartInfo = {}) => {
    const width = getPositiveNumber(chartInfo.width, 200);
    const height = getPositiveNumber(chartInfo.height, 160);
    const ratio = getWaterBallRatio(chartInfo);
    const cx = Math.round(width / 2);
    const cy = Math.round(height / 2);
    const radius = Math.round(Math.min(width, height) * 0.42);
    const shapeKey = chartInfo.waterBallShape || 'circle';
    const tokens = getWaterBallStyleTokens(chartInfo);
    const clipShape = buildWaterBallClipShape(shapeKey, cx, cy, radius);
    const backgroundShape = {
        ...buildWaterBallClipShape(shapeKey, cx, cy, radius, 'water-ball-background'),
        style: {
            fill: tokens.backgroundFill,
            shadowBlur: 18,
            shadowColor: tokens.shadowColor,
            opacity: 0.95
        },
        z: 1
    };
    const waveClip = buildWaterBallClipShape(shapeKey, cx, cy, radius, 'water-ball-clip');
    const wavePadding = radius * 0.35;
    const waveShift = radius * 0.16;
    const labelText = `${Math.round(ratio * 100)}%`;
    const motionGraphics = getWaterBallMotionGraphicLayer(getAnimationMode(chartInfo), tokens, cx, cy, radius);

    return {
        animation: true,
        backgroundColor: 'transparent',
        series: [{
            name: 'water-ball-value',
            type: 'custom',
            coordinateSystem: 'none',
            silent: true,
            data: [ratio],
            renderItem: () => ({ type: 'group', children: [] })
        }],
        graphic: [
            {
                ...backgroundShape
            },
            {
                ...clipShape,
                style: {
                    fill: 'transparent',
                    stroke: tokens.borderColor,
                    lineWidth: 1.5,
                    shadowBlur: 8,
                    shadowColor: tokens.shadowColor
                },
                z: 4
            },
            {
                name: 'water-ball-wave-group',
                type: 'group',
                silent: true,
                clipPath: waveClip,
                z: 2,
                children: [
                    {
                        name: 'water-ball-wave',
                        type: 'polygon',
                        z: 2,
                        shape: {
                            points: buildWaterBallWavePoints(cx, cy, radius, ratio, 0, wavePadding)
                        },
                        style: {
                            fill: tokens.waveColor,
                            opacity: tokens.preset ? 0.94 : 0.92,
                            shadowBlur: 12,
                            shadowColor: tokens.shadowColor
                        },
                        keyframeAnimation: {
                            duration: 1800,
                            loop: true,
                            keyframes: [
                                { percent: 0, x: -waveShift },
                                { percent: 0.5, x: waveShift },
                                { percent: 1, x: -waveShift }
                            ]
                        }
                    },
                    {
                        name: 'water-ball-wave-highlight',
                        type: 'polygon',
                        z: 3,
                        shape: {
                            points: buildWaterBallWavePoints(cx, cy, radius, ratio, Math.PI * 0.7, wavePadding)
                        },
                        style: {
                            fill: tokens.highlightColor,
                            opacity: 0.48
                        },
                        keyframeAnimation: {
                            duration: 2300,
                            loop: true,
                            keyframes: [
                                { percent: 0, x: waveShift },
                                { percent: 0.5, x: -waveShift },
                                { percent: 1, x: waveShift }
                            ]
                        }
                    }
                ]
            },
            {
                name: 'water-ball-label',
                type: 'text',
                z: 5,
                style: {
                    text: labelText,
                    x: cx,
                    y: cy,
                    fill: tokens.labelColor,
                    fontSize: getPositiveNumber(chartInfo.dataFontSize, 24),
                    fontFamily: chartInfo.dataFontFamily || chartInfo.fontFamily || 'Arial',
                    fontWeight: chartInfo.fontStyle && String(chartInfo.fontStyle).indexOf('bold') > -1 ? 700 : 600,
                    textAlign: 'center',
                    textVerticalAlign: 'middle',
                    textShadowBlur: 10,
                    textShadowColor: chartInfo.waterBallTextShadowColor || tokens.shadowColor
                }
            },
            ...motionGraphics
        ]
    };
};

const chartResizeObserverMap = new WeakMap();

const bindChartAutoResize = (chart, element) => {
    if (!chart || !element || chartResizeObserverMap.has(element)) return;
    const resizeChart = () => {
        try {
            chart.resize();
        } catch (e) {
            // Ignore resize races while the chart is being disposed.
        }
    };
    if (typeof ResizeObserver !== 'undefined') {
        const observer = new ResizeObserver(resizeChart);
        observer.observe(element);
        chartResizeObserverMap.set(element, observer);
        return;
    }
    window.addEventListener('resize', resizeChart);
    chartResizeObserverMap.set(element, {
        disconnect: () => window.removeEventListener('resize', resizeChart)
    });
};

const chartStylePresets = {
    neon: {
        colors: ['#20f7ff', '#7c5cff', '#ff4fd8', '#35ff9f', '#ffd166'],
        axis: '#65d8ff',
        label: '#d8f7ff',
        split: 'rgba(78, 211, 255, 0.18)',
        shadow: '#20f7ff'
    },
    aurora: {
        colors: ['#36f2b7', '#28a7ff', '#7dff6a', '#ffd34d', '#ff6b8a'],
        axis: '#49f6c7',
        label: '#dffdf4',
        split: 'rgba(55, 242, 183, 0.16)',
        shadow: '#36f2b7'
    },
    amber: {
        colors: ['#ffd166', '#ff9f1c', '#ff5d5d', '#fff275', '#2ec4b6'],
        axis: '#ffd166',
        label: '#fff7dc',
        split: 'rgba(255, 209, 102, 0.18)',
        shadow: '#ffb703'
    }
};

const defaultMotionPreset = {
    colors: ['#20f7ff', '#7c5cff', '#36f2b7', '#ffd166'],
    axis: '#65d8ff',
    label: '#d8f7ff',
    split: 'rgba(78, 211, 255, 0.18)',
    shadow: '#20f7ff'
};

const cloneOption = (option) => JSON.parse(JSON.stringify(option || {}));

const gradientColor = (topColor, bottomColor) => ({
    type: 'linear',
    x: 0,
    y: 0,
    x2: 0,
    y2: 1,
    colorStops: [
        { offset: 0, color: topColor },
        { offset: 1, color: bottomColor }
    ],
    global: false
});

const hexToRgba = (hexColor, alpha) => {
    const normalized = String(hexColor || '').replace('#', '').trim();
    if (!/^[0-9a-fA-F]{6}$/.test(normalized)) return hexColor;
    const red = parseInt(normalized.slice(0, 2), 16);
    const green = parseInt(normalized.slice(2, 4), 16);
    const blue = parseInt(normalized.slice(4, 6), 16);
    return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
};

const getPieBorderColor = (preset, alpha) => hexToRgba(preset.axis || preset.colors[0], alpha);

const withAxisStyle = (option, preset) => {
    if (option.xAxis) {
        option.xAxis.axisLine = {
            ...(option.xAxis.axisLine || {}),
            lineStyle: { ...((option.xAxis.axisLine || {}).lineStyle || {}), color: preset.axis, width: 1 }
        };
        option.xAxis.axisTick = {
            ...(option.xAxis.axisTick || {}),
            lineStyle: { ...((option.xAxis.axisTick || {}).lineStyle || {}), color: preset.axis }
        };
        option.xAxis.axisLabel = {
            ...(option.xAxis.axisLabel || {}),
            textStyle: { ...((option.xAxis.axisLabel || {}).textStyle || {}), color: preset.label, fontWeight: 500 }
        };
    }
    if (option.yAxis) {
        option.yAxis.axisLine = {
            ...(option.yAxis.axisLine || {}),
            lineStyle: { ...((option.yAxis.axisLine || {}).lineStyle || {}), color: preset.axis, width: 1 }
        };
        option.yAxis.axisTick = {
            ...(option.yAxis.axisTick || {}),
            lineStyle: { ...((option.yAxis.axisTick || {}).lineStyle || {}), color: preset.axis }
        };
        option.yAxis.axisLabel = {
            ...(option.yAxis.axisLabel || {}),
            textStyle: { ...((option.yAxis.axisLabel || {}).textStyle || {}), color: preset.label, fontWeight: 500 }
        };
        option.yAxis.splitLine = {
            ...(option.yAxis.splitLine || {}),
            show: true,
            lineStyle: { ...((option.yAxis.splitLine || {}).lineStyle || {}), color: preset.split, type: 'dashed' }
        };
    }
};

const getGraphicBaseLayer = (preset) => ([
    {
        type: 'rect',
        left: 0,
        top: 0,
        right: 0,
        bottom: 0,
        silent: true,
        z: -20,
        style: {
            fill: {
                type: 'linear',
                x: 0,
                y: 0,
                x2: 1,
                y2: 1,
                colorStops: [
                    { offset: 0, color: 'rgba(5, 20, 45, 0.42)' },
                    { offset: 0.55, color: 'rgba(4, 16, 34, 0.12)' },
                    { offset: 1, color: 'rgba(2, 10, 24, 0.35)' }
                ]
            }
        }
    },
    {
        type: 'group',
        right: 10,
        top: 10,
        silent: true,
        z: -10,
        children: [
            {
                type: 'line',
                shape: { x1: 0, y1: 0, x2: 42, y2: 0 },
                style: { stroke: preset.axis, lineWidth: 2, opacity: 0.8, shadowBlur: 8, shadowColor: preset.shadow }
            },
            {
                type: 'line',
                shape: { x1: 42, y1: 0, x2: 42, y2: 18 },
                style: { stroke: preset.axis, lineWidth: 2, opacity: 0.5 }
            }
        ]
    }
]);

const getGaugeGraphicLayer = (preset) => ([
    {
        type: 'ring',
        left: 'center',
        top: 'middle',
        silent: true,
        z: -8,
        shape: {
            r: 92,
            r0: 89
        },
        style: {
            stroke: preset.axis,
            fill: 'transparent',
            opacity: 0.28,
            lineWidth: 2,
            shadowBlur: 18,
            shadowColor: preset.shadow
        }
    },
    {
        type: 'ring',
        left: 'center',
        top: 'middle',
        silent: true,
        z: -9,
        shape: {
            r: 68,
            r0: 66
        },
        style: {
            stroke: preset.colors[1],
            fill: 'transparent',
            opacity: 0.16,
            lineWidth: 1
        }
    }
]);

const hasUsefulValue = (value) => {
    if (Array.isArray(value)) return value.some(hasUsefulValue);
    if (value && typeof value === 'object') return hasUsefulValue(value.value);
    if (value === null || value === undefined || value === '') return false;
    const numericValue = Number(value);
    return Number.isFinite(numericValue) && numericValue !== 0;
};

const optionHasUsefulData = (option) => {
    const series = Array.isArray(option.series) ? option.series : [];
    return series.some(item => Array.isArray(item.data) && item.data.some(hasUsefulValue));
};

const withNoDataHint = (option, preset) => {
    if (optionHasUsefulData(option)) return;
    option.graphic = [
        ...(option.graphic || []),
        {
            type: 'text',
            left: 'center',
            top: 'middle',
            silent: true,
            z: 20,
            style: {
                text: '暂无数据',
                fill: preset.label,
                fontSize: 16,
                fontWeight: 600,
                opacity: 0.72,
                textShadowColor: preset.shadow,
                textShadowBlur: 10
            }
        }
    ];
};

const withCommonStyle = (option, preset) => {
    option.backgroundColor = 'transparent';
    option.color = preset.colors;
    option.tooltip = {
        ...(option.tooltip || {}),
        backgroundColor: 'rgba(4, 18, 35, 0.92)',
        borderColor: preset.axis,
        borderWidth: 1,
        textStyle: { color: '#ffffff' },
        extraCssText: `box-shadow: 0 0 16px ${preset.shadow}; border-radius: 6px;`
    };
    if (option.tooltip.trigger === 'axis') {
        option.tooltip.axisPointer = {
            ...(option.tooltip.axisPointer || {}),
            type: 'shadow',
            shadowStyle: {
                color: 'rgba(96, 218, 255, 0.10)'
            },
            lineStyle: {
                color: preset.axis,
                width: 1,
                type: 'dashed'
            },
            label: {
                backgroundColor: 'rgba(4, 18, 35, 0.94)',
                color: '#ffffff',
                borderColor: preset.axis,
                borderWidth: 1
            }
        };
    }
    option.graphic = [
        ...getGraphicBaseLayer(preset),
        ...(option.graphic || [])
    ];
    if (option.title && option.title.textStyle) {
        option.title.textStyle = {
            ...option.title.textStyle,
            color: option.title.textStyle.color || preset.label,
            textShadowColor: preset.shadow,
            textShadowBlur: 8
        };
    }
    if (option.legend && option.legend.textStyle) {
        option.legend.textStyle = styleLegendText(option.legend.textStyle, preset);
    }
    withAxisStyle(option, preset);
    withNoDataHint(option, preset);
};

const isDefaultDarkLabelColor = (color) => {
    if (!color) return true;
    const normalized = String(color).replace(/\s/g, '').toLowerCase();
    return normalized === '#000000' || normalized === '#000' || normalized === 'black' || normalized === 'rgb(0,0,0)' || normalized === 'rgba(0,0,0,1)';
};

const styleDataLabel = (label = {}, preset) => {
    if (!label.show) return label;
    return {
        ...label,
        color: isDefaultDarkLabelColor(label.color) ? preset.label : label.color,
        fontWeight: label.fontWeight || 700,
        textShadowColor: label.textShadowColor || preset.shadow,
        textShadowBlur: label.textShadowBlur || 10
    };
};

const styleLegendText = (textStyle = {}, preset) => ({
    ...textStyle,
    color: isDefaultDarkLabelColor(textStyle.color) ? preset.label : textStyle.color,
    fontWeight: textStyle.fontWeight || 600,
    textShadowColor: textStyle.textShadowColor || preset.shadow,
    textShadowBlur: textStyle.textShadowBlur || 8
});

const styleBarSeries = (series, preset, index) => ({
    ...series,
    barMaxWidth: series.barMaxWidth || 34,
    barMinHeight: series.barMinHeight || 3,
    ...(series.label ? { label: styleDataLabel(series.label, preset) } : {}),
    itemStyle: {
        ...(series.itemStyle || {}),
        color: gradientColor(preset.colors[index % preset.colors.length], 'rgba(5, 20, 42, 0.45)'),
        borderRadius: [8, 8, 2, 2],
        shadowBlur: 14,
        shadowColor: preset.colors[index % preset.colors.length],
        shadowOffsetY: 0
    },
    emphasis: {
        ...(series.emphasis || {}),
        itemStyle: {
            ...((series.emphasis || {}).itemStyle || {}),
            shadowBlur: 24,
            shadowColor: preset.colors[index % preset.colors.length]
        }
    }
});

const styleSortedBarSeries = (series, preset, index) => ({
    ...styleBarSeries(series, preset, index),
    backgroundStyle: {
        ...(series.backgroundStyle || {}),
        color: 'rgba(255, 255, 255, 0.06)',
        borderRadius: [8, 8, 2, 2]
    },
    showBackground: true
});

const getAnimationMode = (chartInfo = {}) => chartInfo.chartAnimation || chartInfo.chartMotion || 'off';

const buildLineFlowSeries = (series, option, preset, index) => {
    const points = getLineMotionPoints(series, option);
    if (points.length < 2) return null;
    return {
        name: `${series.name || 'line'}-flow`,
        type: 'lines',
        coordinateSystem: 'cartesian2d',
        polyline: true,
        z: 10,
        data: [{ coords: points }],
        lineStyle: {
            color: 'transparent',
            width: 0,
            opacity: 0
        },
        effect: {
            show: true,
            period: 3,
            trailLength: 0.45,
            symbol: 'circle',
            symbolSize: 6,
            color: preset.colors[index % preset.colors.length]
        },
        tooltip: { show: false }
    };
};

const getLineDataValue = (dataItem) => {
    const rawValue = dataItem && typeof dataItem === 'object' && !Array.isArray(dataItem)
        ? dataItem.value
        : dataItem;
    if (Array.isArray(rawValue)) {
        return rawValue.length > 1 ? rawValue[1] : rawValue[0];
    }
    return rawValue;
};

const getLineMotionPoints = (series, option) => {
    const xAxis = getSingleAxisOption(option.xAxis);
    const xdata = Array.isArray(xAxis.data) ? xAxis.data : [];
    const data = Array.isArray(series.data) ? series.data : [];
    return data
        .map((item, pointIndex) => {
            const yValue = getLineDataValue(item);
            if (yValue === null || yValue === undefined || yValue === '' || Number.isNaN(Number(yValue))) return null;
            return [xdata[pointIndex] === undefined ? pointIndex : xdata[pointIndex], Number(yValue)];
        })
        .filter(Boolean);
};

const buildLineMotionSeries = (series, option, preset, index, mode) => {
    if (mode !== 'entrance' && mode !== 'pulse') return null;
    const points = getLineMotionPoints(series, option);
    if (points.length < 2) return null;
    const glowColor = preset.colors[index % preset.colors.length];
    const tailColor = preset.colors[(index + 1) % preset.colors.length] || glowColor;
    return {
        name: mode === 'entrance' ? 'line-entrance-motion' : 'line-pulse-motion',
        type: 'custom',
        coordinateSystem: 'cartesian2d',
        data: [0],
        silent: true,
        z: 92,
        tooltip: { show: false },
        renderItem: (params, api) => {
            const screenPoints = points.map(point => api.coord(point));
            if (screenPoints.length < 2) return null;
            const coordSys = params.coordSys || {};
            const pathStyle = {
                stroke: glowColor,
                lineWidth: mode === 'entrance' ? 4 : 5,
                fill: null,
                opacity: mode === 'entrance' ? 0.86 : 0.54,
                shadowBlur: mode === 'entrance' ? 22 : 28,
                shadowColor: glowColor,
                lineJoin: 'round',
                lineCap: 'round'
            };
            const children = [
                {
                    name: mode === 'entrance' ? 'line-entrance-stroke' : 'line-pulse-glow',
                    type: 'polyline',
                    shape: {
                        points: screenPoints,
                        smooth: 0.25
                    },
                    style: pathStyle,
                    keyframeAnimation: mode === 'pulse' ? {
                        duration: 1500,
                        delay: (index % 5) * 120,
                        loop: true,
                        keyframes: [
                            { percent: 0, style: { opacity: 0.28, lineWidth: 3, shadowBlur: 10 } },
                            { percent: 0.5, style: { opacity: 0.92, lineWidth: 6, shadowBlur: 34 } },
                            { percent: 1, style: { opacity: 0.28, lineWidth: 3, shadowBlur: 10 } }
                        ]
                    } : undefined
                }
            ];

            if (mode === 'entrance') {
                const lastPoint = screenPoints[screenPoints.length - 1];
                children.push({
                    name: 'line-entrance-head',
                    type: 'circle',
                    shape: { cx: lastPoint[0], cy: lastPoint[1], r: 5 },
                    style: {
                        fill: '#ffffff',
                        opacity: 0.82,
                        shadowBlur: 18,
                        shadowColor: glowColor
                    },
                    keyframeAnimation: {
                        duration: 1200,
                        loop: false,
                        keyframes: [
                            { percent: 0, style: { opacity: 0, shadowBlur: 0 } },
                            { percent: 0.72, style: { opacity: 0.92, shadowBlur: 22 } },
                            { percent: 1, style: { opacity: 0.54, shadowBlur: 12 } }
                        ]
                    }
                });
            } else {
                children.push({
                    name: 'line-pulse-core',
                    type: 'polyline',
                    shape: {
                        points: screenPoints,
                        smooth: 0.25
                    },
                    style: {
                        stroke: tailColor,
                        lineWidth: 2,
                        fill: null,
                        opacity: 0.46,
                        lineJoin: 'round',
                        lineCap: 'round'
                    }
                });
            }

            const group = {
                type: 'group',
                children
            };
            if (mode === 'entrance') {
                group.clipPath = {
                    type: 'rect',
                    shape: {
                        x: coordSys.x || 0,
                        y: coordSys.y || 0,
                        width: coordSys.width || 0,
                        height: coordSys.height || 0
                    },
                    origin: [coordSys.x || 0, coordSys.y || 0],
                    scaleX: 0,
                    keyframeAnimation: {
                        duration: 1200,
                        loop: false,
                        keyframes: [
                            { percent: 0, scaleX: 0 },
                            { percent: 0.2, scaleX: 0.08 },
                            { percent: 1, scaleX: 1 }
                        ]
                    }
                };
            }
            return group;
        }
    };
};

const getSingleAxisOption = (axis) => Array.isArray(axis) ? (axis[0] || {}) : (axis || {});

const getBarDataValue = (dataItem) => {
    const rawValue = dataItem && typeof dataItem === 'object' && !Array.isArray(dataItem)
        ? dataItem.value
        : dataItem;
    if (Array.isArray(rawValue)) {
        const numericValue = rawValue.find(value => value !== null && value !== undefined && value !== '' && !Number.isNaN(Number(value)));
        return Number(numericValue || 0);
    }
    return Number(rawValue || 0);
};

const getBarShapeContext = (series, option, index, preset) => {
    const data = Array.isArray(series.data) ? series.data : [];
    const xAxis = getSingleAxisOption(option.xAxis);
    const yAxis = getSingleAxisOption(option.yAxis);
    const isHorizontal = yAxis.type === 'category';
    const categoryData = isHorizontal
        ? (Array.isArray(yAxis.data) ? yAxis.data : [])
        : (Array.isArray(xAxis.data) ? xAxis.data : []);
    const barWidth = Number.parseFloat(series.barWidth || series.barMaxWidth || 26);
    return {
        data,
        isHorizontal,
        categoryData,
        maxBarWidth: Number.isFinite(barWidth) ? barWidth : 26,
        glowColor: preset.colors[index % preset.colors.length],
        tailColor: preset.colors[(index + 1) % preset.colors.length] || preset.colors[index % preset.colors.length]
    };
};

const getBarSeriesData = (data, categoryData, isHorizontal) => data.map((item, dataIndex) => {
    const category = categoryData[dataIndex] === undefined ? dataIndex : categoryData[dataIndex];
    const value = getBarDataValue(item);
    return isHorizontal ? [value, category, dataIndex] : [category, value, dataIndex];
});

const getBarShapeFromApi = (api, params, isHorizontal, maxBarWidth) => {
    const value = Number(api.value(isHorizontal ? 0 : 1) || 0);
    const categoryValue = api.value(isHorizontal ? 1 : 0);
    const startPoint = isHorizontal ? api.coord([0, categoryValue]) : api.coord([categoryValue, 0]);
    const endPoint = isHorizontal ? api.coord([value, categoryValue]) : api.coord([categoryValue, value]);
    const bandSize = isHorizontal ? api.size([0, 1])[1] : api.size([1, 0])[0];
    const bodySize = Math.max(4, Math.min(Math.abs(bandSize) * 0.58, maxBarWidth));
    const shape = isHorizontal ? {
        x: Math.min(startPoint[0], endPoint[0]),
        y: endPoint[1] - bodySize / 2,
        width: Math.abs(endPoint[0] - startPoint[0]),
        height: bodySize
    } : {
        x: endPoint[0] - bodySize / 2,
        y: Math.min(startPoint[1], endPoint[1]),
        width: bodySize,
        height: Math.abs(startPoint[1] - endPoint[1])
    };
    return echarts.graphic.clipRectByRect(shape, {
        x: params.coordSys.x,
        y: params.coordSys.y,
        width: params.coordSys.width,
        height: params.coordSys.height
    });
};

const buildBarMotionKeyframes = (mode, dataIndex) => {
    const delay = (dataIndex % 6) * 120;
    if (mode === 'entrance') {
        return {
            duration: 1200,
            delay,
            loop: false,
            keyframes: [
                { percent: 0, scaleX: 0.72, scaleY: 0.04, style: { opacity: 0.08, shadowBlur: 4 } },
                { percent: 0.55, scaleX: 1.03, scaleY: 1.03, style: { opacity: 0.82, shadowBlur: 28 } },
                { percent: 1, scaleX: 1, scaleY: 1, style: { opacity: 0.42, shadowBlur: 14 } }
            ]
        };
    }
    if (mode === 'flow') {
        return {
            duration: 1700,
            delay,
            loop: true,
            keyframes: [
                { percent: 0, style: { opacity: 0.16, shadowBlur: 6 } },
                { percent: 0.44, style: { opacity: 0.72, shadowBlur: 24 } },
                { percent: 1, style: { opacity: 0.16, shadowBlur: 6 } }
            ]
        };
    }
    return {
        duration: 1450,
        delay,
        loop: true,
        keyframes: [
            { percent: 0, style: { opacity: 0.18, shadowBlur: 8 } },
            { percent: 0.5, style: { opacity: 0.78, shadowBlur: 30 } },
            { percent: 1, style: { opacity: 0.18, shadowBlur: 8 } }
        ]
    };
};

const buildBarFlowHighlight = (shape, isHorizontal, glowColor, dataIndex) => {
    const highlightSize = Math.max(12, (isHorizontal ? shape.width : shape.height) * 0.28);
    const startOffset = 0;
    const endOffset = isHorizontal ? shape.width + highlightSize : -highlightSize;
    const sweepShape = isHorizontal ? {
        x: shape.x - highlightSize,
        y: shape.y,
        width: highlightSize,
        height: shape.height
    } : {
        x: shape.x,
        y: shape.y + shape.height,
        width: shape.width,
        height: highlightSize
    };

    return {
        name: 'bar-flow-highlight',
        type: 'rect',
        shape: sweepShape,
        clipPath: { type: 'rect', shape },
        style: {
            fill: new echarts.graphic.LinearGradient(
                isHorizontal ? 0 : 0,
                isHorizontal ? 0 : 1,
                isHorizontal ? 1 : 0,
                isHorizontal ? 0 : 0,
                [
                    { offset: 0, color: 'rgba(255, 255, 255, 0)' },
                    { offset: 0.5, color: 'rgba(255, 255, 255, 0.72)' },
                    { offset: 1, color: 'rgba(255, 255, 255, 0)' }
                ]
            ),
            opacity: 0.78,
            shadowBlur: 18,
            shadowColor: glowColor
        },
        keyframeAnimation: {
            duration: 1500,
            delay: (dataIndex % 6) * 110,
            loop: true,
            keyframes: isHorizontal ? [
                { percent: 0, x: startOffset, style: { opacity: 0 } },
                { percent: 0.18, x: shape.width * 0.15, style: { opacity: 0.76 } },
                { percent: 0.82, x: shape.width * 0.72, style: { opacity: 0.76 } },
                { percent: 1, x: endOffset, style: { opacity: 0 } }
            ] : [
                { percent: 0, y: startOffset, style: { opacity: 0 } },
                { percent: 0.18, y: shape.height * 0.72, style: { opacity: 0.76 } },
                { percent: 0.82, y: shape.height * 0.15, style: { opacity: 0.76 } },
                { percent: 1, y: endOffset, style: { opacity: 0 } }
            ]
        }
    };
};

const buildBarMotionSeries = (series, option, preset, index, mode, styleKey = 'original') => {
    const data = Array.isArray(series.data) ? series.data : [];
    if (data.length === 0) return null;

    const { isHorizontal, categoryData, maxBarWidth, glowColor, tailColor } = getBarShapeContext(series, option, index, preset);

    return {
        name: `${series.name || 'bar'}-${mode}-body`,
        type: 'custom',
        coordinateSystem: 'cartesian2d',
        data: getBarSeriesData(data, categoryData, isHorizontal),
        silent: true,
        z: 90,
        tooltip: { show: false },
        renderItem: (params, api) => {
            const clippedShape = getBarShapeFromApi(api, params, isHorizontal, maxBarWidth);
            if (!clippedShape || clippedShape.width <= 0 || clippedShape.height <= 0) return null;

            const dataIndex = Number(api.value(2) || 0);
            const barMotionKeyframes = buildBarMotionKeyframes(mode, dataIndex);
            if (styleKey && styleKey !== 'original') {
                const shapeChildren = buildBarShapeChildren(clippedShape, isHorizontal, styleKey, glowColor, tailColor).map(child => ({
                    ...child,
                    keyframeAnimation: barMotionKeyframes
                }));
                const children = mode === 'flow'
                    ? [...shapeChildren, buildBarFlowHighlight(clippedShape, isHorizontal, glowColor, dataIndex)]
                    : shapeChildren;
                const shapeGroup = {
                    type: 'group',
                    children
                };
                if (['rounded', 'cylinder'].includes(styleKey)) {
                    shapeGroup.clipPath = { type: 'rect', shape: clippedShape };
                }
                return shapeGroup;
            }
            const fill = new echarts.graphic.LinearGradient(
                0,
                isHorizontal ? 0 : 1,
                isHorizontal ? 1 : 0,
                0,
                [
                    { offset: 0, color: 'rgba(255, 255, 255, 0.12)' },
                    { offset: 0.48, color: glowColor },
                    { offset: 1, color: tailColor }
                ]
            );
            const baseRect = {
                type: 'rect',
                shape: clippedShape,
                style: {
                    fill,
                    opacity: mode === 'entrance' ? 0.22 : 0.32,
                    shadowBlur: mode === 'flow' ? 8 : 12,
                    shadowColor: glowColor
                },
                keyframeAnimation: barMotionKeyframes
            };
            const innerGlow = {
                type: 'rect',
                shape: {
                    ...clippedShape,
                    x: clippedShape.x + (isHorizontal ? 0 : clippedShape.width * 0.18),
                    y: clippedShape.y + (isHorizontal ? clippedShape.height * 0.18 : 0),
                    width: isHorizontal ? clippedShape.width : clippedShape.width * 0.64,
                    height: isHorizontal ? clippedShape.height * 0.64 : clippedShape.height
                },
                style: {
                    fill: 'rgba(255, 255, 255, 0.34)',
                    opacity: 0.14,
                    shadowBlur: 10,
                    shadowColor: '#ffffff'
                },
                keyframeAnimation: mode === 'entrance' ? {
                    duration: 1000,
                    delay: (dataIndex % 6) * 120,
                    loop: false,
                    keyframes: [
                        { percent: 0, style: { opacity: 0 } },
                        { percent: 0.66, style: { opacity: 0.38 } },
                        { percent: 1, style: { opacity: 0.14 } }
                    ]
                } : {
                    duration: mode === 'flow' ? 1700 : 1450,
                    delay: (dataIndex % 6) * 120,
                    loop: true,
                    keyframes: [
                        { percent: 0, style: { opacity: 0.06 } },
                        { percent: 0.5, style: { opacity: mode === 'flow' ? 0.26 : 0.34 } },
                        { percent: 1, style: { opacity: 0.06 } }
                    ]
                }
            };
            const children = mode === 'flow'
                ? [baseRect, innerGlow, buildBarFlowHighlight(clippedShape, isHorizontal, glowColor, dataIndex)]
                : [baseRect, innerGlow];

            return {
                type: 'group',
                clipPath: { type: 'rect', shape: clippedShape },
                children
            };
        }
    };
};

const polygonShape = (points) => ({ points });

const transparentBarStyle = {
    color: 'rgba(0, 0, 0, 0)',
    borderColor: 'rgba(0, 0, 0, 0)',
    shadowBlur: 0,
    shadowColor: 'rgba(0, 0, 0, 0)'
};

const getHiddenBarBackgroundStyle = (style = {}) => ({
    ...style,
    ...transparentBarStyle,
    opacity: 0
});

const hideBarDataBackground = (data = []) => data.map(item => {
    if (!item || typeof item !== 'object' || Array.isArray(item)) return item;
    return {
        ...item,
        showBackground: false,
        backgroundStyle: getHiddenBarBackgroundStyle(item.backgroundStyle || {})
    };
});

const hasVisibleBarLabel = (series = {}) => !!(series.label && series.label.show);

const getTransparentLabelBarItemStyle = (style = {}) => {
    const nextStyle = {
        ...style,
        ...transparentBarStyle
    };
    delete nextStyle.opacity;
    return nextStyle;
};

const buildBarLabelCarrierSeries = (series, index, z) => ({
    ...series,
    name: index === 0 ? 'bar-data-labels' : `bar-data-labels-${index + 1}`,
    type: 'bar',
    silent: true,
    tooltip: { show: false },
    legendHoverLink: false,
    barGap: '-100%',
    z,
    showBackground: false,
    backgroundStyle: getHiddenBarBackgroundStyle(series.backgroundStyle || {}),
    itemStyle: getTransparentLabelBarItemStyle(series.itemStyle || {}),
    emphasis: {
        ...(series.emphasis || {}),
        disabled: true,
        itemStyle: getTransparentLabelBarItemStyle(((series.emphasis || {}).itemStyle || {}))
    },
    label: {
        ...(series.label || {}),
        show: true
    }
});

const buildBarShapeChildren = (shape, isHorizontal, styleKey, glowColor, tailColor) => {
    const fill = new echarts.graphic.LinearGradient(
        0,
        isHorizontal ? 0 : 1,
        isHorizontal ? 1 : 0,
        0,
        [
            { offset: 0, color: 'rgba(255, 255, 255, 0.22)' },
            { offset: 0.32, color: glowColor },
            { offset: 1, color: tailColor }
        ]
    );
    const tightPolygonShadow = ['diamond', 'hexagon', 'prism', 'trapezoid', 'pyramid', 'stereoGroup'].includes(styleKey);
    const baseStyle = {
        fill,
        opacity: 0.9,
        shadowBlur: tightPolygonShadow ? 0 : 18,
        shadowColor: glowColor
    };
    const edgeStyle = {
        fill: 'rgba(255, 255, 255, 0.22)',
        opacity: 0.75
    };
    const capSize = Math.max(4, Math.min(isHorizontal ? shape.height * 0.5 : shape.width * 0.5, 10));
    const x1 = shape.x;
    const y1 = shape.y;
    const x2 = shape.x + shape.width;
    const y2 = shape.y + shape.height;
    const mx = shape.x + shape.width / 2;
    const my = shape.y + shape.height / 2;

    if (styleKey === 'rounded') {
        return [{
            type: 'rect',
            shape: { ...shape, r: Math.min(shape.width, shape.height) * 0.42 },
            style: baseStyle
        }];
    }
    if (styleKey === 'cylinder') {
        return [
            { type: 'rect', shape, style: baseStyle },
            {
                type: 'ellipse',
                shape: isHorizontal
                    ? { cx: x1, cy: my, rx: capSize * 0.48, ry: shape.height / 2 }
                    : { cx: mx, cy: y1, rx: shape.width / 2, ry: capSize * 0.48 },
                style: { ...edgeStyle, fill: 'rgba(148, 238, 255, 0.55)' }
            },
            {
                type: 'ellipse',
                shape: isHorizontal
                    ? { cx: x2, cy: my, rx: capSize * 0.62, ry: shape.height / 2 }
                    : { cx: mx, cy: y2, rx: shape.width / 2, ry: capSize * 0.62 },
                style: { ...edgeStyle, opacity: 0.32 }
            }
        ];
    }
    if (styleKey === 'diamond') {
        const h = Math.min(isHorizontal ? shape.width * 0.22 : shape.height * 0.22, isHorizontal ? shape.height : shape.width);
        const main = isHorizontal
            ? [[x1 + h, y1], [x2, y1], [x2 - h, y2], [x1, y2]]
            : [[x1, y1 + h], [mx, y1], [x2, y1 + h], [x2, y2], [x1, y2]];
        const cap = isHorizontal
            ? [[x1, my], [x1 + h, y1], [x1 + h, y2]]
            : [[mx, y1], [x2, y1 + h], [x1, y1 + h]];
        return [
            { type: 'polygon', shape: polygonShape(main), style: baseStyle },
            { type: 'polygon', shape: polygonShape(cap), style: edgeStyle }
        ];
    }
    if (styleKey === 'hexagon' || styleKey === 'prism') {
        const d = Math.min(isHorizontal ? shape.height * 0.42 : shape.width * 0.42, 14);
        const points = isHorizontal
            ? [[x1 + d, y1], [x2 - d, y1], [x2, my], [x2 - d, y2], [x1 + d, y2], [x1, my]]
            : [[mx, y1], [x2, y1 + d], [x2, y2 - d], [mx, y2], [x1, y2 - d], [x1, y1 + d]];
        const side = styleKey === 'prism'
            ? (isHorizontal ? [[x2 - d, y1], [x2, my], [x2 - d, y2], [mx, y2], [mx, y1]] : [[mx, y1], [x2, y1 + d], [x2, y2 - d], [mx, y2], [mx, my]])
            : null;
        return [
            { type: 'polygon', shape: polygonShape(points), style: baseStyle },
            ...(side ? [{ type: 'polygon', shape: polygonShape(side), style: edgeStyle }] : [])
        ];
    }
    if (styleKey === 'trapezoid' || styleKey === 'pyramid') {
        const narrow = styleKey === 'pyramid' ? 0.34 : 0.68;
        const offset = isHorizontal ? shape.height * (1 - narrow) / 2 : shape.width * (1 - narrow) / 2;
        const points = isHorizontal
            ? [[x1, y1 + offset], [x2, y1], [x2, y2], [x1, y2 - offset]]
            : [[x1 + offset, y1], [x2 - offset, y1], [x2, y2], [x1, y2]];
        return [{ type: 'polygon', shape: polygonShape(points), style: baseStyle }];
    }
    if (styleKey === 'stereoGroup') {
        const depthX = Math.max(5, Math.min(shape.width * 0.34, 14));
        const depthY = Math.max(5, Math.min(shape.height * 0.12, 14));
        const front = [[x1, y1 + depthY], [x2 - depthX, y1 + depthY], [x2 - depthX, y2], [x1, y2]];
        const side = [[x2 - depthX, y1 + depthY], [x2, y1], [x2, y2 - depthY], [x2 - depthX, y2]];
        const top = [[x1, y1 + depthY], [x1 + depthX, y1], [x2, y1], [x2 - depthX, y1 + depthY]];
        return [
            {
                name: 'stereo-front',
                type: 'polygon',
                shape: polygonShape(front),
                style: {
                    ...baseStyle,
                    shadowBlur: 10,
                    shadowColor: glowColor
                }
            },
            {
                name: 'stereo-side',
                type: 'polygon',
                shape: polygonShape(side),
                style: {
                    fill: tailColor,
                    opacity: 0.72
                }
            },
            {
                name: 'stereo-top',
                type: 'polygon',
                shape: polygonShape(top),
                style: {
                    fill: glowColor,
                    opacity: 0.56
                }
            }
        ];
    }
    if (styleKey === 'battery') {
        const wallX = Math.max(2, shape.width * 0.14);
        const wallY = Math.max(3, shape.height * 0.08);
        const inner = {
            x: shape.x + wallX,
            y: shape.y + wallY,
            width: Math.max(2, shape.width - wallX * 2),
            height: Math.max(2, shape.height - wallY * 2)
        };
        const segmentCount = 4;
        const shellRadius = Math.max(3, Math.min(shape.width, shape.height) * 0.16);
        const shellStroke = 'rgba(214, 247, 255, 0.92)';
        const shellFill = new echarts.graphic.LinearGradient(
            0, 0, isHorizontal ? 1 : 0, isHorizontal ? 0 : 1,
            [
                { offset: 0, color: 'rgba(7, 23, 54, 0.88)' },
                { offset: 0.55, color: 'rgba(5, 18, 45, 0.68)' },
                { offset: 1, color: 'rgba(7, 25, 56, 0.92)' }
            ]
        );
        const segmentFill = new echarts.graphic.LinearGradient(
            0, isHorizontal ? 0 : 1, isHorizontal ? 1 : 0, isHorizontal ? 0 : 0,
            [
                { offset: 0, color: 'rgba(210, 255, 185, 0.95)' },
                { offset: 0.42, color: glowColor },
                { offset: 1, color: tailColor }
            ]
        );
        const segments = Array.from({ length: segmentCount }).map((_, segmentIndex) => {
            if (isHorizontal) {
                const gap = Math.max(2, Math.min(inner.width * 0.045, 6));
                const width = Math.max(1, (inner.width - gap * (segmentCount - 1)) / segmentCount);
                return {
                    name: 'battery-segment',
                    type: 'rect',
                    shape: {
                        x: inner.x + segmentIndex * (width + gap),
                        y: inner.y,
                        width,
                        height: inner.height,
                        r: Math.min(5, inner.height * 0.22)
                    },
                    style: {
                        fill: segmentFill,
                        opacity: 0.86,
                        shadowBlur: 10,
                        shadowColor: glowColor
                    }
                };
            }
            const gap = Math.max(2, Math.min(inner.height * 0.055, 6));
            const height = Math.max(1, (inner.height - gap * (segmentCount - 1)) / segmentCount);
            return {
                name: 'battery-segment',
                type: 'rect',
                shape: {
                    x: inner.x,
                    y: inner.y + (segmentCount - 1 - segmentIndex) * (height + gap),
                    width: inner.width,
                    height,
                    r: Math.min(5, inner.width * 0.22)
                },
                style: {
                    fill: segmentFill,
                    opacity: 0.86,
                    shadowBlur: 10,
                    shadowColor: glowColor
                }
            };
        });
        const head = isHorizontal
            ? {
                x: x2 - 1,
                y: y1 + shape.height * 0.32,
                width: Math.max(4, Math.min(shape.height * 0.24, 10)),
                height: shape.height * 0.36,
                r: 2
            }
            : {
                x: x1 + shape.width * 0.3,
                y: y1 - Math.max(4, Math.min(shape.width * 0.24, 10)) + 1,
                width: shape.width * 0.4,
                height: Math.max(4, Math.min(shape.width * 0.24, 10)),
                r: 2
            };
        const gloss = isHorizontal
            ? { x: shape.x + shape.width * 0.08, y: shape.y + shape.height * 0.16, width: Math.max(2, shape.width * 0.18), height: Math.max(1, shape.height * 0.06), r: 2 }
            : { x: shape.x + shape.width * 0.16, y: shape.y + shape.height * 0.08, width: Math.max(1, shape.width * 0.08), height: Math.max(2, shape.height * 0.18), r: 2 };
        return [
            {
                name: 'battery-terminal',
                type: 'rect',
                shape: head,
                style: {
                    fill: 'rgba(186, 238, 255, 0.36)',
                    stroke: shellStroke,
                    lineWidth: 1.4,
                    shadowBlur: 10,
                    shadowColor: glowColor
                }
            },
            {
                name: 'battery-shell',
                type: 'rect',
                shape: { ...shape, r: shellRadius },
                style: {
                    fill: shellFill,
                    stroke: shellStroke,
                    lineWidth: 1.8,
                    shadowBlur: 18,
                    shadowColor: glowColor
                }
            },
            {
                name: 'battery-chamber',
                type: 'rect',
                shape: { ...inner, r: Math.max(3, Math.min(inner.width, inner.height) * 0.12) },
                style: {
                    fill: 'rgba(2, 12, 34, 0.62)',
                    stroke: 'rgba(88, 210, 255, 0.22)',
                    lineWidth: 1
                }
            },
            ...segments
            ,
            {
                name: 'battery-gloss',
                type: 'rect',
                shape: gloss,
                style: {
                    fill: 'rgba(255, 255, 255, 0.42)',
                    opacity: 0.5,
                    shadowBlur: 8,
                    shadowColor: '#ffffff'
                }
            }
        ];
    }
    return [{ type: 'rect', shape, style: baseStyle }];
};

const buildBarShapeSeries = (series, option, preset, index, styleKey) => {
    const { data, isHorizontal, categoryData, maxBarWidth, glowColor, tailColor } = getBarShapeContext(series, option, index, preset);
    if (data.length === 0 || !styleKey || styleKey === 'original') return null;
    return {
        name: `${series.name || 'bar'}-${styleKey}-shape`,
        type: 'custom',
        coordinateSystem: 'cartesian2d',
        data: getBarSeriesData(data, categoryData, isHorizontal),
        silent: true,
        z: 70,
        tooltip: { show: false },
        renderItem: (params, api) => {
            const clippedShape = getBarShapeFromApi(api, params, isHorizontal, maxBarWidth);
            if (!clippedShape || clippedShape.width <= 0 || clippedShape.height <= 0) return null;
            const group = {
                type: 'group',
                children: buildBarShapeChildren(clippedShape, isHorizontal, styleKey, glowColor, tailColor)
            };
            if (['rounded', 'cylinder'].includes(styleKey)) {
                group.clipPath = { type: 'rect', shape: clippedShape };
            }
            return group;
        }
    };
};

const getMotionGraphicLayer = (mode, preset, cat) => {
    if (mode === 'pulse') {
        const isRoundChart = cat === 'gauge' || cat === 'pie' || cat === 'huan' || cat === 'alarmpie';
        if (isRoundChart) {
            return [{
                name: 'chart-motion-pulse',
                type: 'ring',
                left: 'center',
                top: 'middle',
                silent: true,
                z: -3,
                shape: { r: 104, r0: 99 },
                style: {
                    stroke: preset.axis,
                    fill: 'transparent',
                    opacity: 0.12,
                    lineWidth: 2,
                    shadowBlur: 18,
                    shadowColor: preset.shadow
                },
                keyframeAnimation: {
                    duration: 1800,
                    loop: true,
                    keyframes: [
                        { percent: 0, scaleX: 0.92, scaleY: 0.92, style: { opacity: 0.08 } },
                        { percent: 0.5, scaleX: 1.08, scaleY: 1.08, style: { opacity: 0.34 } },
                        { percent: 1, scaleX: 0.92, scaleY: 0.92, style: { opacity: 0.08 } }
                    ]
                }
            }];
        }
        return [{
            name: 'chart-motion-pulse',
            type: 'rect',
            left: 6,
            top: 6,
            right: 6,
            bottom: 6,
            silent: true,
            z: 80,
            style: {
                fill: 'rgba(120, 230, 255, 0.035)',
                stroke: preset.axis,
                lineWidth: 2,
                opacity: 0.26,
                shadowBlur: 18,
                shadowColor: preset.shadow
            },
            keyframeAnimation: {
                duration: 1800,
                loop: true,
                keyframes: [
                    { percent: 0, scaleX: 0.985, scaleY: 0.985, style: { opacity: 0.10, shadowBlur: 8 } },
                    { percent: 0.5, scaleX: 1.015, scaleY: 1.015, style: { opacity: 0.42, shadowBlur: 34 } },
                    { percent: 1, scaleX: 0.985, scaleY: 0.985, style: { opacity: 0.10, shadowBlur: 8 } }
                ]
            }
        }];
    }
    if (mode === 'flow') {
        return [{
            name: 'chart-motion-flow',
            type: 'rect',
            left: -140,
            top: 0,
            width: 90,
            bottom: 0,
            silent: true,
            z: 30,
            style: {
                fill: {
                    type: 'linear',
                    x: 0,
                    y: 0,
                    x2: 1,
                    y2: 0,
                    colorStops: [
                        { offset: 0, color: 'rgba(255, 255, 255, 0)' },
                        { offset: 0.5, color: 'rgba(120, 230, 255, 0.18)' },
                        { offset: 1, color: 'rgba(255, 255, 255, 0)' }
                    ]
                },
                opacity: 0.7
            },
            keyframeAnimation: {
                duration: 2600,
                loop: true,
                keyframes: [
                    { percent: 0, x: -140, style: { opacity: 0 } },
                    { percent: 0.12, x: -60, style: { opacity: 0.7 } },
                    { percent: 0.86, x: 520, style: { opacity: 0.7 } },
                    { percent: 1, x: 620, style: { opacity: 0 } }
                ]
            }
        }];
    }
    return [];
};

const isPieLikeChart = (cat) => cat === 'pie' || cat === 'huan' || cat === 'alarmpie';

const getPercentNumber = (value, fallback) => {
    const match = String(value || '').match(/^(-?\d+(?:\.\d+)?)%$/);
    return match ? Number(match[1]) : fallback;
};

const getPieMotionOuterRadius = (chartInfo = {}) => {
    if (chartInfo.roseSwitch === '2') return chartInfo.iconSwitch === '2' ? 82 : 100;
    const radiusPercent = chartInfo.iconSwitch === '2' ? 58 : 70;
    const chartWidth = getPositiveNumber(chartInfo.width, 380);
    const chartHeight = getPositiveNumber(chartInfo.height, 250);
    return Math.round((radiusPercent / 100) * (Math.min(chartWidth, chartHeight) / 2));
};

const getPieMotionCenterPoint = (chartInfo = {}) => {
    const center = chartInfo.iconSwitch === '2' ? pieBottomLegendCenter : ['50%', '50%'];
    const chartWidth = getPositiveNumber(chartInfo.width, 380);
    const chartHeight = getPositiveNumber(chartInfo.height, 250);
    return [
        Math.round((getPercentNumber(center[0], 50) / 100) * chartWidth),
        Math.round((getPercentNumber(center[1], 50) / 100) * chartHeight)
    ];
};

const getPieMotionGraphicLayer = (mode, preset, chartInfo = {}) => {
    const [motionCx, motionCy] = getPieMotionCenterPoint(chartInfo);
    const motionOuterRadius = getPieMotionOuterRadius(chartInfo);
    if (mode === 'entrance') {
        return [{
            name: 'pie-motion-entrance',
            type: 'ring',
            origin: [motionCx, motionCy],
            silent: true,
            z: 86,
            shape: { cx: motionCx, cy: motionCy, r: motionOuterRadius + 4, r0: motionOuterRadius },
            style: {
                stroke: preset.axis,
                fill: 'transparent',
                opacity: 0.2,
                lineWidth: 2,
                shadowBlur: 22,
                shadowColor: preset.shadow
            },
            keyframeAnimation: {
                duration: 1200,
                loop: false,
                keyframes: [
                    { percent: 0, scaleX: 0.4, scaleY: 0.4, style: { opacity: 0, shadowBlur: 2 } },
                    { percent: 0.55, scaleX: 1.12, scaleY: 1.12, style: { opacity: 0.54, shadowBlur: 30 } },
                    { percent: 1, scaleX: 1, scaleY: 1, style: { opacity: 0.16, shadowBlur: 14 } }
                ]
            }
        }];
    }
    if (mode === 'pulse') {
        return [{
            name: 'pie-motion-pulse',
            type: 'ring',
            origin: [motionCx, motionCy],
            silent: true,
            z: 86,
            shape: { cx: motionCx, cy: motionCy, r: motionOuterRadius + 7, r0: Math.max(0, motionOuterRadius - 7) },
            style: {
                stroke: preset.axis,
                fill: 'transparent',
                opacity: 0.16,
                lineWidth: 2,
                shadowBlur: 20,
                shadowColor: preset.shadow
            },
            keyframeAnimation: {
                duration: 1700,
                loop: true,
                keyframes: [
                    { percent: 0, scaleX: 0.94, scaleY: 0.94, style: { opacity: 0.08, shadowBlur: 8 } },
                    { percent: 0.5, scaleX: 1.1, scaleY: 1.1, style: { opacity: 0.42, shadowBlur: 36 } },
                    { percent: 1, scaleX: 0.94, scaleY: 0.94, style: { opacity: 0.08, shadowBlur: 8 } }
                ]
            }
        }];
    }
    if (mode === 'flow') {
        return [{
            name: 'pie-motion-flow',
            type: 'group',
            origin: [motionCx, motionCy],
            silent: true,
            z: 88,
            children: [
                {
                    name: 'pie-motion-flow-bounds',
                    type: 'circle',
                    shape: {
                        cx: motionCx,
                        cy: motionCy,
                        r: motionOuterRadius
                    },
                    style: {
                        fill: 'transparent',
                        stroke: 'transparent',
                        opacity: 0
                    }
                },
                {
                    name: 'pie-motion-flow-arc',
                    type: 'arc',
                    shape: {
                        cx: motionCx,
                        cy: motionCy,
                        r: motionOuterRadius,
                        startAngle: -Math.PI * 0.18,
                        endAngle: Math.PI * 0.34,
                        clockwise: true
                    },
                    style: {
                        stroke: preset.label,
                        fill: null,
                        opacity: 0.78,
                        lineWidth: 5,
                        lineCap: 'round',
                        shadowBlur: 22,
                        shadowColor: preset.shadow
                    }
                },
                {
                    name: 'pie-motion-flow-tail',
                    type: 'arc',
                    shape: {
                        cx: motionCx,
                        cy: motionCy,
                        r: motionOuterRadius,
                        startAngle: -Math.PI * 0.28,
                        endAngle: -Math.PI * 0.02,
                        clockwise: true
                    },
                    style: {
                        stroke: preset.axis,
                        fill: null,
                        opacity: 0.22,
                        lineWidth: 2,
                        lineCap: 'round',
                        shadowBlur: 12,
                        shadowColor: preset.shadow
                    }
                }
            ],
            keyframeAnimation: {
                duration: 1800,
                loop: true,
                keyframes: [
                    { percent: 0, rotation: 0, style: { opacity: 0 } },
                    { percent: 0.16, rotation: Math.PI * 0.45, style: { opacity: 0.78 } },
                    { percent: 0.84, rotation: Math.PI * 1.75, style: { opacity: 0.78 } },
                    { percent: 1, rotation: Math.PI * 2, style: { opacity: 0 } }
                ]
            }
        }];
    }
    return [];
};

const withAnimationStyle = (option, chartInfo, preset) => {
    const mode = getAnimationMode(chartInfo);
    const barStyleKey = chartInfo.chartBarStyle || 'original';
    if (mode === 'off') {
        option.animation = false;
        option.animationDuration = 0;
        option.animationDurationUpdate = 0;
        option.stateAnimation = { duration: 0 };
        return;
    }

    option.animation = true;
    option.animationDuration = mode === 'entrance' ? 1200 : 900;
    option.animationDurationUpdate = mode === 'entrance' ? 900 : 1300;
    option.animationEasing = 'cubicOut';
    option.animationEasingUpdate = 'cubicInOut';
    option.stateAnimation = { duration: 450, easing: 'cubicOut' };

    const flowSeries = [];
    const lineMotionSeries = [];
    const barMotionSeries = [];
    option.series = (option.series || []).map((series, index) => {
        if (series.type === 'line' && mode === 'flow') {
            const lineFlowSeries = buildLineFlowSeries(series, option, preset, index);
            if (lineFlowSeries) flowSeries.push(lineFlowSeries);
            return series;
        }
        if (series.type === 'line' && (mode === 'entrance' || mode === 'pulse')) {
            const lineMotion = buildLineMotionSeries(series, option, preset, index, mode);
            if (lineMotion) lineMotionSeries.push(lineMotion);
            return {
                ...series,
                animation: true,
                animationDuration: mode === 'entrance' ? 1200 : 900,
                animationEasing: mode === 'entrance' ? 'cubicOut' : 'cubicInOut',
                lineStyle: {
                    ...(series.lineStyle || {}),
                    shadowBlur: Math.max(((series.lineStyle || {}).shadowBlur || 0), mode === 'pulse' ? 20 : 12),
                    shadowColor: (series.lineStyle || {}).shadowColor || preset.colors[index % preset.colors.length]
                }
            };
        }
        if (series.type === 'bar' && (mode === 'pulse' || mode === 'entrance' || mode === 'flow')) {
            const barMotion = buildBarMotionSeries(series, option, preset, index, mode, barStyleKey);
            if (barMotion) barMotionSeries.push(barMotion);
            const baseItemStyle = series.itemStyle || {};
            const isHiddenBarCarrier = baseItemStyle.opacity === 0 && baseItemStyle.color === 'rgba(0, 0, 0, 0)';
            return {
                ...series,
                animation: true,
                animationDelay: (dataIndex) => mode === 'entrance' ? dataIndex * 80 : 0,
                animationDuration: mode === 'entrance' ? 1000 : 900,
                animationEasing: mode === 'entrance' ? 'elasticOut' : 'cubicOut',
                itemStyle: {
                    ...baseItemStyle,
                    shadowBlur: isHiddenBarCarrier ? 0 : Math.max((baseItemStyle.shadowBlur || 0), mode === 'flow' ? 18 : 24),
                    shadowColor: isHiddenBarCarrier ? 'rgba(0, 0, 0, 0)' : (baseItemStyle.shadowColor || preset.colors[index % preset.colors.length])
                }
            };
        }
        if (series.type === 'gauge' && mode === 'pulse') {
            return {
                ...series,
                detail: {
                    ...(series.detail || {}),
                    valueAnimation: true
                },
                progress: {
                    ...(series.progress || {}),
                    itemStyle: {
                        ...((series.progress || {}).itemStyle || {}),
                        shadowBlur: Math.max((((series.progress || {}).itemStyle || {}).shadowBlur || 0), 26),
                        shadowColor: preset.shadow
                    }
                }
            };
        }
        if (series.type === 'pie' && (mode === 'pulse' || mode === 'entrance' || mode === 'flow')) {
            return {
                ...series,
                animation: true,
                animationType: mode === 'flow' ? 'expansion' : 'scale',
                animationDuration: mode === 'entrance' ? 1200 : 1000,
                animationEasing: mode === 'flow' ? 'cubicOut' : 'elasticOut'
            };
        }
        return series;
    });
    if (flowSeries.length > 0) {
        option.series = option.series.concat(flowSeries);
    }
    if (lineMotionSeries.length > 0) {
        option.series = option.series.concat(lineMotionSeries);
    }
    if (barMotionSeries.length > 0) {
        option.series = option.series.concat(barMotionSeries);
    }
    const motionGraphics = isPieLikeChart(chartInfo.cat)
        ? getPieMotionGraphicLayer(mode, preset, chartInfo)
        : getMotionGraphicLayer(mode, preset, chartInfo.cat);
    if (motionGraphics.length > 0) {
        option.graphic = [
            ...(option.graphic || []),
            ...motionGraphics
        ];
    }
};

const styleLineSeries = (series, preset, index) => {
    const hasDataLabel = !!(series.label && series.label.show);
    return {
        ...series,
        smooth: true,
        showSymbol: hasDataLabel ? true : false,
        symbol: 'circle',
        symbolSize: hasDataLabel ? Math.max(Number(series.symbolSize || 0), 7) : 7,
        ...(series.label ? { label: styleDataLabel(series.label, preset) } : {}),
        lineStyle: {
            ...(series.lineStyle || {}),
            width: 3,
            color: preset.colors[index % preset.colors.length],
            shadowBlur: 14,
            shadowColor: preset.colors[index % preset.colors.length],
            shadowOffsetY: 0
        },
        areaStyle: {
            ...(series.areaStyle || {}),
            opacity: 0.22,
            color: gradientColor(preset.colors[index % preset.colors.length], 'rgba(5, 20, 42, 0)')
        },
        emphasis: {
            ...(series.emphasis || {}),
            focus: 'series'
        }
    };
};

const stylePieSeries = (series, preset) => ({
    ...series,
    avoidLabelOverlap: true,
    itemStyle: {
        ...(series.itemStyle || {}),
        borderColor: getPieBorderColor(preset, 0.46),
        borderWidth: 1.6,
        shadowBlur: 18,
        shadowColor: preset.shadow
    },
    label: {
        ...(series.label || {}),
        color: preset.label,
        fontWeight: 600
    },
    labelLine: {
        ...(series.labelLine || {}),
        lineStyle: {
            ...((series.labelLine || {}).lineStyle || {}),
            color: preset.axis
        }
    },
    emphasis: {
        ...(series.emphasis || {}),
        scale: true,
        scaleSize: 8,
        itemStyle: {
            ...((series.emphasis || {}).itemStyle || {}),
            borderColor: getPieBorderColor(preset, 0.68),
            borderWidth: 2,
            shadowBlur: 28,
            shadowColor: preset.shadow
        }
    }
});

const styleGaugeSeries = (series, preset) => ({
    ...series,
    radius: series.radius || '82%',
    progress: {
        ...(series.progress || {}),
        show: true,
        width: 12,
        itemStyle: {
            color: gradientColor(preset.colors[0], preset.colors[1]),
            shadowBlur: 18,
            shadowColor: preset.shadow
        }
    },
    pointer: {
        ...(series.pointer || {}),
        length: '62%',
        width: 5,
        itemStyle: {
            ...((series.pointer || {}).itemStyle || {}),
            color: preset.colors[0],
            shadowBlur: 16,
            shadowColor: preset.shadow
        }
    },
    axisLine: {
        ...(series.axisLine || {}),
        lineStyle: {
            ...((series.axisLine || {}).lineStyle || {}),
            width: 14,
            color: [
                [0.35, 'rgba(32, 247, 255, 0.28)'],
                [0.75, 'rgba(54, 242, 183, 0.42)'],
                [1, 'rgba(255, 209, 102, 0.55)']
            ],
            shadowBlur: 12,
            shadowColor: preset.shadow
        }
    },
    splitLine: {
        ...(series.splitLine || {}),
        distance: -18,
        length: 12,
        lineStyle: {
            ...((series.splitLine || {}).lineStyle || {}),
            color: preset.axis,
            width: 2,
            shadowBlur: 8,
            shadowColor: preset.shadow
        }
    },
    axisTick: {
        ...(series.axisTick || {}),
        distance: -14,
        length: 6,
        lineStyle: {
            ...((series.axisTick || {}).lineStyle || {}),
            color: preset.axis,
            width: 1
        }
    },
    axisLabel: {
        ...(series.axisLabel || {}),
        color: preset.label,
        distance: 18
    },
    detail: {
        ...(series.detail || {}),
        color: preset.label,
        fontWeight: 700,
        textShadowColor: preset.shadow,
        textShadowBlur: 16
    },
    title: {
        ...(series.title || {}),
        color: preset.label,
        textShadowColor: preset.shadow,
        textShadowBlur: 8
    }
});

export const applyChartVisualStyle = (option, chartInfo = {}) => {
    const styleKey = chartInfo.chartStyle || chartInfo.chartThemeStyle || 'original';
    const animationMode = getAnimationMode(chartInfo);
    const hasVisualStyle = !!(styleKey && styleKey !== 'original' && chartStylePresets[styleKey]);
    const barStyleKey = chartInfo.chartBarStyle || 'original';
    const hasBarShapeStyle = chartInfo.cat === 'bar' && barStyleKey !== 'original';
    if (!hasVisualStyle && animationMode === 'off' && !hasBarShapeStyle) return option;

    const styled = cloneOption(option);
    const preset = hasVisualStyle ? chartStylePresets[styleKey] : defaultMotionPreset;
    const cat = chartInfo.cat;
    if (hasVisualStyle) {
        withCommonStyle(styled, preset);

        styled.series = (styled.series || []).map((series, index) => {
            if (cat === 'gauge' || series.type === 'gauge') return styleGaugeSeries(series, preset);
            if (cat === 'bar' || series.type === 'bar') {
                return chartInfo.sortEnable === '2'
                    ? styleSortedBarSeries(series, preset, index)
                    : styleBarSeries(series, preset, index);
            }
            if (cat === 'line' || series.type === 'line') return styleLineSeries(series, preset, index);
            if (cat === 'pie' || cat === 'huan' || cat === 'alarmpie' || series.type === 'pie') return stylePieSeries(series, preset);
            return series;
        });

        if (cat === 'gauge') {
            styled.graphic = [
                ...getGaugeGraphicLayer(preset),
                ...(styled.graphic || [])
            ];
        }
    }
    if (hasBarShapeStyle) {
        const barShapeSeries = [];
        styled.series = (styled.series || []).map((series, index) => {
            if (series.type !== 'bar') return series;
            const shapeSeries = buildBarShapeSeries(series, styled, preset, index, barStyleKey);
            if (shapeSeries) barShapeSeries.push(shapeSeries);
            return {
                ...series,
                showBackground: false,
                backgroundStyle: getHiddenBarBackgroundStyle(series.backgroundStyle || {}),
                data: hideBarDataBackground(series.data || []),
                itemStyle: {
                    ...(series.itemStyle || {}),
                    ...transparentBarStyle,
                    opacity: 0,
                },
                emphasis: {
                    ...(series.emphasis || {}),
                    itemStyle: {
                        ...((series.emphasis || {}).itemStyle || {}),
                        ...transparentBarStyle,
                        opacity: 0,
                    }
                }
            };
        });
        if (barShapeSeries.length > 0) {
            styled.series = styled.series.concat(barShapeSeries);
        }
    }
    withAnimationStyle(styled, chartInfo, preset);

    if (cat === 'bar' && (hasBarShapeStyle || animationMode !== 'off')) {
        const maxSeriesZ = Math.max(0, ...(styled.series || []).map(series => Number(series.z || 0)));
        const labelCarrierSeries = (styled.series || [])
            .filter(series => series.type === 'bar' && hasVisibleBarLabel(series))
            .map((series, index) => buildBarLabelCarrierSeries(series, index, maxSeriesZ + 20 + index));
        if (labelCarrierSeries.length > 0) {
            styled.series = styled.series.concat(labelCarrierSeries);
        }
    }

    return styled;
};

// Comment translated to English.
const getAlarmData = async (element, chartInfo, alarmDataList) => {
    var alarmpieChart = initChart(element);
    bindChartAutoResize(alarmpieChart, element);
    // let res = await httpsend.getData('GroupAlarmStatisticKey', {
    //     "startDateTime": "1970-01-01 00:00:00",
    //     "endDateTime": "2099-12-31 23:59:59",
    //     "type": "level",
    //     "ServerCode": '',
    // })
    let alarmdata = [
        {name: t('auto.k0138'), value: 0},
        {name: t('auto.k0139'), value: 0},
        {name: t('auto.k0140'), value: 0},
        {name: t('auto.k0141'), value: 0},
        {name: t('auto.k0142'), value: 0}
    ];
    if (alarmDataList && Array.isArray(alarmDataList.data)) {
        const levelCount = { '1': 0, '2': 0, '3': 0, '4': 0, '5': 0 };
        alarmDataList.data.forEach((item) => {
            const rawLevel = (
                item && item.AlarmLevel !== undefined ? item.AlarmLevel
                    : (item && item.alarmLevel !== undefined ? item.alarmLevel : (item ? item.level : undefined))
            );
            const level = rawLevel === undefined || rawLevel === null ? '' : String(rawLevel).trim();
            if (Object.prototype.hasOwnProperty.call(levelCount, level)) {
                levelCount[level] += 1;
            }
        });
        alarmdata = [
            { name: t('auto.k0138'), value: levelCount['1'] },
            { name: t('auto.k0139'), value: levelCount['2'] },
            { name: t('auto.k0140'), value: levelCount['3'] },
            { name: t('auto.k0141'), value: levelCount['4'] },
            { name: t('auto.k0142'), value: levelCount['5'] }
        ];
    }
    // const seriesAlarmData = alarmdata.filter((item) => Number(item.value) !== 0);
    const seriesAlarmData = alarmdata;
    let data = [
        {
            type: 'pie',
            radius: '70%',
            data: seriesAlarmData,
            label: {
                position: 'inside',
                color: chartInfo.dataColor
            },
        }
    ];
    if (chartInfo.roseSwitch === '2') {
        if (data) {
            data.forEach(ele => {
                ele['radius'] = [20, 100];
                ele['roseType'] = 'radius';
                ele['itemStyle'] = {
                    borderRadius: 5
                };
            })
        }
    }
    var alarmpieoption = {
        color: ['#35DFFC', '#3783FF', '#FFCC37', '#FF9000', '#F96055'],
        tooltip: {
            trigger: 'item'
        },
        title: {// Comment translated to English.
            show: chartInfo.titleSwitch === '2' ? true : false,
            text: chartInfo.titleSwitch === '2' ? chartInfo.title : '',
            textStyle: {
                color: chartInfo.titleColor,
                fontSize: chartInfo.titleFontSize
            }
        },
        legend: {// Comment translated to English.
            show: chartInfo.iconSwitch === '2' ? true : false,
            right: 10,
            textStyle: {
                color: chartInfo.iconColor
            },
            orient: chartInfo.orient,
            algin: chartInfo.algin
        },
        series: data
    };
    safeSetOption(alarmpieChart, applyChartVisualStyle(alarmpieoption, chartInfo));
}
// Comment translated to English.
function echart(images, selectedId,alarmData) {
    setTimeout(() => {
        if (!document.querySelectorAll(".chart")) return;
        let chartlist = document.querySelectorAll(".chart");
        for (var i = 0; i < chartlist.length; i++) {
            var element = chartlist[i];
            let findimg = getImgIndex(images, element);
            if (findimg > -1 && images[findimg] && images[findimg].id) {
                // Comment translated to English.
                const moduleJson = images[findimg].moduleJson;
                const firstChild = moduleJson && Array.isArray(moduleJson.children) ? moduleJson.children[0] : null;
                if (!firstChild || !firstChild.attrs) continue;
                let chartInfo = firstChild.attrs;
                if (chartInfo.cat === 'gauge') {// Comment translated to English.
                    var gaugeChart = initChart(element);
                    bindChartAutoResize(gaugeChart, element);
                    var gaugeoption = {
                        grid: {
                            left: 0,
                            top: 0,
                            bottom: 0,
                            right: 0
                        },
                        series: [
                            {
                                type: 'gauge',
                                max: chartInfo.max,
                                min: chartInfo.min,
                                progress: {// Comment translated to English.
                                    show: chartInfo.progressSwitch === '2' ? true : false
                                },
                                pointer: { // Comment translated to English.
                                    itemStyle: {
                                        color: chartInfo.progressSwitch === '2' ? chartInfo.progressColor : 'auto',
                                    }
                                },
                                axisTick: {// Comment translated to English.
                                    distance: 0,
                                    length: 3,
                                    lineStyle: {
                                        color: chartInfo.markColor,
                                        width: 1
                                    }
                                },
                                axisLabel: {// Comment translated to English.
                                    color: chartInfo.markFontColor,
                                },
                                itemStyle: {// Comment translated to English.
                                    color: chartInfo.progressSwitch === '2' ? chartInfo.progressColor : 'auto',
                                },
                                axisLine: {// Comment translated to English.
                                    lineStyle: {
                                        color: [
                                            [chartInfo.axisLine1, chartInfo.axisLineColor1],
                                            [chartInfo.axisLine2, chartInfo.axisLineColor2],
                                            [chartInfo.axisLine3, chartInfo.axisLineColor3],
                                            [chartInfo.axisLine4, chartInfo.axisLineColor4],
                                        ]
                                    }
                                },
                                splitLine: {// Comment translated to English.
                                    distance: 0,
                                    length: 5,
                                    lineStyle: {
                                        color: chartInfo.markColor,
                                        width: 1
                                    }
                                },
                                title: {
                                    offsetCenter: [0, '100%'],
                                    fontSize: chartInfo.titleFontSize,
                                    color: chartInfo.titleColor,
                                },
                                detail: {
                                    valueAnimation: true,
                                    formatter: '{value}',
                                    color: chartInfo.dataColor,
                                    fontSize: chartInfo.dataFontSize,
                                    offsetCenter: [0, '70%']
                                },
                                data: [
                                    {
                                        value: chartInfo.data,
                                        name: chartInfo.titleSwitch === '2' ? chartInfo.title : ""
                                    }
                                ]
                            }
                        ]
                    };
                    safeSetOption(gaugeChart, applyChartVisualStyle(gaugeoption, chartInfo));
                } else if (chartInfo.cat === 'line') {// Comment translated to English.
                    var lineChart = initChart(element);
                    bindChartAutoResize(lineChart, element);
                    let top = 20;
                    let data = chartInfo.data;
                    let legenddata = [];
                    if (data) {
                        data.forEach(element => {
                            legenddata.push(element.name)
                        });
                    }
                    if (chartInfo.titleSwitch === '2') top = 50;
                    if (chartInfo.iconSwitch === '2') top = 50;
                    // Comment translated to English.
                    if (chartInfo.dataSwitch === '2') {
                        if (data) {
                            data.forEach(element => {
                                element['label'] = {
                                    show: true,
                                    position: 'top',
                                    color: chartInfo.dataColor,
                                    fontSize: chartInfo.dataFontSize
                                }
                            });
                        }
                    }
                    // Comment translated to English.
                    if (chartInfo.areaSwitch === '2') {
                        if (data) {
                            data.forEach(element => {
                                element['areaStyle'] = {}
                            });
                        }
                    }
                    var lineoption = {
                        grid: {
                            top: top,
                            left: 10,
                            right: 20,
                            bottom: 10,
                            containLabel: true,
                        },
                        tooltip: {
                            trigger: 'axis'
                        },
                        title: {// Comment translated to English.
                            show: chartInfo.titleSwitch === '2' ? true : false,
                            text: chartInfo.titleSwitch === '2' ? chartInfo.title : '',
                            textStyle: {
                                color: chartInfo.titleColor,
                                fontSize: chartInfo.titleFontSize
                            }
                        },
                        legend: {// Comment translated to English.
                            show: chartInfo.iconSwitch === '2' ? true : false,
                            data: legenddata,
                            right: 10,
                            textStyle: {
                                color: chartInfo.iconColor
                            }
                        },
                        xAxis: {
                            axisLine: {// Comment translated to English.
                                lineStyle: {
                                    color: chartInfo.xaxisLine
                                }
                            },
                            axisTick: {// Comment translated to English.
                                lineStyle: {
                                    color: chartInfo.markColor
                                }
                            },
                            axisLabel: {// Comment translated to English.
                                show: chartInfo.xTextShow === '2' ? true : false,
                                textStyle: {
                                    color: chartInfo.xColor,
                                }
                            },
                            show: chartInfo.xshow === '2' ? true : false,
                            type: 'category',
                            boundaryGap: true,// Comment translated to English.
                            data: chartInfo.xdata
                        },
                        yAxis: {
                            axisLine: {// Comment translated to English.
                                show: chartInfo.yshow === '2' ? true : false,
                                lineStyle: {
                                    color: chartInfo.yaxisLine
                                }
                            },
                            axisTick: {// Comment translated to English.
                                lineStyle: {
                                    color: chartInfo.markColor
                                }
                            },
                            axisLabel: {// Comment translated to English.
                                textStyle: {
                                    color: chartInfo.yColor,
                                }
                            },
                            splitLine: {
                                show: chartInfo.splitSwitch === '2' ? true : false,
                                lineStyle: {
                                    color: chartInfo.splitColor
                                }
                            },
                            type: 'value'
                        },
                        series: data
                    };

                    safeSetOption(lineChart, applyChartVisualStyle(lineoption, chartInfo));
                } else if (chartInfo.cat === 'bar') {// Comment translated to English.
                    var barChart = initChart(element);
                    bindChartAutoResize(barChart, element);
                    let top = 20;
                    let sourceData = Array.isArray(chartInfo.data) ? chartInfo.data : [];
                    let data = sourceData.map((series) => ({
                        ...series,
                        data: Array.isArray(series.data) ? [...series.data] : []
                    }));
                    let xdata = Array.isArray(chartInfo.xdata) ? [...chartInfo.xdata] : [];
                    const shouldSortBar = chartInfo.sortEnable === '2' && data.length > 0 && xdata.length > 0;
                    if (shouldSortBar) {
                        const sortSeriesIndex = chartInfo.sortTarget === 'first' ? 0 : parseInt(chartInfo.sortTarget, 10) || 0;
                        const safeSortSeriesIndex = sortSeriesIndex >= 0 && sortSeriesIndex < data.length ? sortSeriesIndex : 0;
                        const baseSeries = data[safeSortSeriesIndex];
                        const rows = xdata.map((name, index) => ({
                            name,
                            index,
                            sortValue: Number(baseSeries && Array.isArray(baseSeries.data) ? baseSeries.data[index] : 0) || 0
                        }));
                        rows.sort((a, b) => {
                            if (b.sortValue === a.sortValue) {
                                return a.index - b.index;
                            }
                            return chartInfo.sortOrder === 'asc' ? a.sortValue - b.sortValue : b.sortValue - a.sortValue;
                        });
                        // 显示前 N 个柱体：sortTopN > 0 时截取，否则保留全部
                        const topNRaw = parseInt(chartInfo.sortTopN, 10);
                        const topN = Number.isFinite(topNRaw) && topNRaw > 0 ? topNRaw : 0;
                        const limitedRows = topN > 0 ? rows.slice(0, topN) : rows;
                        xdata = limitedRows.map((row) => row.name);
                        data = data.map((series) => ({
                            ...series,
                            data: limitedRows.map((row) => {
                                const currentValue = Array.isArray(series.data) ? series.data[row.index] : 0;
                                return currentValue === undefined ? 0 : currentValue;
                            })
                        }));
                    }
                    let legenddata = [];
                    if (data) {
                        data.forEach(element => {
                            legenddata.push(element.name)
                        });
                    }
                    if (chartInfo.titleSwitch === '2') top = 50;
                    if (chartInfo.iconSwitch === '2') top = 50;
                    // Comment translated to English.
                    if (chartInfo.dataSwitch === '2') {
                        if (data) {
                            data.forEach(ele => {
                                ele['label'] = {
                                    show: true,
                                    position: getBarDataLabelPosition(chartInfo),
                                    color: chartInfo.dataColor,
                                    fontSize: chartInfo.dataFontSize
                                }
                            })
                        }
                    }
                    // Comment translated to English.
                    if (chartInfo.barbackgroundSwitch === '2') {
                        if (data) {
                            data.forEach(ele => {
                                ele['showBackground'] = true;
                                ele['backgroundStyle'] = {
                                    color: chartInfo.barbackground
                                }
                            })
                        }
                    }

                    const barAxisOption = buildBarAxisOption(chartInfo, xdata);
                    var baroption = {
                        grid: {
                            top: top,
                            left: 10,
                            right: 20,
                            bottom: 10,
                            containLabel: true,
                        },
                        tooltip: {
                            trigger: 'axis'
                        },
                        title: {// Comment translated to English.
                            show: chartInfo.titleSwitch === '2' ? true : false,
                            text: chartInfo.titleSwitch === '2' ? chartInfo.title : '',
                            textStyle: {
                                color: chartInfo.titleColor,
                                fontSize: chartInfo.titleFontSize
                            }
                        },
                        legend: {// Comment translated to English.
                            show: chartInfo.iconSwitch === '2' ? true : false,
                            data: legenddata,
                            right: 10,
                            textStyle: {
                                color: chartInfo.iconColor
                            }
                        },
                        xAxis: barAxisOption.xAxis,
                        yAxis: barAxisOption.yAxis,
                        series: data
                    };

                    safeSetOption(barChart, applyChartVisualStyle(baroption, chartInfo));
                } else if (chartInfo.cat === 'pie') {// Comment translated to English.
                    var pieChart = initChart(element);
                    bindChartAutoResize(pieChart, element);
                    let data = [buildPieSeriesOption(chartInfo)];
                    var pieoption = {
                        tooltip: {
                            trigger: 'item'
                        },
                        title: {// Comment translated to English.
                            show: chartInfo.titleSwitch === '2' ? true : false,
                            text: chartInfo.titleSwitch === '2' ? chartInfo.title : '',
                            textStyle: {
                                color: chartInfo.titleColor,
                                fontSize: chartInfo.titleFontSize
                            }
                        },
                        legend: buildPieLegendOption(chartInfo),
                        series: data
                    };

                    safeSetOption(pieChart, applyChartVisualStyle(pieoption, chartInfo));
                } else if (chartInfo.cat === 'waterBall') {
                    var waterBallChart = initChart(element);
                    bindChartAutoResize(waterBallChart, element);
                    safeSetOption(waterBallChart, buildWaterBallOption(chartInfo));
                } else if (chartInfo.cat === 'alarmpie') {// Comment translated to English.
                    getAlarmData(element, chartInfo, alarmData);
                } else if (chartInfo.cat === 'huan') {// Comment translated to English.
                    var huanChart = initChart(element);
                    bindChartAutoResize(huanChart, element);
                    var huanoption = {
                        tooltip: {
                            trigger: 'item'
                        },
                        title: {// Comment translated to English.
                            show: chartInfo.titleSwitch === '2' ? true : false,
                            text: chartInfo.titleSwitch === '2' ? chartInfo.title : '',
                            textStyle: {
                                color: chartInfo.titleColor,
                                fontSize: chartInfo.titleFontSize
                            }
                        },
                        legend: {// Comment translated to English.
                            show: chartInfo.iconSwitch === '2' ? true : false,
                            right: 10,
                            textStyle: {
                                color: chartInfo.iconColor
                            },
                            orient: chartInfo.orient,
                            algin: chartInfo.algin
                        },
                        series: [
                            {
                                type: 'pie',
                                radius: ['40%', '70%'],
                                avoidLabelOverlap: false,
                                label: {
                                    show: false,
                                    position: 'center'
                                },
                                emphasis: {
                                    label: {
                                        show: chartInfo.dataSwitch === '2' ? true : false,
                                        fontSize: chartInfo.datafontSize,
                                        fontWeight: 'bold',
                                        color: chartInfo.dataColor
                                    }
                                },
                                labelLine: {
                                    show: false
                                },
                                data: chartInfo.data
                            }
                        ]
                    };

                    safeSetOption(huanChart, applyChartVisualStyle(huanoption, chartInfo));
                } else if (chartInfo.cat === 'pue') {// Comment translated to English.
                    var pueChart = initChart(element);
                    bindChartAutoResize(pueChart, element);
                    var pueoption = {
                        grid: {
                            left: 0,
                            top: 0,
                            bottom: 0,
                            right: 0
                        },
                        series: [
                            {
                                type: 'gauge',
                                max: 10,
                                axisLine: {
                                    lineStyle: {
                                        width: 10,
                                        color: [
                                            [
                                                0.5,
                                                {
                                                    type: 'linear',
                                                    x: 0,
                                                    y: 0,
                                                    x2: 0,
                                                    y2: 1,
                                                    colorStops: [
                                                        {
                                                            offset: 0,
                                                            color: '#B19589'
                                                        },
                                                        {
                                                            offset: 1,
                                                            color: '#3550F7'
                                                        }
                                                    ],
                                                    global: false
                                                }
                                            ],
                                            [
                                                1,
                                                {
                                                    type: 'linear',
                                                    x: 0,
                                                    y: 0,
                                                    x2: 0,
                                                    y2: 1,
                                                    colorStops: [
                                                        {
                                                            offset: 0,
                                                            color: '#B19589'
                                                        },
                                                        {
                                                            offset: 1,
                                                            color: '#F9BE4A'
                                                        }
                                                    ],
                                                    global: false
                                                }
                                            ]
                                        ]
                                    }
                                },
                                pointer: {
                                    itemStyle: {
                                        color: 'auto'
                                    }
                                },
                                axisTick: {
                                    distance: -16,
                                    length: 5,
                                    lineStyle: {
                                        color: '#fff',
                                        width: 1
                                    }
                                },
                                splitLine: {
                                    distance: -28,
                                    length: 30,
                                    lineStyle: {
                                        color: '#fff',
                                        width: 0
                                    }
                                },
                                axisLabel: {
                                    color: '#fff',
                                    distance: 12,
                                    fontSize: 10
                                },
                                detail: {
                                    valueAnimation: true,
                                    formatter: '{value}',
                                    color: '#F8C472',
                                    fontSize: 20,
                                    offsetCenter: [0, '70%']
                                },
                                data: [
                                    {
                                        value: chartInfo.data
                                    }
                                ]
                            }
                        ]
                    };
                    safeSetOption(pueChart, pueoption);
                }
            }
        }
    }, 1000)
}
// Comment translated to English.
function getImgIndex(images, element) {
    if (images && element) {
        return images.findIndex(v => 'Echart' + v.id === element.getAttribute('id'));
    } else {
        return null
    }

}
export default echart;
