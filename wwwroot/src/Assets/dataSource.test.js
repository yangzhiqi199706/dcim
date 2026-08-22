import {
  buildDataSourceApiUrl,
  collectPreviewDataSourceGroups,
  createLatestDataSourceRequestGuard,
  groupPreviewSourcesByDataSource,
  mergePreviewShapesByDataSource,
  isDataSourceRecordMatch,
  mergeDataSourceResponse,
  mergeFailedDataSourceHosts,
  normalizeDataSourceHost,
  requestDataSourceGroups,
  selectDataSourceResponse,
  splitPreviewShapeByDataSource,
  tagDataSourceResponse,
} from './dataSource';

const createShape = (id, bindings, child = {}) => ({
  id,
  moduleJson: {
    attrs: { dataKey: bindings },
    children: [{ className: 'Text', attrs: {}, ...child }],
  },
});

describe('data source host normalization', () => {
  test('uses the DCIM default port when only an IP is entered', () => {
    expect(normalizeDataSourceHost('192.168.0.60')).toBe('192.168.0.60:8086');
    expect(normalizeDataSourceHost(' 192.168.0.60:9000 ')).toBe('192.168.0.60:9000');
  });

  test('keeps the local source empty and rejects paths or credentials', () => {
    expect(normalizeDataSourceHost('')).toBe('');
    expect(() => normalizeDataSourceHost('192.168.0.60/path')).toThrow('invalidDataSourceHost');
    expect(() => normalizeDataSourceHost('user@192.168.0.60')).toThrow('invalidDataSourceHost');
    expect(() => normalizeDataSourceHost('999.1.1.1')).toThrow('invalidDataSourceHost');
  });

  test('builds a remote endpoint without changing the current protocol', () => {
    expect(buildDataSourceApiUrl('192.168.0.60', 'GetDeviceListKey', 'http:'))
      .toBe('http://192.168.0.60:8086/GetDeviceListKey');
  });
});

describe('data source response isolation', () => {
  test('tracks failed protocol sources until a later successful response', () => {
    const first = mergeFailedDataSourceHosts(
      new Set(),
      { failedSourceHosts: ['192.168.0.60'] },
      ['', '192.168.0.60:8086']
    );
    expect(Array.from(first)).toEqual(['192.168.0.60:8086']);

    const second = mergeFailedDataSourceHosts(
      first,
      { failedSourceHosts: [] },
      ['', '192.168.0.60:8086']
    );
    expect(Array.from(second)).toEqual([]);
  });

  test('ignores a stale response after a newer source request starts', () => {
    const guard = createLatestDataSourceRequestGuard();
    const localRequest = guard.begin('devices');
    const remoteRequest = guard.begin('devices');

    expect(guard.isCurrent(localRequest)).toBe(false);
    expect(guard.isCurrent(remoteRequest)).toBe(true);
    guard.invalidate();
    expect(guard.isCurrent(remoteRequest)).toBe(false);
  });

  test('tags returned rows and requires both ID and source host to match', () => {
    const response = tagDataSourceResponse({ code: 100, data: [{ DevID: 7 }] }, '192.168.0.60');
    const row = response.data[0];

    expect(row.__sourceHost).toBe('192.168.0.60:8086');
    expect(isDataSourceRecordMatch(row, { key: 7, sourceHost: '192.168.0.60' }, 'DevID', 'key')).toBe(true);
    expect(isDataSourceRecordMatch(row, { key: 7 }, 'DevID', 'key')).toBe(false);
  });

  test('retains the last successful rows only for failed source hosts', () => {
    const previous = {
      code: 100,
      data: [
        { DevID: 1, value: 'old-local', __sourceHost: '' },
        { DevID: 2, value: 'old-remote', __sourceHost: '192.168.0.60:8086' },
      ],
    };
    const next = {
      code: 100,
      data: [{ DevID: 1, value: 'new-local', __sourceHost: '' }],
      failedSourceHosts: ['192.168.0.60:8086'],
    };

    expect(mergeDataSourceResponse(previous, next).data).toEqual([
      { DevID: 1, value: 'new-local', __sourceHost: '' },
      { DevID: 2, value: 'old-remote', __sourceHost: '192.168.0.60:8086' },
    ]);
  });
});

describe('preview data source groups', () => {
  test('groups equal device IDs under different source hosts', () => {
    const groups = collectPreviewDataSourceGroups([
      createShape('local', [{ key: '7', name: 'Power', type: '1' }]),
      createShape('remote', [{ key: '7', name: 'Power', type: '1', sourceHost: '192.168.0.60' }]),
      createShape('custom', [{ parkey: '19', sourceHost: '192.168.0.60:8086' }]),
    ]);

    expect(groups[''].realtimeDeviceIds).toEqual(['7']);
    expect(groups['192.168.0.60:8086'].realtimeDeviceIds).toEqual(['7']);
    expect(groups['192.168.0.60:8086'].customParameterIds).toEqual(['19']);
  });

  test('separates component sources and API rows before preview rendering', () => {
    const localShape = createShape('local', [{ key: '7', name: 'Power' }]);
    const remoteShape = createShape('remote', [{ key: '7', name: 'Power', sourceHost: '192.168.0.60' }]);
    const groupedSources = groupPreviewSourcesByDataSource([localShape, remoteShape]);
    const response = {
      code: 100,
      data: [
        { DevID: 7, value: 'local', __sourceHost: '' },
        { DevID: 7, value: 'remote', __sourceHost: '192.168.0.60:8086' },
      ],
    };

    expect(groupedSources['']).toEqual([localShape]);
    expect(groupedSources['192.168.0.60:8086']).toEqual([remoteShape]);
    expect(selectDataSourceResponse(response, '192.168.0.60').data).toEqual([
      { DevID: 7, value: 'remote', __sourceHost: '192.168.0.60:8086' },
    ]);
  });

  test('keeps successful servers when another server is offline', async () => {
    const groups = collectPreviewDataSourceGroups([
      createShape('local', [{ key: '1', name: 'A', type: '1' }]),
      createShape('remote', [{ key: '2', name: 'B', type: '1', sourceHost: '192.168.0.60' }]),
    ]);
    const requester = jest.fn((sourceHost) => (
      sourceHost
        ? Promise.reject(new Error('offline'))
        : Promise.resolve({ code: 100, data: [{ DevID: 1 }] })
    ));

    await expect(requestDataSourceGroups(
      groups,
      'GetDevCommandListKey',
      group => ({ DevIDs: group.realtimeDeviceIds.join(',') }),
      requester
    )).resolves.toEqual({
      code: 100,
      data: [{ DevID: 1, __sourceHost: '' }],
      failedSourceHosts: ['192.168.0.60:8086'],
    });
  });

  test('treats a DCIM business failure as a failed source', async () => {
    const result = await requestDataSourceGroups(
      { '': { sourceHost: '', realtimeDeviceIds: ['1'] } },
      'GetDevCommandListKey',
      group => ({ DevIDs: group.realtimeDeviceIds.join(',') }),
      () => Promise.resolve({ code: 400, data: [] })
    );

    expect(result.data).toEqual([]);
    expect(result.failedSourceHosts).toEqual(['']);
  });

  test('splits one multi-source chart into source-specific clones and merges its series back', () => {
    const shape = createShape('multi', [
      { key: '1', name: 'Local power' },
      { key: '2', name: 'Remote power', sourceHost: '192.168.0.60' },
    ]);
    const clones = splitPreviewShapeByDataSource(shape);

    expect(clones).toHaveLength(2);
    expect(clones.map(item => item.moduleJson.attrs.dataKey.length)).toEqual([1, 1]);
    expect(clones[0].moduleJson.attrs.dataKey[0].sourceHost).toBeUndefined();
    expect(clones[1].moduleJson.attrs.dataKey[0].sourceHost).toBe('192.168.0.60');

    const merged = mergePreviewShapesByDataSource([
      {
        ...clones[0],
        moduleJson: {
          ...clones[0].moduleJson,
          children: [{ className: 'Echart', attrs: { cat: 'line', data: [{ name: 'Local', data: [1] }], xdata: ['x'] } }],
        },
      },
      {
        ...clones[1],
        moduleJson: {
          ...clones[1].moduleJson,
          children: [{ className: 'Echart', attrs: { cat: 'line', data: [{ name: 'Remote', data: [2] }], xdata: ['x'] } }],
        },
      },
    ]);

    expect(merged).toHaveLength(1);
    expect(merged[0].moduleJson.children[0].attrs.data).toEqual([
      { name: 'Local', data: [1] },
      { name: 'Remote', data: [2] },
    ]);
    expect(merged[0].moduleJson.attrs.dataKey).toHaveLength(2);
  });
});
