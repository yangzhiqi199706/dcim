jest.mock('../Assets/httpsend', () => ({
    getData: jest.fn(),
}));

import React, { act, useState } from 'react';
import { createRoot } from 'react-dom/client';
import ElementAttr, * as ElementAttrUtils from './ElementAttr';

const textAttributes = [
    { attrName: 'Text', attrCode: 'text', attrType: 'textarea', attrWhere: 'description' },
    { attrName: 'Line height', attrCode: 'lineHeight', attrType: 'number', attrWhere: 'description' },
    { attrName: 'Font size', attrCode: 'fontSize', attrType: 'number', attrWhere: 'description' },
    { attrName: 'Font color', attrCode: 'fill', attrType: 'color', attrWhere: 'description' },
    { attrName: 'Font family', attrCode: 'fontFamily', attrType: 'selectFamily', attrWhere: 'description' },
    { attrName: 'Font style', attrCode: 'fontStyle', attrType: 'select', attrWhere: 'description' },
];

const createTextShape = (id, values = {}) => ({
    id,
    moduleJson: {
        attrs: {
            moduleAttr: [{
                attrGroupName: 'Text settings',
                attrGroupContent: textAttributes,
            }],
        },
        children: [{
            attrs: {
                name: 'description',
                text: 'Text',
                lineHeight: 1,
                fontSize: 18,
                fill: '#000000',
                fontFamily: 'Microsoft YaHei',
                fontStyle: 'normal',
                ...values,
            },
        }],
    },
});

describe('ElementAttr batch common attributes', () => {
    test('finds every shared editable text property for two text elements', () => {
        const first = createTextShape('first');
        const second = createTextShape('second', { fontSize: 24 });

        expect(typeof ElementAttrUtils.getBatchCommonAttributeGroups).toBe('function');
        expect(ElementAttrUtils.getBatchCommonAttributeGroups([first, second])).toEqual([{
            name: 'Text settings',
            attributes: textAttributes,
        }]);
    });

    test('applies a common property only to selected compatible text elements', () => {
        const first = createTextShape('first');
        const second = createTextShape('second', { fontSize: 24 });
        const untouched = createTextShape('untouched', { fontSize: 12 });
        const attribute = textAttributes[2];

        expect(typeof ElementAttrUtils.applyCommonAttributeToSelection).toBe('function');
        const updated = ElementAttrUtils.applyCommonAttributeToSelection(
            [first, second, untouched],
            ['first', 'second'],
            attribute,
            '30',
        );

        expect(updated[0].moduleJson.children[0].attrs.fontSize).toBe(30);
        expect(updated[1].moduleJson.children[0].attrs.fontSize).toBe(30);
        expect(updated[2]).toBe(untouched);
        expect(first.moduleJson.children[0].attrs.fontSize).toBe(18);
    });

    test('keeps the batch color input mounted while applying continuous color changes', () => {
        const attribute = textAttributes[3];
        const container = document.createElement('div');
        const root = createRoot(container);
        const appliedValues = [];
        const Harness = () => {
            const [shapes, setShapes] = useState([
                createTextShape('first', { fill: '#000000' }),
                createTextShape('second', { fill: '#000000' }),
            ]);
            return <ElementAttr
                MultiSelect
                selectedShapes={shapes}
                onBatchCommonAttributeChange={(nextAttribute, value) => {
                    appliedValues.push(value);
                    setShapes((current) => ElementAttrUtils.applyCommonAttributeToSelection(
                        current,
                        ['first', 'second'],
                        nextAttribute,
                        value,
                    ));
                }}
            />;
        };

        global.IS_REACT_ACT_ENVIRONMENT = true;
        act(() => {
            root.render(<Harness />);
        });
        const colorInput = container.querySelector('input[type="color"]');
        expect(colorInput).not.toBeNull();

        act(() => {
            const setNativeValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
            setNativeValue.call(colorInput, '#00ff00');
            colorInput.dispatchEvent(new Event('input', { bubbles: true }));
        });

        expect(appliedValues).toEqual(['#00ff00']);
        expect(container.querySelector('input[type="color"]')).toBe(colorInput);
        expect(colorInput.value).toBe('#00ff00');
        act(() => root.unmount());
        delete global.IS_REACT_ACT_ENVIRONMENT;
    });
});
