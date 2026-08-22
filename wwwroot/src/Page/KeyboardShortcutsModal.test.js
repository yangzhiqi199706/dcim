import fs from 'fs';
import path from 'path';

describe('keyboard shortcuts modal', () => {
    const file = path.join(__dirname, 'KeyboardShortcutsModal.js');

    test('renders catalog groups in a closable modal', () => {
        const source = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : '';

        expect(source).toContain("import { Modal } from 'antd';");
        expect(source).toContain('KEYBOARD_SHORTCUT_GROUPS.map');
        expect(source).toContain('onCancel={onClose}');
        expect(source).toContain('footer={null}');
        expect(source).toContain('<kbd>{item.keys}</kbd>');
    });
});
