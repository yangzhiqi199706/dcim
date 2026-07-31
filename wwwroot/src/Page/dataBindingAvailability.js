const hasValue = (value) => value !== undefined && value !== null && String(value).trim() !== '';

const isDataPointElement = (element) => {
    const attrs = element && element.moduleJson && element.moduleJson.attrs;
    const moduleAttr = attrs && attrs.moduleAttr;
    return Array.isArray(moduleAttr) && moduleAttr.some((group) => Array.isArray(group && group.attrGroupContent)
        && group.attrGroupContent.some((attribute) => attribute && attribute.attrType === 'hardwareInputNew'));
};

const parsePayload = (value) => {
    if (value && typeof value === 'object' && !Array.isArray(value)) return value;
    if (typeof value !== 'string' || !value.trim()) return null;
    try {
        return JSON.parse(value.replace(/'/g, '"'));
    } catch (error) {
        return null;
    }
};

const getDevicePayloads = (device) => {
    const rawRecords = Array.isArray(device && device.DeviceLastDataArr)
        ? device.DeviceLastDataArr
        : (device && device.DeviceLastData ? [{ data: device.DeviceLastData }] : []);

    return rawRecords.map((record) => ({
        cmdType: record && (record.cmdType !== undefined ? record.cmdType : record.CmdType),
        values: parsePayload(record && (record.data !== undefined ? record.data : record.Data)),
    })).filter((record) => record.values && typeof record.values === 'object');
};

const isRemoteBinding = (binding) => typeof binding.src === 'string' && binding.src.indexOf('@') > -1;

const hasMatchingDataPoint = (device, binding) => {
    const targetName = String(binding.name || '').trim();
    const targetCommandType = String(binding.cmdtype || '').trim();
    return getDevicePayloads(device).some((record) => {
        const commandMatches = !targetCommandType || !hasValue(record.cmdType)
            || String(record.cmdType).trim() === targetCommandType;
        return commandMatches && Object.prototype.hasOwnProperty.call(record.values, targetName);
    });
};

const isUnavailableBinding = (binding, devicesById) => {
    if (!binding || typeof binding !== 'object' || isRemoteBinding(binding)) return false;

    const deviceId = binding.key || binding.devkey || binding.deveventskey;
    if (!hasValue(deviceId)) return false;
    const device = devicesById.get(String(deviceId));
    if (!device) return true;
    return hasValue(binding.name) && !hasMatchingDataPoint(device, binding);
};

export const validateDataBindingAvailability = (elements, devices) => {
    if (!Array.isArray(devices)) return [];
    const devicesById = new Map(devices
        .filter((device) => device && hasValue(device.id))
        .map((device) => [String(device.id), device]));

    return (Array.isArray(elements) ? elements : [])
        .map((element, index) => ({ element, index }))
        .filter(({ element }) => element && element.moduleJson && isDataPointElement(element))
        .reduce((findings, { element, index }) => {
            const attrs = element.moduleJson.attrs || {};
            const bindings = Array.isArray(attrs.dataKey) ? attrs.dataKey : [];
            if (!bindings.some((binding) => isUnavailableBinding(binding, devicesById))) return findings;
            findings.push({
                code: 'unavailable-data-binding',
                severity: 'warning',
                elementId: element.id === undefined || element.id === null ? '' : String(element.id).trim(),
                index,
            });
            return findings;
        }, []);
};

export default validateDataBindingAvailability;
