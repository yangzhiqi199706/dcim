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
        const children = element.moduleJson.children.map((child, index) => (
            index === 0
                ? { ...child, attrs: { ...child.attrs, [attrKey]: value } }
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
