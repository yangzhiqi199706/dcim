import fs from 'fs';
import path from 'path';

describe('designer graphite workbench shell contract', () => {
    const source = fs.readFileSync(path.join(__dirname, 'DesignerApp.js'), 'utf8');

    test('provides semantic shell classes for the workbench regions', () => {
        expect(source).toMatch(/className="[^"]*designerShell[^"]*"/);
        expect(source).toMatch(/className="[^"]*designerHeader[^"]*"/);
        expect(source).toMatch(/className="[^"]*designerCanvasBody[^"]*"/);
        expect(source).toMatch(/className="[^"]*designerInspector[^"]*"/);
    });

    test('keeps empty-canvas guidance legible against the graphite canvas', () => {
        expect(source).toContain("const DESIGNER_EMPTY_STATE_TEXT = '#e7eef1';");
        expect(source.match(/fill=\{DESIGNER_EMPTY_STATE_TEXT\}/g)).toHaveLength(2);
    });
});
