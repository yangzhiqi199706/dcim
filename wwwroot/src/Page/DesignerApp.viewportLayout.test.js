import fs from 'fs';
import path from 'path';

const readDesignerStyle = () => fs.readFileSync(path.join(__dirname, '..', 'Assets', 'designer.css'), 'utf8');

describe('designer viewport layout', () => {
    test('keeps the canvas workspace within the viewport below the header', () => {
        const style = readDesignerStyle().replace(/\s+/g, '');

        expect(style).toContain('.designerShell.top{min-height:64px');
        expect(style).toContain('.designerShell.left,.designerShell.eleAttrs{height:calc(100vh-64px)');
        expect(style).toContain('.designerShell.canvasStage{width:calc(100vw-596px);height:calc(100vh-104px)');
        expect(style).toContain('.designerShell.canvasStage2{margin:16pxauto24px');
    });
});
