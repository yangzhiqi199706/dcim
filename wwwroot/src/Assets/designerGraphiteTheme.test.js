import fs from 'fs';
import path from 'path';

describe('designer graphite theme contract', () => {
    const source = fs.readFileSync(path.join(__dirname, 'designer.css'), 'utf8');

    test('defines the graphite surface and teal accent tokens', () => {
        expect(source).toContain('--designer-surface: #24292d;');
        expect(source).toContain('--designer-accent: #2cc7ae;');
    });

    test('styles the editor shell layout and honors reduced-motion preferences', () => {
        expect(source).toContain('.designerShell');
        expect(source).toContain('.designerShell .top');
        expect(source).toContain('.designerShell .canvasBody');
        expect(source).toContain('@media (prefers-reduced-motion: reduce)');
    });
});
