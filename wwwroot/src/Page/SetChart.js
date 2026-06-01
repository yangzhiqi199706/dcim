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
        option.legend.textStyle = {
            ...option.legend.textStyle,
            color: option.legend.textStyle.color || preset.label
        };
    }
    withAxisStyle(option, preset);
    withNoDataHint(option, preset);
};

const isDefaultDarkLabelColor = (color) => {
    if (!color) return true;
    const normalized = String(color).replace(/\s/g, '').toLowerCase();
    return normalized === '#000000' || normalized === '#000' || normalized === 'black' || normalized === 'rgb(0,0,0)' || normalized === 'rgba(0,0,0,1)';
};

const styleBarDataLabel = (label = {}, preset) => {
    if (!label.show) return label;
    return {
        ...label,
        color: isDefaultDarkLabelColor(label.color) ? preset.label : label.color,
        fontWeight: label.fontWeight || 700,
        textShadowColor: label.textShadowColor || preset.shadow,
        textShadowBlur: label.textShadowBlur || 10
    };
};

const styleBarSeries = (series, preset, index) => ({
    ...series,
    barMaxWidth: series.barMaxWidth || 34,
    barMinHeight: series.barMinHeight || 3,
    ...(series.label ? { label: styleBarDataLabel(series.label, preset) } : {}),
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
    const xdata = option.xAxis && Array.isArray(option.xAxis.data) ? option.xAxis.data : [];
    const data = Array.isArray(series.data) ? series.data : [];
    if (data.length < 2) return null;
    const points = data.map((value, pointIndex) => {
        const xValue = xdata[pointIndex] === undefined ? pointIndex : xdata[pointIndex];
        const yValue = value && typeof value === 'object' ? value.value : value;
        return [xValue, yValue];
    });
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
                if (['rounded', 'cylinder', 'battery'].includes(styleKey)) {
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

const insetShape = (shape, ratio) => ({
    x: shape.x + shape.width * ratio,
    y: shape.y + shape.height * ratio,
    width: shape.width * (1 - ratio * 2),
    height: shape.height * (1 - ratio * 2)
});

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
        const inner = insetShape(shape, 0.12);
        const segmentCount = 4;
        const segments = Array.from({ length: segmentCount }).map((_, segmentIndex) => {
            if (isHorizontal) {
                const gap = inner.width * 0.04;
                const width = (inner.width - gap * (segmentCount - 1)) / segmentCount;
                return {
                    type: 'rect',
                    shape: { x: inner.x + segmentIndex * (width + gap), y: inner.y, width, height: inner.height, r: 3 },
                    style: { fill: segmentIndex % 2 === 0 ? glowColor : tailColor, opacity: 0.72 }
                };
            }
            const gap = inner.height * 0.05;
            const height = (inner.height - gap * (segmentCount - 1)) / segmentCount;
            return {
                type: 'rect',
                shape: { x: inner.x, y: inner.y + (segmentCount - 1 - segmentIndex) * (height + gap), width: inner.width, height, r: 3 },
                style: { fill: segmentIndex % 2 === 0 ? glowColor : tailColor, opacity: 0.72 }
            };
        });
        const head = isHorizontal
            ? { x: x2, y: y1 + shape.height * 0.34, width: Math.max(3, shape.height * 0.18), height: shape.height * 0.32, r: 2 }
            : { x: x1 + shape.width * 0.34, y: y1 - Math.max(3, shape.width * 0.18), width: shape.width * 0.32, height: Math.max(3, shape.width * 0.18), r: 2 };
        return [
            { type: 'rect', shape: { ...shape, r: 4 }, style: { fill: 'rgba(7, 20, 48, 0.28)', stroke: glowColor, lineWidth: 1.4, shadowBlur: 14, shadowColor: glowColor } },
            { type: 'rect', shape: head, style: { fill: 'rgba(180, 238, 255, 0.64)' } },
            ...segments
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
            if (['rounded', 'cylinder', 'battery'].includes(styleKey)) {
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
    const barMotionSeries = [];
    option.series = (option.series || []).map((series, index) => {
        if (series.type === 'line' && mode === 'flow') {
            const lineFlowSeries = buildLineFlowSeries(series, option, preset, index);
            if (lineFlowSeries) flowSeries.push(lineFlowSeries);
            return series;
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
        if (series.type === 'pie' && mode === 'pulse') {
            return {
                ...series,
                animationType: 'scale',
                animationEasing: 'elasticOut'
            };
        }
        return series;
    });
    if (flowSeries.length > 0) {
        option.series = option.series.concat(flowSeries);
    }
    if (barMotionSeries.length > 0) {
        option.series = option.series.concat(barMotionSeries);
    }
    const motionGraphics = getMotionGraphicLayer(mode, preset, chartInfo.cat);
    if (motionGraphics.length > 0) {
        option.graphic = [
            ...(option.graphic || []),
            ...motionGraphics
        ];
    }
};

const styleLineSeries = (series, preset, index) => ({
    ...series,
    smooth: true,
    showSymbol: false,
    symbol: 'circle',
    symbolSize: 7,
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
});

const stylePieSeries = (series, preset) => ({
    ...series,
    avoidLabelOverlap: true,
    itemStyle: {
        ...(series.itemStyle || {}),
        borderColor: 'rgba(5, 18, 36, 0.92)',
        borderWidth: 2,
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
                let chartInfo = images[findimg].moduleJson.children[0].attrs;
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
                    let data = [
                        {
                            type: 'pie',
                            radius: '70%',
                            data: chartInfo.data,
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

                    safeSetOption(pieChart, applyChartVisualStyle(pieoption, chartInfo));
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
