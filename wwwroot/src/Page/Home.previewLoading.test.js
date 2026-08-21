const fs = require('fs');
const path = require('path');

describe('preview data loading integration', () => {
    const source = fs.readFileSync(path.join(__dirname, 'PreviewApp.js'), 'utf8');

    test('uses a gated concurrent batch after the static preview render', () => {
        expect(source).toContain("from './previewDataBatch'");
        expect(source).toContain('const previewRefreshChannels = createPreviewRefreshChannels();');
        expect(source).toContain('const loadPreviewData = (runner, options) => runner(async () => {');
        expect(source).toContain('runPreviewDataBatchWithStatus([');
        expect(source).toContain('mergeSuccessfulPreviewData(');

        const staticRenderIndex = source.indexOf('handlepredata(effectivePreviewJson);');
        const initialBatchIndex = source.indexOf('void loadPreviewData(previewRefreshChannels.realtime, {', staticRenderIndex);

        expect(staticRenderIndex).toBeGreaterThan(-1);
        expect(initialBatchIndex).toBeGreaterThan(staticRenderIndex);
        expect(source).not.toMatch(/var devtime = registerInterval/);
        expect(source).not.toContain('let pageTime;');
        expect(source).not.toContain('let pageTimecalc');
        expect(source).not.toContain('let pageHistoryTime;');
        expect(source).not.toContain('let pageparamHistoryTime;');
        expect(source).toContain("from './previewIncrementalRender'");
        expect(source).toContain('PREVIEW_REALTIME_INTERVAL_MS');
        expect(source).toContain('createPreparedPreviewModel(dynamicSources)');
        expect(source).toContain('selectPreviewSources(preparedPreviewModel, refreshCategories)');
        expect(source).toContain('reconcilePreviewElements(preparedPreviewModel, imagesRef.current, candidates)');
        expect(source).toContain('createProtocolNormalizer()');
        expect(source).toContain('changedChartIds');
        expect(source).toContain('}, PREVIEW_REALTIME_INTERVAL_MS);');
        expect(source).not.toContain('}, 10000);');
        expect(source).not.toContain('setImagesdata(JSON.parse(JSON.stringify(newtplimages)))');
        expect(source).toContain('}, 600000);');
        expect(source).toContain('}, 3600000);');
        expect(source).toContain('}, 100)');
        expect(source).toContain('void loadPreviewData(previewRefreshChannels.realtime, {');
        expect(source).toContain('void loadPreviewData(previewRefreshChannels.background, {');
    });
});
