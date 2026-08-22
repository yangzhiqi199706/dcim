import fs from 'fs';
import path from 'path';

describe('ElementAttr remote data source integration', () => {
  const source = fs.readFileSync(path.join(__dirname, 'ElementAttr.js'), 'utf8');

  test('loads devices and parameters from the effective global source', () => {
    expect(source).toContain("httpsend.getDataFrom(dataSourceHost, 'GetDeviceListKey'");
    expect(source).toContain("httpsend.getDataFrom(dataSourceHost, 'GetParamListKey'");
    expect(source).toContain("const globalDataSourceHost = normalizeDataSourceHost(props.globalDataSourceHost || '');");
    expect(source).toContain('const dataSourceHost = globalDataSourceHost || initialDataSourceHost;');
  });

  test('persists the effective host on new bindings', () => {
    expect(source).toContain('sourceHost: dataSourceHost');
  });

  test('invalidates in-flight requests when the element or global source changes', () => {
    expect(source).toContain('}, [shapeId, initialDataSourceHost, globalDataSourceHost]);');
  });

  test('does not expose per-element data source controls', () => {
    expect(source).not.toContain('dataSourcePropertyEntry');
    expect(source).not.toContain('renderDataSourceSummary');
    expect(source).not.toContain('chooseDataSource');
  });
});
