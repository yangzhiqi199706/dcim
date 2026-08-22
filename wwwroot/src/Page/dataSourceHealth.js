export const HEALTH_STATUSES = ['available', 'missing', 'invalid', 'unavailable', 'unknown'];

const bindingRequirements = {
    dataKey: [
        ['key', 'name', 'type', 'cmdtype', 'src'],
        ['parkey'],
    ],
    dataDevKey: [
        ['key', 'type', 'src'],
    ],
    dataParamsKey: [
        ['devkey', 'dev', 'name', 'type', 'cmdtype', 'src'],
        ['paramskey', 'name'],
    ],
    pageKey: [
        ['pagekey', 'name'],
    ],
    eventKey: [
        ['deveventskey', 'type', 'src'],
        ['eventsdevname', 'eventskey', 'name', 'eventsdevkey', 'src'],
    ],
};

const hasValue = (value) => value !== undefined && value !== null && String(value).trim() !== '';

const hasBindingValue = (binding, key) => binding[key] !== undefined
    && binding[key] !== null
    && String(binding[key]).trim() !== '';

const parsePayload = (value) => {
    if (value && typeof value === 'object' && !Array.isArray(value)) return value;
    if (typeof value !== 'string' || !value.trim()) return null;
    try {
        return JSON.parse(value.replace(/'/g, '"'));
    } catch (error) {
        return null;
    }
};

const getDataPointAttributeCodes = (element) => {
    const attrs = element && element.moduleJson && element.moduleJson.attrs;
    const moduleAttr = attrs && attrs.moduleAttr;
    if (!Array.isArray(moduleAttr)) return [];

    return moduleAttr.reduce((codes, group) => {
        const contents = group && group.attrGroupContent;
        if (!Array.isArray(contents)) return codes;
        contents.forEach((attribute) => {
            if (attribute && attribute.attrType === 'hardwareInputNew' && hasValue(attribute.attrCode)
                && !codes.includes(attribute.attrCode)) {
                codes.push(attribute.attrCode);
            }
        });
        return codes;
    }, []);
};

const isValidBinding = (binding, attrCode) => {
    if (!binding || typeof binding !== 'object' || Array.isArray(binding)) return false;
    const alternatives = bindingRequirements[attrCode];
    if (!Array.isArray(alternatives)) return Object.keys(binding).some((key) => hasBindingValue(binding, key));
    return alternatives.some((requiredFields) => requiredFields.every((field) => hasBindingValue(binding, field)));
};

const getDevicePayloads = (device) => {
    const records = Array.isArray(device && device.DeviceLastDataArr)
        ? device.DeviceLastDataArr
        : (device && device.DeviceLastData ? [{ data: device.DeviceLastData }] : []);

    return records.map((record) => ({
        cmdType: record && (record.cmdType !== undefined ? record.cmdType : record.CmdType),
        values: parsePayload(record && (record.data !== undefined ? record.data : record.Data)),
    })).filter((record) => record.values && typeof record.values === 'object');
};

const getBindingDeviceId = (binding) => binding && (binding.key || binding.devkey || binding.deveventskey);

const isRemoteBinding = (binding) => typeof binding.src === 'string' && binding.src.indexOf('@') > -1;

const hasMatchingDataPoint = (device, binding) => {
    const dataPointName = String(binding.name || '').trim();
    const commandType = String(binding.cmdtype || '').trim();
    return getDevicePayloads(device).some((record) => {
        const commandMatches = !commandType || !hasValue(record.cmdType)
            || String(record.cmdType).trim() === commandType;
        return commandMatches && Object.prototype.hasOwnProperty.call(record.values, dataPointName);
    });
};

const getBindingHealth = (binding, attrCode, devicesById) => {
    if (isRemoteBinding(binding)) return 'unknown';
    if (attrCode === 'pageKey' || attrCode === 'eventKey') return 'unknown';

    const deviceId = getBindingDeviceId(binding);
    if (!hasValue(deviceId)) return 'unknown';
    const device = devicesById.get(String(deviceId));
    if (!device) return 'unavailable';

    if (attrCode === 'dataDevKey') return 'available';
    if ((attrCode === 'dataKey' || attrCode === 'dataParamsKey') && hasValue(binding.name)) {
        return hasMatchingDataPoint(device, binding) ? 'available' : 'unavailable';
    }
    return 'unknown';
};

const getBindingSummary = (bindingGroups) => {
    const names = bindingGroups.reduce((result, group) => result.concat(group.bindings), [])
        .map((binding) => binding && binding.name)
        .filter(hasValue)
        .map((name) => String(name).trim());
    return [...new Set(names)].join(', ');
};

const getElementLabel = (element, bindingSummary, index) => {
    if (bindingSummary) return bindingSummary;
    const children = element && element.moduleJson && element.moduleJson.children;
    const firstChild = Array.isArray(children) ? children[0] : null;
    const attrs = firstChild && firstChild.attrs ? firstChild.attrs : {};
    const title = attrs.title || attrs.name;
    if (hasValue(title)) return String(title).trim();
    return `Element ${index + 1}`;
};

const getInitialCounts = () => HEALTH_STATUSES.reduce((counts, status) => ({
    ...counts,
    [status]: 0,
}), {});

export const getDataSourceHealthReport = (elements, devices) => {
    const devicesById = new Map((Array.isArray(devices) ? devices : [])
        .filter((device) => device && hasValue(device.id))
        .map((device) => [String(device.id), device]));
    const hasSnapshot = Array.isArray(devices);

    const items = (Array.isArray(elements) ? elements : [])
        .map((element, index) => ({ element, index, attrCodes: getDataPointAttributeCodes(element) }))
        .filter(({ element, attrCodes }) => element && element.moduleJson && attrCodes.length > 0)
        .map(({ element, index, attrCodes }) => {
            const attrs = element.moduleJson.attrs || {};
            const bindingGroups = attrCodes.map((attrCode) => ({
                attrCode,
                bindings: Array.isArray(attrs[attrCode]) ? attrs[attrCode] : [],
            }));
            const bindingSummary = getBindingSummary(bindingGroups);
            const isMissing = bindingGroups.some((group) => group.bindings.length === 0);
            const hasInvalidBinding = bindingGroups.some((group) => group.bindings
                .some((binding) => !isValidBinding(binding, group.attrCode)));
            const bindingStates = bindingGroups.reduce((states, group) => states.concat(group.bindings
                .map((binding) => getBindingHealth(binding, group.attrCode, devicesById))), []);
            let status = 'available';

            if (isMissing) status = 'missing';
            else if (hasInvalidBinding) status = 'invalid';
            else if (!hasSnapshot) status = 'unknown';
            else if (bindingStates.includes('unavailable')) status = 'unavailable';
            else if (bindingStates.includes('unknown')) status = 'unknown';

            return {
                elementId: element.id === undefined || element.id === null ? '' : String(element.id).trim(),
                index,
                label: getElementLabel(element, bindingSummary, index),
                bindingSummary,
                status,
            };
        });

    const counts = items.reduce((result, item) => ({
        ...result,
        [item.status]: result[item.status] + 1,
    }), getInitialCounts());

    return { items, counts };
};

export default getDataSourceHealthReport;
