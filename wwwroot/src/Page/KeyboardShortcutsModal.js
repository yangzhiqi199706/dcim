import React from 'react';
import { Modal } from 'antd';
import { t } from '../i18n';
import { KEYBOARD_SHORTCUT_GROUPS } from './keyboardShortcuts';

function KeyboardShortcutsModal({ open, onClose }) {
    return (
        <Modal
            title={t('designer.shortcuts.title')}
            open={open}
            onCancel={onClose}
            footer={null}
            width={680}
            className="keyboardShortcutsModal"
        >
            <div className="keyboardShortcutsList">
                {KEYBOARD_SHORTCUT_GROUPS.map((group) => (
                    <section className="keyboardShortcutsGroup" key={group.titleKey}>
                        <h3>{t(group.titleKey)}</h3>
                        {group.items.map((item) => (
                            <div className="keyboardShortcutsRow" key={item.keys}>
                                <kbd>{item.keys}</kbd>
                                <span>{t(item.actionKey)}</span>
                            </div>
                        ))}
                    </section>
                ))}
            </div>
        </Modal>
    );
}

export default KeyboardShortcutsModal;
