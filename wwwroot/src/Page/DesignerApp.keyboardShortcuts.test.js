import fs from 'fs';
import path from 'path';

describe('designer keyboard shortcuts help', () => {
    const source = fs.readFileSync(path.join(__dirname, 'DesignerApp.js'), 'utf8');

    test('opens and closes the shortcut dialog from the top controls', () => {
        expect(source).toContain("import { KeyOutlined } from '@ant-design/icons';");
        expect(source).toContain("import KeyboardShortcutsModal from './KeyboardShortcutsModal';");
        expect(source).toContain('const [keyboardShortcutsOpen, setKeyboardShortcutsOpen] = useState(false);');
        expect(source).toContain('onClick={() => setKeyboardShortcutsOpen(true)}');
        expect(source).toContain('<KeyboardShortcutsModal');
        expect(source).toContain('open={keyboardShortcutsOpen}');
        expect(source).toContain('onClose={() => setKeyboardShortcutsOpen(false)}');
    });
});
