export function getLogoConfig(response) {
  if (!response || typeof response !== 'object') return null;

  const data = response.data;
  if (Array.isArray(data)) {
    return data[0] && typeof data[0] === 'object' ? data[0] : null;
  }
  return data && typeof data === 'object' ? data : null;
}

export function resolveUseSlaveId(config) {
  if (!config || typeof config !== 'object') return false;
  if (Object.prototype.hasOwnProperty.call(config, 'UseSlaveID')) {
    return String(config.UseSlaveID) === '1';
  }
  return String(config.MasterSlaveOpen) === '2'
    && String(config.MasterSlaveRelation) === '1';
}

export function persistLogoRuntimeConfig(config, storage = localStorage) {
  const enabled = resolveUseSlaveId(config);
  storage.setItem('UseSlaveID', enabled ? '1' : '0');
  if (config && config.create_time) {
    storage.setItem('SystemStartTime', config.create_time);
  }
  return enabled;
}
