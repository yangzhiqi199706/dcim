# Keyboard Shortcuts Dialog Design

## Goal

Provide a discoverable help control in the designer toolbar. Selecting it opens a
complete, read-only keyboard shortcuts dialog so operators can learn existing
editor commands without changing the canvas state.

## User Experience

- Place a compact keyboard-icon button in the top controls group, next to snap,
  group, and ungroup controls.
- Give the button an accessible label and tooltip named "Keyboard shortcuts".
- Open an Ant Design modal when selected. Standard close control, Escape, and
  mask close behavior remain enabled.
- The modal groups commands by purpose and presents each command as a key label
  paired with its localized action description.
- Add a mouse interaction note for `Ctrl/Cmd + click` multi-selection.

## Shortcut Content

The dialog documents only commands currently implemented in `DesignerApp.js`:

1. Selection and movement: multi-select, arrow-key movement, and Shift-arrow
   accelerated movement.
2. Grouping: `Ctrl+G` group and `Ctrl+Shift+J` ungroup.
3. Clipboard and editing: copy, cut, paste, undo, delete, and text replacement.
4. Layer and lock management: move to top/bottom, move layer up/down, lock, and
   unlock.
5. Page action: save where a page supports saving.

## Implementation Boundaries

- Keep the dialog state local to the designer component.
- Store row metadata in a small module or component-local constant; render it
  with the existing i18n helper so both configured locales remain complete.
- Do not alter existing key handlers, command availability, selection logic, or
  preview mode.
- Do not show the control in preview mode.

## Testing

- Test that shortcut metadata covers the implemented combination and movement
  commands used in the dialog.
- Test that the help control opens and closes the dialog.
- Retain the existing application-entry tests to confirm preview mode does not
  render designer controls.
