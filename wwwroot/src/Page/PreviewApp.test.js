import fs from 'fs';
import path from 'path';

describe('preview application runtime', () => {
    const source = fs.readFileSync(path.join(__dirname, 'PreviewApp.js'), 'utf8');

    test('keeps static rendering ahead of the gated incremental data batch', () => {
        expect(source).toContain("from './previewDataBatch'");
        expect(source).toContain("from './previewIncrementalRender'");
        expect(source).toContain('const previewRefreshChannels = createPreviewRefreshChannels();');
        expect(source).toContain('const loadPreviewData = (runner, options) => runner(async () => {');
        expect(source).toContain('handlepredata(previewjson);');
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
});
