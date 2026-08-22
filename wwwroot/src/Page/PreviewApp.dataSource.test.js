import fs from 'fs';
import path from 'path';

describe('PreviewApp remote data source integration', () => {
  const source = fs.readFileSync(path.join(__dirname, 'PreviewApp.js'), 'utf8');

  test('collects bindings by source and performs grouped DCIM requests', () => {
    expect(source).toContain('collectPreviewDataSourceGroups');
    expect(source).toContain('requestDataSourceGroups');
    expect(source).toContain("'GetDevCommandListKey'");
    expect(source).toContain('httpsend.getDataFrom');
  });

  test('overlays the active global host before preview grouping without changing saved page data', () => {
    expect(source).toContain('readGlobalDataSource');
    expect(source).toContain('applyGlobalDataSourceToShapes');
    expect(source).toContain('const effectivePreviewJson =');
    expect(source).toContain('handlepredata(effectivePreviewJson)');
  });
});
