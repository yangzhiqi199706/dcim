import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import PreflightModal from './PreflightModal';

global.IS_REACT_ACT_ENVIRONMENT = true;
if (!global.ShadowRoot) global.ShadowRoot = function ShadowRoot() {};

describe('PreflightModal', () => {
    let container;
    let root;

    beforeEach(() => {
        container = document.createElement('div');
        document.body.appendChild(container);
        root = createRoot(container);
    });

    afterEach(() => {
        act(() => root.unmount());
        document.body.removeChild(container);
    });

    test('locates the element selected from a finding', () => {
        const onLocate = jest.fn();

        act(() => {
            root.render(
                <PreflightModal
                    open
                    findings={[{ code: 'out-of-bounds', severity: 'warning', elementId: 'meter-1', index: 0 }]}
                    onClose={() => {}}
                    onLocate={onLocate}
                />
            );
        });

        const finding = container.querySelector('[data-element-id="meter-1"]');
        expect(finding).not.toBeNull();

        act(() => {
            finding.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        });

        expect(onLocate).toHaveBeenCalledWith('meter-1');
    });
});
