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

    test('keeps the remote synchronization label visible in the system group', () => {
        const style = fs.readFileSync(path.join(__dirname, '..', 'Assets', 'designer.css'), 'utf8');
        const compactDesktopRules = style.slice(
            style.indexOf('@media (max-width: 1320px)'),
            style.indexOf('@media (max-width: 980px)')
        );

        expect(style).toMatch(/\.ribbonGroup-system \.ribbonCommandLabel,[\s\S]*?\{[\s\S]*?display:\s*inline;/);
        expect(compactDesktopRules).not.toContain('.ribbonGroup-system .ribbonCommandLabel');
    });
});
