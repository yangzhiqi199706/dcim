import fs from 'fs';
import path from 'path';

const readStyle = () => fs.readFileSync(path.join(__dirname, '..', 'Assets', 'style.css'), 'utf8');

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
});
