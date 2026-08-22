export const PREVIEW_REALTIME_INTERVAL_MS = 5000;

const ALARM_DEPENDENCIES = ['alarm'];
const LIVE_DEPENDENCIES = ['realtime', 'alarm'];
const PARAM_DEPENDENCIES = ['param'];
const BINDING_TYPES = ['dataKey', 'eventKey', 'pageKey', 'dataParamsKey', 'dataDevKey'];

const getFirstChild = (shape) => shape && shape.moduleJson && Array.isArray(shape.moduleJson.children)
    ? shape.moduleJson.children[0]
    : null;

const getBindingType = (shape) => {
    const attrs = shape && shape.moduleJson && shape.moduleJson.attrs;
    const groups = attrs && Array.isArray(attrs.moduleAttr) ? attrs.moduleAttr : [];
    const group = groups.find(item => item && Array.isArray(item.attrGroupContent)
        && item.attrGroupContent.some(content => content && BINDING_TYPES.includes(content.attrCode)));
    return group && group.attrGroupContent[0] ? group.attrGroupContent[0].attrCode : '';
};

const getDependencies = (shape) => {
    const attrs = shape && shape.moduleJson && shape.moduleJson.attrs;
    const dataKey = attrs && Array.isArray(attrs.dataKey) ? attrs.dataKey : [];
    const firstChild = getFirstChild(shape);
    const childAttrs = firstChild && firstChild.attrs ? firstChild.attrs : {};
    const bindingType = getBindingType(shape);

    if (firstChild && (firstChild.className === 'alarmList' || childAttrs.cat === 'alarmpie' || childAttrs.name === 'ipImage')) {
        return ALARM_DEPENDENCIES;
    }
    if (!bindingType) return ['all'];
    if (bindingType === 'dataKey') return dataKey[0] && dataKey[0].parkey ? PARAM_DEPENDENCIES : LIVE_DEPENDENCIES;
    if (bindingType === 'eventKey') return ALARM_DEPENDENCIES;
    if (bindingType === 'pageKey') return ['initial'];
    if (bindingType === 'dataDevKey') return LIVE_DEPENDENCIES;
    if (bindingType === 'dataParamsKey') {
        if (firstChild && firstChild.className === 'Echart' && childAttrs.cat === 'line') {
            return dataKey[0] && dataKey[0].paramskey ? ['historyParam'] : ['history'];
        }
        return dataKey[0] && dataKey[0].paramskey ? PARAM_DEPENDENCIES : ['realtime'];
    }
    return ['all'];
};

export const createPreparedPreviewModel = (sources) => {
    const safeSources = Array.isArray(sources) ? sources.filter(item => item && item.id) : [];
    const entries = safeSources.map(source => {
        const firstChild = getFirstChild(source);
        return {
            source,
            id: source.id,
            dependencies: getDependencies(source),
            isChart: Boolean(firstChild && (firstChild.className === 'Echart' || firstChild.className === 'pueHtml'))
        };
    });
    return { sources: safeSources, entries };
};

export const selectPreviewSources = (model, categories) => {
    const safeModel = model || { sources: [], entries: [] };
    const requested = new Set(Array.isArray(categories) ? categories : []);
    if (requested.has('initial')) return safeModel.sources;
    return safeModel.entries
        .filter(entry => entry.dependencies.some(category => category === 'all' || requested.has(category)))
        .map(entry => entry.source);
};

const hasSameRenderedValue = (previous, candidate) => JSON.stringify(previous) === JSON.stringify(candidate);

export const reconcilePreviewElements = (model, previousElements, candidates) => {
    const safeModel = model || { entries: [] };
    const previousById = new Map((Array.isArray(previousElements) ? previousElements : []).map(item => [item.id, item]));
    const candidatesById = new Map((Array.isArray(candidates) ? candidates : []).map(item => [item.id, item]));
    const changedIds = [];
    const changedChartIds = [];
    const elements = safeModel.entries.map(entry => {
        const previous = previousById.get(entry.id);
        const candidate = candidatesById.get(entry.id);
        if (!candidate) return previous || entry.source;
        if (previous && hasSameRenderedValue(previous, candidate)) return previous;
        changedIds.push(entry.id);
        if (entry.isChart) changedChartIds.push(entry.id);
        return candidate;
    });
    return { elements, changedIds, changedChartIds };
};

export const mergePreviewChartRenderIds = (pendingIds, changedIds) => {
    const merged = new Set(Array.isArray(pendingIds) ? pendingIds : []);
    (Array.isArray(changedIds) ? changedIds : []).forEach(id => {
        if (id) merged.add(id);
    });
    return Array.from(merged);
};

export const getPreviewChartRenderIds = (model, refreshCategories, changedChartIds) => {
    const categories = new Set(Array.isArray(refreshCategories) ? refreshCategories : []);
    if (!categories.has('initial')) return Array.isArray(changedChartIds) ? changedChartIds : [];
    const entries = model && Array.isArray(model.entries) ? model.entries : [];
    const initialChartIds = entries
        .filter(entry => entry && entry.isChart && entry.id)
        .map(entry => entry.id);
    return mergePreviewChartRenderIds(changedChartIds, initialChartIds);
};

export const createInitialPreviewRenderState = (model) => {
    const safeModel = model || { sources: [] };
    const elements = Array.isArray(safeModel.sources) ? safeModel.sources : [];
    return {
        elements,
        chartIds: getPreviewChartRenderIds(safeModel, ['initial'], [])
    };
};
