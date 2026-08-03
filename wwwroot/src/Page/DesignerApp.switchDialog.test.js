import fs from 'fs';
import path from 'path';

describe('DesignerApp switch confirmation actions', () => {
    test('uses a separated, wrapping action layout for save, discard, and cancel', () => {
        const source = fs.readFileSync(path.join(__dirname, 'DesignerApp.js'), 'utf8');
        const style = fs.readFileSync(path.join(__dirname, '..', 'Assets', 'designer.css'), 'utf8');

        expect(source).toContain('className="layui-layer-btn switchConfirmActions"');
        expect(style).toContain('.designerShell .layui-layer-btn.switchConfirmActions');
        expect(style).toContain('gap: 8px;');
        expect(style).toContain('flex-wrap: wrap;');
    });
});
