import fs from 'fs';
import path from 'path';

const helperPath = path.join(__dirname, 'logoConfig.js');

describe('logo runtime configuration compatibility', () => {
  test('provides a shared logo configuration parser', () => {
    expect(fs.existsSync(helperPath)).toBe(true);
  });

  test('normalizes array and object response payloads', () => {
    if (!fs.existsSync(helperPath)) return;
    const { getLogoConfig } = require('./logoConfig');

    expect(getLogoConfig({ data: [{ UseSlaveID: '1' }] })).toEqual({ UseSlaveID: '1' });
    expect(getLogoConfig({ data: { UseSlaveID: '0' } })).toEqual({ UseSlaveID: '0' });
    expect(getLogoConfig({ data: [] })).toBeNull();
    expect(getLogoConfig(null)).toBeNull();
  });

  test('prefers explicit UseSlaveID and falls back to master-slave settings', () => {
    if (!fs.existsSync(helperPath)) return;
    const { resolveUseSlaveId } = require('./logoConfig');

    expect(resolveUseSlaveId({ UseSlaveID: '1' })).toBe(true);
    expect(resolveUseSlaveId({ UseSlaveID: '0', MasterSlaveOpen: '2', MasterSlaveRelation: '1' })).toBe(false);
    expect(resolveUseSlaveId({ MasterSlaveOpen: 2, MasterSlaveRelation: 1 })).toBe(true);
    expect(resolveUseSlaveId({ MasterSlaveOpen: 2, MasterSlaveRelation: 0 })).toBe(false);
    expect(resolveUseSlaveId(null)).toBe(false);
  });

  test('persists the resolved flag and system start time', () => {
    if (!fs.existsSync(helperPath)) return;
    const { persistLogoRuntimeConfig } = require('./logoConfig');
    const values = {};
    const storage = {
      setItem: (key, value) => {
        values[key] = value;
      },
    };

    expect(persistLogoRuntimeConfig({
      MasterSlaveOpen: '2',
      MasterSlaveRelation: '1',
      create_time: '2026-08-12 20:00:00',
    }, storage)).toBe(true);
    expect(values).toEqual({
      UseSlaveID: '1',
      SystemStartTime: '2026-08-12 20:00:00',
    });
  });
});
