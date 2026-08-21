import { mainApiBase } from '../config/endpoints';

export const DATA_SOURCE_DEFAULT_PORT = '8086';
export const DATA_SOURCE_META_KEY = '__sourceHost';

const INVALID_HOST_ERROR = 'invalidDataSourceHost';
const HOSTNAME_PATTERN = /^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i;

const isValidIpv4 = (host) => {
  const parts = host.split('.');
  return parts.length === 4 && parts.every(part => /^\d{1,3}$/.test(part) && Number(part) <= 255);
};

export const normalizeDataSourceHost = (value) => {
  const raw = String(value === undefined || value === null ? '' : value).trim();
  if (!raw) return '';
  if (/[/\\@?#\s]/.test(raw) || raw.includes('://')) throw new Error(INVALID_HOST_ERROR);

  const match = raw.match(/^([^:]+)(?::(\d{1,5}))?$/);
  if (!match) throw new Error(INVALID_HOST_ERROR);
  const host = match[1].toLowerCase();
  const port = match[2] || DATA_SOURCE_DEFAULT_PORT;
  const portNumber = Number(port);
  const isIpv4Like = /^\d+(?:\.\d+){3}$/.test(host);
  const isValidHost = isIpv4Like ? isValidIpv4(host) : HOSTNAME_PATTERN.test(host);
  if (!isValidHost || portNumber < 1 || portNumber > 65535) {
    throw new Error(INVALID_HOST_ERROR);
  }
  return `${host}:${port}`;
};

const safeNormalizeDataSourceHost = (value) => {
  try {
    return normalizeDataSourceHost(value);
  } catch (error) {
    return '';
  }
};

export const createLatestDataSourceRequestGuard = () => {
  let generation = 0;
  const sequences = {};
  return {
    begin(key = 'default') {
      sequences[key] = (sequences[key] || 0) + 1;
      return { key, sequence: sequences[key], generation };
    },
    isCurrent(request) {
      return Boolean(request)
        && request.generation === generation
        && request.sequence === sequences[request.key];
    },
    invalidate(key) {
      if (key) {
        sequences[key] = (sequences[key] || 0) + 1;
        return;
      }
      generation += 1;
    },
  };
};

export const buildDataSourceApiUrl = (sourceHost, path, protocol) => {
  const normalizedHost = normalizeDataSourceHost(sourceHost);
  const cleanPath = String(path || '').replace(/^\/+/, '');
  if (!normalizedHost) return `${String(mainApiBase).replace(/\/+$/, '')}/${cleanPath}`;
  const resolvedProtocol = protocol || (typeof window !== 'undefined' ? window.location.protocol : 'http:');
  return `${resolvedProtocol}//${normalizedHost}/${cleanPath}`;
};

export const tagDataSourceResponse = (response, sourceHost) => {
  const normalizedHost = normalizeDataSourceHost(sourceHost);
  const safeResponse = response && typeof response === 'object' ? response : { code: 100, data: [] };
  const data = Array.isArray(safeResponse.data)
    ? safeResponse.data.map(item => (
      item && typeof item === 'object'
        ? { ...item, [DATA_SOURCE_META_KEY]: normalizedHost }
        : item
    ))
    : safeResponse.data;
  return { ...safeResponse, data };
};

export const isDataSourceRecordMatch = (record, binding, recordIdKey, bindingIdKey) => {
  if (!record || !binding) return false;
  const recordId = record[recordIdKey];
  const bindingId = binding[bindingIdKey];
  if (String(recordId === undefined || recordId === null ? '' : recordId).trim()
      !== String(bindingId === undefined || bindingId === null ? '' : bindingId).trim()) return false;
  return safeNormalizeDataSourceHost(record[DATA_SOURCE_META_KEY])
    === safeNormalizeDataSourceHost(binding.sourceHost);
};

const getShapeModuleJson = (shape) => (
  shape && shape.moduleJson
    ? shape.moduleJson
    : (shape && shape.attrs ? shape.attrs.moduleJson : null)
);

const createGroup = (sourceHost) => ({
  sourceHost,
  realtimeDeviceIds: [],
  snmpDeviceIds: [],
  historyDeviceIds: [],
  historyCommandTypes: [],
  customParameterIds: [],
  historyParameterIds: [],
});

const pushUnique = (target, value) => {
  if (value === undefined || value === null || String(value).trim() === '') return;
  const normalized = String(value);
  if (!target.includes(normalized)) target.push(normalized);
};

export const collectPreviewDataSourceGroups = (shapes) => {
  const groups = { '': createGroup('') };
  (Array.isArray(shapes) ? shapes : []).forEach((shape) => {
    const moduleJson = getShapeModuleJson(shape);
    const attrs = moduleJson && moduleJson.attrs;
    const bindings = attrs && Array.isArray(attrs.dataKey) ? attrs.dataKey : [];
    const firstChild = moduleJson && Array.isArray(moduleJson.children) ? moduleJson.children[0] : null;
    const isHistoryChart = firstChild && firstChild.className === 'Echart'
      && firstChild.attrs && firstChild.attrs.cat === 'line';

    bindings.forEach((binding) => {
      if (!binding || typeof binding !== 'object') return;
      const sourceHost = safeNormalizeDataSourceHost(binding.sourceHost);
      if (!groups[sourceHost]) groups[sourceHost] = createGroup(sourceHost);
      const group = groups[sourceHost];
      const deviceId = binding.key !== undefined ? binding.key : binding.devkey;
      pushUnique(group.realtimeDeviceIds, deviceId);
      if (String(binding.type) === '3' && binding.name) pushUnique(group.snmpDeviceIds, deviceId);
      if (binding.parkey !== undefined) pushUnique(group.customParameterIds, binding.parkey);
      if (binding.paramskey !== undefined) {
        pushUnique(group.customParameterIds, binding.paramskey);
        pushUnique(group.historyParameterIds, binding.paramskey);
      }
      if (isHistoryChart && binding.devkey !== undefined) {
        if (!sourceHost && typeof binding.src === 'string' && binding.src.includes('@')) return;
        pushUnique(group.historyDeviceIds, binding.devkey);
        pushUnique(group.historyCommandTypes, binding.cmdtype);
      }
    });
  });
  return groups;
};

export const groupPreviewSourcesByDataSource = (shapes) => {
  const groups = {};
  (Array.isArray(shapes) ? shapes : []).forEach((shape) => {
    const moduleJson = getShapeModuleJson(shape);
    const attrs = moduleJson && moduleJson.attrs;
    const bindings = attrs && Array.isArray(attrs.dataKey) ? attrs.dataKey : [];
    const sourceHost = safeNormalizeDataSourceHost(bindings[0] && bindings[0].sourceHost);
    if (!groups[sourceHost]) groups[sourceHost] = [];
    groups[sourceHost].push(shape);
  });
  return groups;
};

const PREVIEW_SOURCE_SPLIT_META_KEY = '__previewSourceSplit';

const cloneValue = (value) => JSON.parse(JSON.stringify(value));

export const splitPreviewShapeByDataSource = (shape) => {
  const moduleJson = getShapeModuleJson(shape);
  const attrs = moduleJson && moduleJson.attrs;
  const bindings = attrs && Array.isArray(attrs.dataKey) ? attrs.dataKey : [];
  const bindingsByHost = bindings.reduce((groups, binding) => {
    const sourceHost = safeNormalizeDataSourceHost(binding && binding.sourceHost);
    if (!groups[sourceHost]) groups[sourceHost] = [];
    groups[sourceHost].push(binding);
    return groups;
  }, {});
  const sourceHosts = Object.keys(bindingsByHost);
  if (sourceHosts.length <= 1) return [shape];

  return sourceHosts.map((sourceHost, sourceIndex) => {
    const clone = cloneValue(shape);
    const cloneModuleJson = getShapeModuleJson(clone);
    cloneModuleJson.attrs.dataKey = cloneValue(bindingsByHost[sourceHost]);
    clone[PREVIEW_SOURCE_SPLIT_META_KEY] = {
      sourceIndex,
      originalDataKey: cloneValue(bindings),
    };
    return clone;
  });
};

const mergeChartData = (targetAttrs, sourceAttrs) => {
  const targetData = Array.isArray(targetAttrs.data) ? targetAttrs.data : [];
  const sourceData = Array.isArray(sourceAttrs.data) ? sourceAttrs.data : [];
  if (targetAttrs.cat === 'bar' && targetData.length === 1 && sourceData.length === 1) {
    targetData[0] = {
      ...targetData[0],
      data: [
        ...(Array.isArray(targetData[0].data) ? targetData[0].data : []),
        ...(Array.isArray(sourceData[0].data) ? sourceData[0].data : []),
      ],
    };
    targetAttrs.data = targetData;
    targetAttrs.xdata = [
      ...(Array.isArray(targetAttrs.xdata) ? targetAttrs.xdata : []),
      ...(Array.isArray(sourceAttrs.xdata) ? sourceAttrs.xdata : []),
    ];
    return;
  }
  targetAttrs.data = [...targetData, ...sourceData];
  if ((!Array.isArray(targetAttrs.xdata) || targetAttrs.xdata.length === 0)
      && Array.isArray(sourceAttrs.xdata)) {
    targetAttrs.xdata = sourceAttrs.xdata;
  }
};

export const mergePreviewShapesByDataSource = (shapes) => {
  const mergedById = new Map();
  const order = [];
  (Array.isArray(shapes) ? shapes : []).forEach((shape) => {
    const splitMeta = shape && shape[PREVIEW_SOURCE_SPLIT_META_KEY];
    const shapeId = shape && shape.id;
    if (!splitMeta || !shapeId) {
      order.push(shape);
      return;
    }
    if (!mergedById.has(shapeId)) {
      const initial = cloneValue(shape);
      delete initial[PREVIEW_SOURCE_SPLIT_META_KEY];
      const initialModuleJson = getShapeModuleJson(initial);
      initialModuleJson.attrs.dataKey = cloneValue(splitMeta.originalDataKey);
      mergedById.set(shapeId, initial);
      order.push(initial);
      return;
    }

    const target = mergedById.get(shapeId);
    const targetChild = getShapeModuleJson(target).children[0];
    const sourceChild = getShapeModuleJson(shape).children[0];
    if (targetChild && sourceChild && targetChild.className === 'Echart'
        && sourceChild.className === 'Echart' && targetChild.attrs && sourceChild.attrs) {
      mergeChartData(targetChild.attrs, sourceChild.attrs);
    }
  });
  return order;
};

export const selectDataSourceResponse = (response, sourceHost) => {
  const normalizedHost = safeNormalizeDataSourceHost(sourceHost);
  const safeResponse = response && typeof response === 'object' ? response : { code: 100, data: [] };
  const data = Array.isArray(safeResponse.data)
    ? safeResponse.data.filter(item => (
      safeNormalizeDataSourceHost(item && item[DATA_SOURCE_META_KEY]) === normalizedHost
    ))
    : [];
  return { ...safeResponse, data };
};

export const mergeDataSourceResponse = (previous, next) => {
  const safePrevious = previous && typeof previous === 'object' ? previous : { code: 100, data: [] };
  const safeNext = next && typeof next === 'object' ? next : { code: 100, data: [] };
  const failedHosts = new Set((Array.isArray(safeNext.failedSourceHosts) ? safeNext.failedSourceHosts : [])
    .map(safeNormalizeDataSourceHost));
  const retained = Array.isArray(safePrevious.data)
    ? safePrevious.data.filter(item => failedHosts.has(safeNormalizeDataSourceHost(item && item[DATA_SOURCE_META_KEY])))
    : [];
  return {
    ...safeNext,
    data: [...(Array.isArray(safeNext.data) ? safeNext.data : []), ...retained],
  };
};

export const mergeFailedDataSourceHosts = (current, response, knownSourceHosts = []) => {
  const failed = new Set(
    (Array.isArray(response && response.failedSourceHosts) ? response.failedSourceHosts : [])
      .map(safeNormalizeDataSourceHost)
  );
  const next = new Set(current || []);
  (Array.isArray(knownSourceHosts) ? knownSourceHosts : []).forEach((sourceHost) => {
    const normalized = safeNormalizeDataSourceHost(sourceHost);
    if (failed.has(normalized)) next.add(normalized);
    else next.delete(normalized);
  });
  return next;
};

export const requestDataSourceGroups = async (groups, path, payloadFactory, requester) => {
  const entries = Object.keys(groups || {}).map(sourceHost => groups[sourceHost]);
  const taskDescriptors = entries.map((group) => {
    const payload = payloadFactory(group);
    if (!payload) return null;
    return {
      group,
      promise: Promise.resolve()
      .then(() => requester(group.sourceHost, path, payload))
      .then(response => ({ group, response })),
    };
  }).filter(Boolean);
  if (taskDescriptors.length === 0) return { code: 100, data: [], failedSourceHosts: [] };

  const settled = await Promise.allSettled(taskDescriptors.map(item => item.promise));
  const data = [];
  const failedSourceHosts = [];
  settled.forEach((entry, index) => {
    if (entry.status === 'rejected') {
      failedSourceHosts.push(taskDescriptors[index].group.sourceHost);
      return;
    }
    const response = entry.value.response;
    if (response && response.code !== undefined && Number(response.code) !== 100) {
      failedSourceHosts.push(entry.value.group.sourceHost);
      return;
    }
    const tagged = tagDataSourceResponse(response, entry.value.group.sourceHost);
    if (Array.isArray(tagged.data)) data.push(...tagged.data);
  });
  return { code: 100, data, failedSourceHosts };
};
