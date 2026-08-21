import {
  GLOBAL_DATA_SOURCE_STORAGE_KEY,
  applyGlobalDataSourceToShapes,
  isVerifiedGlobalDataSourceHost,
  readGlobalDataSource,
  saveGlobalDataSource,
} from './globalDataSource';

const createStorage = (initial = {}) => {
  const values = { ...initial };
  return {
    getItem: jest.fn(key => (Object.prototype.hasOwnProperty.call(values, key) ? values[key] : null)),
    setItem: jest.fn((key, value) => { values[key] = value; }),
  };
};

describe('global data source configuration', () => {
  test('normalizes and persists a remote host with the default port', () => {
    const storage = createStorage();

    const config = saveGlobalDataSource({ enabled: true, host: '192.168.0.60' }, storage);

    expect(config).toEqual({ enabled: true, host: '192.168.0.60:8086' });
    expect(readGlobalDataSource(storage)).toEqual(config);
  });

  test('persists disabled mode without retaining an active host', () => {
    const storage = createStorage();

    const config = saveGlobalDataSource({ enabled: false, host: '192.168.0.60:9000' }, storage);

    expect(config).toEqual({ enabled: false, host: '' });
    expect(readGlobalDataSource(storage)).toEqual(config);
  });

  test('falls back to local mode when stored data is invalid', () => {
    const storage = createStorage({
      [GLOBAL_DATA_SOURCE_STORAGE_KEY]: '{invalid-json',
    });

    expect(readGlobalDataSource(storage)).toEqual({ enabled: false, host: '' });
  });

  test('overlays the global host on all bindings without mutating saved shapes', () => {
    const shapes = [{
      id: 'shape-1',
      attrs: {
        moduleJson: {
          attrs: {
            dataKey: [
              { key: '1', sourceHost: '' },
              { key: '2', sourceHost: '192.168.0.9:9000' },
            ],
          },
          children: [],
        },
      },
    }];

    const result = applyGlobalDataSourceToShapes(shapes, '192.168.0.60');

    expect(result[0].attrs.moduleJson.attrs.dataKey.map(binding => binding.sourceHost))
      .toEqual(['192.168.0.60:8086', '192.168.0.60:8086']);
    expect(shapes[0].attrs.moduleJson.attrs.dataKey[1].sourceHost).toBe('192.168.0.9:9000');
  });

  test('returns the original shape list when global mode is disabled', () => {
    const shapes = [{ id: 'shape-1' }];

    expect(applyGlobalDataSourceToShapes(shapes, '')).toBe(shapes);
  });

  test('requires the current remote host to match the last successful connection test', () => {
    expect(isVerifiedGlobalDataSourceHost('192.168.0.60', '192.168.0.60:8086')).toBe(true);
    expect(isVerifiedGlobalDataSourceHost('192.168.0.61', '192.168.0.60:8086')).toBe(false);
    expect(isVerifiedGlobalDataSourceHost('invalid/address', '192.168.0.60:8086')).toBe(false);
    expect(isVerifiedGlobalDataSourceHost('', '')).toBe(false);
  });
});
