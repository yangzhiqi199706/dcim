import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import { Simulate } from 'react-dom/test-utils';
import DataSimulationModal from './DataSimulationModal';

global.IS_REACT_ACT_ENVIRONMENT = true;
if (!global.ShadowRoot) global.ShadowRoot = function ShadowRoot() {};

const elements = [{
    id: 'load',
    moduleJson: {
        attrs: { dataKey: [{ key: '42', name: 'Load' }] },
        children: [{ className: 'Text', attrs: { text: '--' } }],
    },
}];

describe('DataSimulationModal', () => {
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

    test('returns edited values using the element id as the override key', () => {
        const onValuesChange = jest.fn();

        act(() => {
            root.render(
                <DataSimulationModal
                    open
                    enabled={false}
                    elements={elements}
                    values={{}}
                    onClose={() => {}}
                    onEnabledChange={() => {}}
                    onReset={() => {}}
                    onValuesChange={onValuesChange}
                />
            );
        });

        const input = container.querySelector('[data-simulation-id="load"]');
        expect(input).not.toBeNull();

        act(() => {
            Simulate.change(input, { target: { value: '42.5' } });
        });

        expect(onValuesChange).toHaveBeenCalledWith({ load: '42.5' });
    });
});
