import { normalizeDataSourceHost } from '../Assets/dataSource';

const getDataKey = (shape) => {
    const dataKey = shape && shape.moduleJson && shape.moduleJson.attrs
        ? shape.moduleJson.attrs.dataKey
        : null;
    return Array.isArray(dataKey) ? dataKey : [];
};

const getDeviceId = (binding) => {
    if (!binding) return '';
    const value = binding.key || binding.devkey || '';
    return value === '' || value === null || value === undefined ? '' : String(value);
};

const getProtocolBinding = (shape) => {
    const dataKey = getDataKey(shape);
    if (dataKey.length !== 1) return null;
    const binding = dataKey[0];
    const deviceId = getDeviceId(binding);
    const parameterName = binding && typeof binding.name === 'string' ? binding.name.trim() : '';
    if (!deviceId || !parameterName) return null;
    let sourceHost;
    try {
        sourceHost = normalizeDataSourceHost(binding.sourceHost);
    } catch (error) {
        return null;
    }
    return { binding, deviceId, parameterName, sourceHost };
};

const parseDeviceData = (value) => {
    if (typeof value !== 'string' || !value.trim()) return null;
    try {
        const parsed = JSON.parse(value.replace(/'/g, '"'));
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
    } catch (error) {
        return null;
    }
};

const normalizeSelectionIds = (ids) => Array.from(new Set(
    (Array.isArray(ids) ? ids : [])
        .filter((id) => id !== null && id !== undefined && id !== '')
        .map((id) => String(id))
)).sort();

export const isParameterReplacementSelectionCurrent = (context, selectedIds) => {
    if (!context || !Array.isArray(context.selectedIds)) return false;
    const expected = normalizeSelectionIds(context.selectedIds);
    const current = normalizeSelectionIds(selectedIds);
    return expected.length === current.length && expected.every((id, index) => id === current[index]);
};

export const createParameterReplacementRequestGuard = () => {
    let requestId = 0;
    return {
        begin: () => {
            requestId += 1;
            return requestId;
        },
        invalidate: () => {
            requestId += 1;
        },
        isCurrent: (id) => id === requestId,
    };
};

export const createParameterReplacementContext = (shapes, selectedIds) => {
    const ids = Array.isArray(selectedIds) ? Array.from(new Set(selectedIds.filter(Boolean))) : [];
    if (ids.length < 2) {
        return { valid: false, reasonKey: 'parameterReplacement.selectionRequired' };
    }
    const shapeById = new Map((Array.isArray(shapes) ? shapes : []).map((shape) => [shape.id, shape]));
    const selectedShapes = ids.map((id) => shapeById.get(id)).filter(Boolean);
    if (selectedShapes.length !== ids.length) {
        return { valid: false, reasonKey: 'parameterReplacement.protocolBindingRequired' };
    }
    const bindings = selectedShapes.map(getProtocolBinding);
    if (bindings.some((binding) => !binding)) {
        return { valid: false, reasonKey: 'parameterReplacement.protocolBindingRequired' };
    }
    const deviceSources = Array.from(new Set(bindings.map((binding) => (
        `${binding.sourceHost}\u0000${binding.deviceId}`
    ))));
    if (deviceSources.length !== 1) {
        return { valid: false, reasonKey: 'parameterReplacement.sameDeviceRequired' };
    }
    const originalOptions = Array.from(new Set(bindings.map((binding) => binding.parameterName)))
        .map((parameterName) => ({ label: parameterName, value: parameterName }));
    return {
        valid: true,
        deviceId: bindings[0].deviceId,
        sourceHost: bindings[0].sourceHost,
        selectedIds: ids,
        originalOptions,
    };
};

export const createDeviceParameterOptions = (device) => {
    if (!device || typeof device !== 'object') return [];
    const packets = Array.isArray(device.DeviceLastDataArr) && device.DeviceLastDataArr.length > 0
        ? device.DeviceLastDataArr.map((item) => ({
            data: item && (item.data || item.Data),
            cmdtype: item && (item.cmdType || item.CmdType || item.cmdtype || ''),
        }))
        : [{ data: device.DeviceLastData, cmdtype: '' }];
    const optionByValue = new Map();
    packets.forEach((packet) => {
        const values = parseDeviceData(packet.data);
        if (!values) return;
        Object.keys(values).forEach((name) => {
            const cmdtype = packet.cmdtype == null ? '' : String(packet.cmdtype);
            const value = `${name}|${cmdtype}`;
            if (optionByValue.has(value)) return;
            optionByValue.set(value, {
                label: name,
                value,
                name,
                cmdtype,
                type: device.LinkMode || device.linkMode || '',
            });
        });
    });
    return Array.from(optionByValue.values());
};

export const createParameterReplacementMappingPlan = (
    originalOptions,
    replacementOptions,
    selectedTargets,
    keywordFrom,
    keywordTo
) => {
    const options = Array.isArray(replacementOptions) ? replacementOptions : [];
    const optionsByValue = new Map(options.map((option) => [option.value, option]));
    const optionsByName = new Map();
    options.forEach((option) => {
        if (!option || typeof option.name !== 'string' || !option.name) return;
        if (!optionsByName.has(option.name)) optionsByName.set(option.name, option);
    });

    const manualTargets = selectedTargets && typeof selectedTargets === 'object' ? selectedTargets : {};
    const from = typeof keywordFrom === 'string' ? keywordFrom.trim() : '';
    const to = typeof keywordTo === 'string' ? keywordTo.trim() : '';
    const mappings = [];
    const missingNames = [];

    (Array.isArray(originalOptions) ? originalOptions : []).forEach((original) => {
        if (!original || typeof original.value !== 'string' || !original.value) return;
        const manualReplacement = optionsByValue.get(manualTargets[original.value]);
        if (manualReplacement) {
            mappings.push({ originalName: original.value, replacement: manualReplacement });
            return;
        }
        if (!from || !original.value.includes(from)) return;
        const expectedName = original.value.split(from).join(to);
        const keywordReplacement = optionsByName.get(expectedName);
        if (keywordReplacement) {
            mappings.push({ originalName: original.value, replacement: keywordReplacement });
        } else {
            missingNames.push(expectedName);
        }
    });

    return { mappings, missingNames: Array.from(new Set(missingNames)) };
};

export const replaceSelectedParameterBindings = (shapes, context, originalName, replacement) => {
    if (!context || !context.valid || !originalName || !replacement || !replacement.name) {
        return { shapes, changedCount: 0 };
    }
    const selectedIds = new Set(context.selectedIds);
    let changedCount = 0;
    const nextShapes = (Array.isArray(shapes) ? shapes : []).map((shape) => {
        if (!shape || !selectedIds.has(shape.id)) return shape;
        const bindingInfo = getProtocolBinding(shape);
        if (
            !bindingInfo
            || bindingInfo.deviceId !== context.deviceId
            || bindingInfo.sourceHost !== context.sourceHost
            || bindingInfo.parameterName !== originalName
        ) {
            return shape;
        }
        const nextBinding = {
            ...bindingInfo.binding,
            name: replacement.name,
        };
        if (replacement.type !== undefined && replacement.type !== null && replacement.type !== '') {
            nextBinding.type = replacement.type;
        }
        if (replacement.cmdtype !== undefined && replacement.cmdtype !== null) {
            nextBinding.cmdtype = replacement.cmdtype;
        }
        changedCount += 1;
        return {
            ...shape,
            moduleJson: {
                ...shape.moduleJson,
                attrs: {
                    ...shape.moduleJson.attrs,
                    dataKey: [nextBinding],
                },
            },
        };
    });
    return { shapes: nextShapes, changedCount };
};

export const replaceSelectedParameterBindingMappings = (shapes, context, mappings) => {
    if (!context || !context.valid || !Array.isArray(mappings)) {
        return { shapes, changedCount: 0 };
    }
    const replacementsByOriginalName = new Map();
    mappings.forEach((mapping) => {
        const originalName = mapping && typeof mapping.originalName === 'string'
            ? mapping.originalName.trim()
            : '';
        const replacement = mapping && mapping.replacement;
        if (!originalName || !replacement || !replacement.name) return;
        replacementsByOriginalName.set(originalName, replacement);
    });
    if (replacementsByOriginalName.size === 0) {
        return { shapes, changedCount: 0 };
    }

    const selectedIds = new Set(context.selectedIds);
    let changedCount = 0;
    const nextShapes = (Array.isArray(shapes) ? shapes : []).map((shape) => {
        if (!shape || !selectedIds.has(shape.id)) return shape;
        const bindingInfo = getProtocolBinding(shape);
        if (
            !bindingInfo
            || bindingInfo.deviceId !== context.deviceId
            || bindingInfo.sourceHost !== context.sourceHost
        ) return shape;
        const replacement = replacementsByOriginalName.get(bindingInfo.parameterName);
        if (!replacement) return shape;

        const nextBinding = {
            ...bindingInfo.binding,
            name: replacement.name,
        };
        if (replacement.type !== undefined && replacement.type !== null && replacement.type !== '') {
            nextBinding.type = replacement.type;
        }
        if (replacement.cmdtype !== undefined && replacement.cmdtype !== null) {
            nextBinding.cmdtype = replacement.cmdtype;
        }
        if (
            nextBinding.name === bindingInfo.binding.name
            && nextBinding.type === bindingInfo.binding.type
            && nextBinding.cmdtype === bindingInfo.binding.cmdtype
        ) {
            return shape;
        }

        changedCount += 1;
        return {
            ...shape,
            moduleJson: {
                ...shape.moduleJson,
                attrs: {
                    ...shape.moduleJson.attrs,
                    dataKey: [nextBinding],
                },
            },
        };
    });
    return { shapes: nextShapes, changedCount };
};
