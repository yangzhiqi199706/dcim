import fs from 'fs';
import path from 'path';

describe('DesignerApp global data source integration', () => {
  const source = fs.readFileSync(path.join(__dirname, 'DesignerApp.js'), 'utf8');

  test('routes every designer device snapshot through the active global source', () => {
    expect(source).toContain('const getDataFromActiveSource =');
    expect(source).toContain("getDataFromActiveSource('GetDeviceListKey', { ComboBox: 'all' }, context.sourceHost)");
    expect((source.match(/getDataFromActiveSource\('GetDeviceListKey'/g) || []).length).toBeGreaterThanOrEqual(4);
  });

  test('keeps page and logo configuration requests on the local business API', () => {
    expect(source).toContain("httpsend.getData('GetLogoKey'");
    expect(source).toContain("httpsend.getData('GetDmpageListKey'");
  });
});
