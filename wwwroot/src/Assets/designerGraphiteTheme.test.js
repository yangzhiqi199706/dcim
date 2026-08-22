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

    test('keeps ribbon command icons distinct from their dark button surfaces', () => {
        expect(source).toContain('.designerRibbon .ribbonCommand:not(:disabled)');
        expect(source).toContain('color: #dceff0;');
        expect(source).toContain('.designerRibbon .ribbonCommand:disabled .anticon');
        expect(source).toContain('color: #a6bbc0;');
        expect(source).toMatch(/\.designerRibbon \.ribbonCommand:disabled \{[\s\S]*?opacity: 1;/);
    });

    test('keeps native dialog select options legible on dark surfaces', () => {
        expect(source).toContain('.designerShell .layui-layer-content select option');
        expect(source).toContain('color: #e7f0f2;');
        expect(source).toContain('background: #273137;');
    });

    test('keeps property panel native select options legible on dark surfaces', () => {
        expect(source).toContain('.designerShell .attrBox select option');
        expect(source).toContain('.designerShell .attrBox select option:checked');
    });
});
