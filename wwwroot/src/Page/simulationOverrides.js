const hasOwn = (value, key) => Object.prototype.hasOwnProperty.call(value || {}, key);

const getDataKey = (element) => {
    const attrs = element && element.moduleJson && element.moduleJson.attrs;
    return attrs && Array.isArray(attrs.dataKey) ? attrs.dataKey : [];
};

const getFirstChild = (element) => {
    const children = element && element.moduleJson && element.moduleJson.children;
    return Array.isArray(children) && children.length > 0 ? children[0] : null;
};

const isChartLike = (child) => child && [
    'Echart',
    'gauge',
    'pueHtml',
    'leakWater',
].includes(child.className);

export const getSimulatableElements = (elements) => (Array.isArray(elements) ? elements : [])
    .filter((element) => element && element.id && getDataKey(element).length > 0);

export const getSimulationElementLabel = (element, index = 0) => {
    const dataKey = getDataKey(element);
    const binding = dataKey.find((item) => item && typeof item.name === 'string' && item.name.trim());
    if (binding) return binding.name.trim();
    const firstChild = getFirstChild(element);
    const attrs = firstChild && firstChild.attrs ? firstChild.attrs : {};
    if (typeof attrs.title === 'string' && attrs.title.trim()) return attrs.title.trim();
    return `Element ${index + 1}`;
};

export const parseSimulationValue = (value) => {
    if (typeof value !== 'string') return value;
    const trimmed = value.trim();
    if (!trimmed) return '';
    if (trimmed[0] === '[' || trimmed[0] === '{') {
        try {
            return JSON.parse(trimmed);
        } catch (error) {
            return value;
        }
    }
    const numberValue = Number(trimmed);
    return Number.isFinite(numberValue) ? numberValue : value;
};

const getSimulationValues = (value, length) => {
    const source = Array.isArray(value) ? value : [value];
    const targetLength = Math.max(length || 0, source.length, 1);
    return Array.from({ length: targetLength }, (_, index) => (
        source[index] === undefined ? source[source.length - 1] : source[index]
    ));
};

const getSeriesLength = (attrs = {}) => {
    if (Array.isArray(attrs.xdata) && attrs.xdata.length > 0) return attrs.xdata.length;
    if (!Array.isArray(attrs.data)) return 0;
    return attrs.data.reduce((length, series) => Math.max(
        length,
        Array.isArray(series && series.data) ? series.data.length : 0
    ), 0);
};

const getAxisChartSimulationData = (attrs, value) => {
    const values = getSimulationValues(value, getSeriesLength(attrs));
    const series = Array.isArray(attrs.data)
        ? attrs.data.filter((item) => item && typeof item === 'object' && !Array.isArray(item))
        : [];
    if (series.length === 0) {
        return [{ type: attrs.cat, data: values }];
    }
    return series.map((item) => ({ ...item, data: [...values] }));
};

const getWaterBallSimulationData = (attrs, value) => {
    const source = Array.isArray(attrs.data) && attrs.data[0] && typeof attrs.data[0] === 'object'
        ? attrs.data[0]
        : {};
    const simulationValue = Array.isArray(value) ? value[0] : value;
    return [{ ...source, value: simulationValue }];
};

const getPieSimulationData = (attrs, value) => {
    const source = Array.isArray(attrs.data)
        ? attrs.data.filter((item) => item && typeof item === 'object' && !Array.isArray(item))
        : [];
    const values = getSimulationValues(value, source.length);
    if (source.length === 0) {
        return values.map((item, index) => ({ value: item, name: String(index + 1) }));
    }
    return source.map((item, index) => ({ ...item, value: values[index] }));
};

const getChartSimulationData = (attrs, value) => {
    if (!attrs || attrs.cat === 'gauge' || attrs.cat === 'pue') {
        return Array.isArray(value) ? value[0] : value;
    }
    if (attrs.cat === 'line' || attrs.cat === 'bar') {
        return getAxisChartSimulationData(attrs, value);
    }
    if (attrs.cat === 'waterBall') {
        return getWaterBallSimulationData(attrs, value);
    }
    if (attrs.cat === 'pie' || attrs.cat === 'huan') {
        return getPieSimulationData(attrs, value);
    }
    return value;
};

export const applySimulationOverrides = (elements, overrides = {}) => (Array.isArray(elements) ? elements : [])
    .map((element) => {
        if (!element || !element.id || !hasOwn(overrides, element.id)) return element;
        const firstChild = getFirstChild(element);
        if (!firstChild || !firstChild.attrs) return element;

        const rawValue = overrides[element.id];
        const value = isChartLike(firstChild)
            ? parseSimulationValue(rawValue)
            : (rawValue === undefined || rawValue === null ? '' : String(rawValue));
        const attrKey = isChartLike(firstChild) ? 'data' : 'text';
        const simulatedValue = isChartLike(firstChild)
            ? getChartSimulationData(firstChild.attrs, value)
            : value;
        const children = element.moduleJson.children.map((child, index) => (
            index === 0
                ? { ...child, attrs: { ...child.attrs, [attrKey]: simulatedValue } }
                : child
        ));

        return {
            ...element,
            moduleJson: {
                ...element.moduleJson,
                children,
            },
        };
    });

export default {
    applySimulationOverrides,
    getSimulatableElements,
    getSimulationElementLabel,
    parseSimulationValue,
};
