import fs from 'fs';
import path from 'path';

describe('designer keyboard shortcuts help', () => {
    const source = fs.readFileSync(path.join(__dirname, 'DesignerApp.js'), 'utf8');
    const ribbonSource = fs.readFileSync(path.join(__dirname, 'DesignerRibbonToolbar.js'), 'utf8');

    test('opens and closes the shortcut dialog from the ribbon overflow menu', () => {
        expect(source).toContain("import DesignerRibbonToolbar from './DesignerRibbonToolbar';");
        expect(source).toContain("import KeyboardShortcutsModal from './KeyboardShortcutsModal';");
        expect(source).toContain('const [keyboardShortcutsOpen, setKeyboardShortcutsOpen] = useState(false);');
        expect(source).toContain('openShortcuts: () => setKeyboardShortcutsOpen(true)');
        expect(source).toContain('<KeyboardShortcutsModal');
        expect(source).toContain('open={keyboardShortcutsOpen}');
        expect(source).toContain('onClose={() => setKeyboardShortcutsOpen(false)}');
        expect(ribbonSource).toContain('icon: <KeyOutlined />');
        expect(ribbonSource).toContain('onClick: commandHandlers.openShortcuts');
    });
});
