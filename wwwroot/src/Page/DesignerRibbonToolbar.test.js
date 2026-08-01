import fs from 'fs';
import path from 'path';

describe('DesignerRibbonToolbar', () => {
    test('exposes contextual tabs and an overflow entry point', () => {
        const source = fs.readFileSync(path.join(__dirname, 'DesignerRibbonToolbar.js'), 'utf8');

        expect(source).toContain('role="tablist"');
        expect(source).toContain('aria-selected={activeTab === tab.id}');
        expect(source).toContain('ribbonToolbarMore');
        expect(source).toContain('getRibbonToolbarGroups(activeTab)');
    });
});
