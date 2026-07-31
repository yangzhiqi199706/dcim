import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import DataSourceHealthModal from './DataSourceHealthModal';

global.IS_REACT_ACT_ENVIRONMENT = true;
if (!global.ShadowRoot) global.ShadowRoot = function ShadowRoot() {};

const report = {
    counts: {
        available: 1,
        missing: 0,
        invalid: 0,
        unavailable: 0,
        unknown: 0,
    },
    items: [{
        elementId: 'meter-1',
        index: 0,
        label: 'Load',
        bindingSummary: 'Load',
        status: 'available',
    }],
};

describe('DataSourceHealthModal', () => {
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

    test('locates the selected health item and requests a manual refresh', () => {
        const onLocate = jest.fn();
        const onRefresh = jest.fn();

        act(() => {
            root.render(
                <DataSourceHealthModal
                    open
                    report={report}
                    loading={false}
                    loadError={false}
                    onClose={() => {}}
                    onLocate={onLocate}
                    onRefresh={onRefresh}
                />
            );
        });

        const item = container.querySelector('[data-health-element-id="meter-1"]');
        const refresh = container.querySelector('[data-health-refresh]');
        expect(item).not.toBeNull();
        expect(refresh).not.toBeNull();

        act(() => {
            item.dispatchEvent(new MouseEvent('click', { bubbles: true }));
            refresh.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        });

        expect(onLocate).toHaveBeenCalledWith('meter-1');
        expect(onRefresh).toHaveBeenCalledTimes(1);
    });

    test('shows a snapshot error without removing the previous report', () => {
        act(() => {
            root.render(
                <DataSourceHealthModal
                    open
                    report={report}
                    loading={false}
                    loadError
                    onClose={() => {}}
                    onLocate={() => {}}
                    onRefresh={() => {}}
                />
            );
        });

        expect(container.querySelector('[data-health-load-error]')).not.toBeNull();
        expect(container.querySelector('[data-health-element-id="meter-1"]')).not.toBeNull();
    });
});
