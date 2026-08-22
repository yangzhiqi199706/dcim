import fs from 'fs';
import path from 'path';

const readSource = (name) => fs.readFileSync(path.join(__dirname, name), 'utf8');

describe('lazy application entry boundary', () => {
    test('keeps both applications behind dynamic imports', () => {
        const source = readSource('index.js');

        expect(source).toContain("React.lazy(() => import('./Page/PreviewApp'))");
        expect(source).toContain("React.lazy(() => import('./Page/DesignerApp'))");
        expect(source).not.toMatch(/import\s+Home\s+from/);
        expect(source).not.toMatch(/import\s+.*PreviewApp\s+from/);
        expect(source).not.toMatch(/import\s+['\"]\.\/Assets\/style\.css['\"]/);
    });

    test('does not preload the legacy designer stylesheet from the HTML shell', () => {
        const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'index.html'), 'utf8');

        expect(source).not.toContain('href="css/style.css"');
    });

    test('keeps designer-only controls out of the preview application', () => {
        const source = readSource('Page/PreviewApp.js');

        ['ItemBox', 'ToolList', 'ConElement', 'ElementAttr', "'antd'", "'@mui/icons-material'"]
            .forEach(token => expect(source).not.toContain(token));
    });

    test('keeps preview polling and rendering out of the designer application', () => {
        const source = readSource('Page/DesignerApp.js');

        [
            'PreviewElement',
            'PreviewDeal',
            'previewDataBatch',
            'previewIncrementalRender',
            'gettxtdata',
            'txttitle'
        ]
            .forEach(token => expect(source).not.toContain(token));
    });

    test('does not retain preview lifecycle state after the route split', () => {
        const source = readSource('Page/DesignerApp.js');

        ['registerInterval', 'registerTimeout', 'stageWidthRef', 'stageHeightRef']
            .forEach(token => expect(source).not.toContain(token));
        expect(source.indexOf('const [stageDimensions, setStageDimensions]'))
            .toBeLessThan(source.indexOf('const displayedStageWidth'));
    });
});
