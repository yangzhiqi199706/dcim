import fs from 'fs';
import path from 'path';

describe('Ribbon toolbar local preview', () => {
    test('loads a dedicated toolbar preview only from localhost', () => {
        const entry = fs.readFileSync(path.join(__dirname, 'index.js'), 'utf8');
        const preview = fs.readFileSync(path.join(__dirname, 'RibbonToolbarPreviewApp.js'), 'utf8');

        expect(entry).toContain("import('./RibbonToolbarPreviewApp')");
        expect(entry).toContain("window.location.hostname === 'localhost'");
        expect(preview).toContain('<DesignerRibbonToolbar');
    });
});
