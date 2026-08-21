import fs from 'fs';
import path from 'path';

const readStyle = () => fs.readFileSync(path.join(__dirname, '..', 'Assets', 'style.css'), 'utf8');
const readItemBox = () => fs.readFileSync(path.join(__dirname, 'ItemBox.js'), 'utf8');

describe('ItemBox action toolbar layout', () => {
    test('anchors the absolute action toolbar above palette search results', () => {
        const style = readStyle();
        const toolbarRule = style.match(/\.uploadBtn\{([^}]*)}/);

        expect(toolbarRule).not.toBeNull();
        const rule = toolbarRule[1].replace(/\s+/g, '');
        expect(rule).toContain('position:absolute');
        expect(rule).toContain('top:0');
        expect(rule).toContain('left:0');
    });

    test('uses a dedicated three-column page action toolbar with space for two rows', () => {
        const style = readStyle();
        const source = readItemBox();
        const toolbarRule = style.match(/\.pageActionToolbar\{([^}]*)}/);

        expect(source).toContain('className="uploadBtn pageActionToolbar"');
        expect(toolbarRule).not.toBeNull();

        const rule = toolbarRule[1].replace(/\s+/g, '');
        expect(rule).toContain('display:grid');
        expect(rule).toContain('grid-template-columns:repeat(3,minmax(0,1fr))');
        expect(rule).toContain('gap:6px');
        expect(rule).toContain('min-height:76px');
        expect(style).toContain('.pageActionToolbar button{width:100%;height:30px;font-size:12px;min-width:0;margin:0;padding:2px 4px;}');
        expect(source).toContain("<div style={{ marginTop: '84px' }}>");
    });

    test('keeps the destructive page action last in the toolbar order', () => {
        const source = readItemBox();
        const toolbar = source.match(/<div className="uploadBtn pageActionToolbar">([\s\S]*?)<\/div>\s*<div style=\{\{ marginTop: '84px' \}\}>/);

        expect(toolbar).not.toBeNull();
        const actions = toolbar[1];
        const importIndex = actions.indexOf("t('common.import')");
        const exportAllIndex = actions.indexOf("t('itemBox.exportAll')");
        const exportIndex = actions.indexOf("t('common.export')");
        const settingsIndex = actions.indexOf("t('common.settings')");
        const deleteIndex = actions.indexOf("t('common.delete')");

        expect(importIndex).toBeGreaterThanOrEqual(0);
        expect(exportAllIndex).toBeGreaterThan(importIndex);
        expect(exportIndex).toBeGreaterThan(exportAllIndex);
        expect(settingsIndex).toBeGreaterThan(exportIndex);
        expect(deleteIndex).toBeGreaterThan(settingsIndex);
    });
});
