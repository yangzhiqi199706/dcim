import fs from 'fs';
import path from 'path';

const readSource = (name) => fs.readFileSync(path.join(__dirname, name), 'utf8');

describe('logo runtime configuration integration', () => {
  test.each(['PreviewApp.js', 'DesignerApp.js', 'Home.js'])(
    '%s uses the shared compatibility parser',
    (name) => {
      const source = readSource(name);

      expect(source).toContain("from '../config/logoConfig'");
      expect(source).toContain('getLogoConfig(');
      expect(source).toContain('persistLogoRuntimeConfig(');
    }
  );

  test('designer refreshes its slave id mode from GetLogoKey', () => {
    const source = readSource('DesignerApp.js');

    expect(source).toContain("httpsend.getData('GetLogoKey', {})");
    expect(source).toContain('setUseSlaveId(enabled);');
  });
});
