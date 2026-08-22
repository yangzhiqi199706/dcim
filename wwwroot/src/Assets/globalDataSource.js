import { normalizeDataSourceHost } from './dataSource';

export const GLOBAL_DATA_SOURCE_STORAGE_KEY = 'vibuilder_global_data_source_v1';
export const DISABLED_GLOBAL_DATA_SOURCE = Object.freeze({ enabled: false, host: '' });

const getDefaultStorage = () => (
  typeof localStorage === 'undefined' ? null : localStorage
);

export function readGlobalDataSource(storage = getDefaultStorage()) {
  if (!storage || typeof storage.getItem !== 'function') return { ...DISABLED_GLOBAL_DATA_SOURCE };
  try {
    const raw = storage.getItem(GLOBAL_DATA_SOURCE_STORAGE_KEY);
    if (!raw) return { ...DISABLED_GLOBAL_DATA_SOURCE };
    const parsed = JSON.parse(raw);
    if (!parsed || parsed.enabled !== true) return { ...DISABLED_GLOBAL_DATA_SOURCE };
    const host = normalizeDataSourceHost(parsed.host);
    return host ? { enabled: true, host } : { ...DISABLED_GLOBAL_DATA_SOURCE };
  } catch (error) {
    return { ...DISABLED_GLOBAL_DATA_SOURCE };
  }
}

export function saveGlobalDataSource(config, storage = getDefaultStorage()) {
  const enabled = Boolean(config && config.enabled);
  const host = enabled ? normalizeDataSourceHost(config.host) : '';
  const normalized = host ? { enabled: true, host } : { ...DISABLED_GLOBAL_DATA_SOURCE };
  if (storage && typeof storage.setItem === 'function') {
    storage.setItem(GLOBAL_DATA_SOURCE_STORAGE_KEY, JSON.stringify(normalized));
  }
  return normalized;
}

export function isVerifiedGlobalDataSourceHost(candidateHost, verifiedHost) {
  try {
    const candidate = normalizeDataSourceHost(candidateHost);
    const verified = normalizeDataSourceHost(verifiedHost);
    return Boolean(candidate && verified && candidate === verified);
  } catch (error) {
    return false;
  }
}

const withHostOnModuleJson = (moduleJson, host) => {
  const attrs = moduleJson && moduleJson.attrs;
  const bindings = attrs && Array.isArray(attrs.dataKey) ? attrs.dataKey : null;
  if (!bindings || bindings.length === 0) return moduleJson;
  return {
    ...moduleJson,
    attrs: {
      ...attrs,
      dataKey: bindings.map(binding => (
        binding && typeof binding === 'object' ? { ...binding, sourceHost: host } : binding
      )),
    },
  };
};

export function applyGlobalDataSourceToShapes(shapes, sourceHost) {
  const host = normalizeDataSourceHost(sourceHost);
  if (!host || !Array.isArray(shapes)) return shapes;
  return shapes.map((shape) => {
    if (!shape || typeof shape !== 'object') return shape;
    if (shape.moduleJson) {
      const moduleJson = withHostOnModuleJson(shape.moduleJson, host);
      return moduleJson === shape.moduleJson ? shape : { ...shape, moduleJson };
    }
    const moduleJson = shape.attrs && shape.attrs.moduleJson;
    const nextModuleJson = withHostOnModuleJson(moduleJson, host);
    if (!moduleJson || nextModuleJson === moduleJson) return shape;
    return {
      ...shape,
      attrs: {
        ...shape.attrs,
        moduleJson: nextModuleJson,
      },
    };
  });
}
