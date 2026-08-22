import fs from 'fs';
import path from 'path';

describe('preview application runtime', () => {
    const source = fs.readFileSync(path.join(__dirname, 'PreviewApp.js'), 'utf8');

    test('keeps static rendering ahead of the gated incremental data batch', () => {
        expect(source).toContain("from './previewDataBatch'");
        expect(source).toContain("from './previewIncrementalRender'");
        expect(source).toContain('const previewRefreshChannels = createPreviewRefreshChannels();');
        expect(source).toContain('const loadPreviewData = (runner, options) => runner(async () => {');
        expect(source).toContain('handlepredata(effectivePreviewJson);');
        expect(source).toContain('}, PREVIEW_REALTIME_INTERVAL_MS);');
        expect(source).toContain('}, 600000);');
        expect(source).toContain('}, 3600000);');
        expect(source).toContain('<PreviewElement');
        expect(source).not.toContain('ItemBox');
    });

    test('loads a titled page through its local page-file reader', () => {
        expect(source).toContain('async function gettxtdata()');
        expect(source).toContain("getDataLocal('imgData', { action: 'page', name: txttitle })");
    });

    test('renders every dynamic chart on the initial preview commit and preserves queued chart ids', () => {
        expect(source).toContain('createInitialPreviewRenderState');
        expect(source).toContain('getPreviewChartRenderIds');
        expect(source).toContain('mergePreviewChartRenderIds');
        expect(source).toContain('let pendingPreviewChartIds = [];');
        expect(source).toContain('pendingPreviewChartIds = mergePreviewChartRenderIds(pendingPreviewChartIds, changedChartIds);');
        expect(source).toContain('const initialPreviewState = createInitialPreviewRenderState(preparedPreviewModel);');
        expect(source).toContain('schedulePreviewChartRender(initialPreviewState.chartIds);');
        expect(source).toContain('schedulePreviewChartRender(getPreviewChartRenderIds(');
    });
});
