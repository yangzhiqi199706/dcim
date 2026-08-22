import fs from 'fs';
import path from 'path';

describe('GlobalDataSourceModal', () => {
  const modalPath = path.join(__dirname, 'GlobalDataSourceModal.js');
  const appPath = path.join(__dirname, 'DesignerApp.js');

  test('tests and saves one global remote API host without invoking destructive synchronization', () => {
    const source = fs.existsSync(modalPath) ? fs.readFileSync(modalPath, 'utf8') : '';

    expect(source).toContain("getDataFrom(candidateHost, 'GetDeviceListKey'");
    expect(source).toContain('saveGlobalDataSource');
    expect(source).toContain("t('globalDataSource.disable')");
    expect(source).not.toContain('remoteSyncPreflight');
    expect(source).not.toContain('remoteSyncStart');
    expect(source).not.toContain('remoteSyncStatus');
  });

  test('is opened from the designer ribbon and receives the active configuration', () => {
    const source = fs.readFileSync(appPath, 'utf8');

    expect(source).toContain('globalDataSource: () => setGlobalDataSourceOpen(true)');
    expect(source).toContain('<GlobalDataSourceModal');
    expect(source).toContain('config={globalDataSource}');
  });
});
