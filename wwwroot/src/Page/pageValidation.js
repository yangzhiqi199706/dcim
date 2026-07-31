const toFiniteNumber = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};

const getFirstChild = (element) => {
    const children = element && element.moduleJson && element.moduleJson.children;
    return Array.isArray(children) && children.length > 0 ? children[0] : null;
};

const getElementDimension = (element, dimension) => {
    const firstChild = getFirstChild(element);
    const firstAttrs = firstChild && firstChild.attrs ? firstChild.attrs : {};
    const moduleJson = element && element.moduleJson ? element.moduleJson : {};
    return toFiniteNumber(
        element && element[dimension] !== undefined ? element[dimension]
            : (moduleJson[dimension] !== undefined ? moduleJson[dimension] : firstAttrs[dimension])
    );
};

const hasBindingReference = (binding) => [
    'key',
    'devkey',
    'parkey',
    'paramskey',
    'deveventskey',
    'eventskey',
    'weblink',
    'link',
    'videoChannel',
].some((key) => binding[key] !== undefined && binding[key] !== null && String(binding[key]).trim() !== '');

const hasIncompleteDataBinding = (element) => {
    const attrs = element && element.moduleJson && element.moduleJson.attrs;
    const dataKey = attrs && attrs.dataKey;
    if (!Array.isArray(dataKey) || dataKey.length === 0) return false;
    return dataKey.some((binding) => !binding || typeof binding !== 'object' || !hasBindingReference(binding));
};

const hasChartDataMismatch = (element) => {
    const child = getFirstChild(element);
    const attrs = child && child.attrs;
    if (!child || child.className !== 'Echart' || !attrs) return false;
    if (!Array.isArray(attrs.xdata) || attrs.xdata.length === 0 || !Array.isArray(attrs.data)) return false;

    if (attrs.data.every((series) => series && typeof series === 'object' && Array.isArray(series.data))) {
        return attrs.data.some((series) => series.data.length !== attrs.xdata.length);
    }

    return attrs.data.length !== attrs.xdata.length;
};

const createFinding = (code, severity, elementId, index) => ({
    code,
    severity,
    elementId,
    index,
});

export const validatePageElements = (elements, options = {}) => {
    const source = Array.isArray(elements) ? elements : [];
    const findings = [];
    const ids = new Map();
    const stageWidth = toFiniteNumber(options.stageWidth);
    const stageHeight = toFiniteNumber(options.stageHeight);

    source.forEach((element, index) => {
        const elementId = element && element.id !== undefined && element.id !== null
            ? String(element.id).trim()
            : '';
        if (!elementId) {
            findings.push(createFinding('missing-id', 'error', '', index));
            return;
        }
        if (ids.has(elementId)) {
            findings.push(createFinding('duplicate-id', 'error', elementId, index));
            return;
        }
        ids.set(elementId, index);
    });

    source.forEach((element, index) => {
        const elementId = element && element.id !== undefined && element.id !== null
            ? String(element.id).trim()
            : '';
        const width = getElementDimension(element, 'width');
        const height = getElementDimension(element, 'height');
        const x = toFiniteNumber(element && element.x);
        const y = toFiniteNumber(element && element.y);
        const invalidGeometry = width === null || height === null || width <= 0 || height <= 0 || x === null || y === null;

        if (invalidGeometry) {
            findings.push(createFinding('invalid-geometry', 'error', elementId, index));
        } else if (stageWidth !== null && stageHeight !== null && stageWidth > 0 && stageHeight > 0
            && (x < 0 || y < 0 || x + width > stageWidth || y + height > stageHeight)) {
            findings.push(createFinding('out-of-bounds', 'warning', elementId, index));
        }

        if (hasIncompleteDataBinding(element)) {
            findings.push(createFinding('incomplete-data-binding', 'warning', elementId, index));
        }

        if (hasChartDataMismatch(element)) {
            findings.push(createFinding('chart-data-mismatch', 'warning', elementId, index));
        }
    });

    return findings;
};

export default validatePageElements;
