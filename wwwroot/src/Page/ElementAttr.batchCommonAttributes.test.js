jest.mock('../Assets/httpsend', () => ({
    getData: jest.fn(),
}));

import * as ElementAttr from './ElementAttr';

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

        expect(typeof ElementAttr.getBatchCommonAttributeGroups).toBe('function');
        expect(ElementAttr.getBatchCommonAttributeGroups([first, second])).toEqual([{
            name: 'Text settings',
            attributes: textAttributes,
        }]);
    });

    test('applies a common property only to selected compatible text elements', () => {
        const first = createTextShape('first');
        const second = createTextShape('second', { fontSize: 24 });
        const untouched = createTextShape('untouched', { fontSize: 12 });
        const attribute = textAttributes[2];

        expect(typeof ElementAttr.applyCommonAttributeToSelection).toBe('function');
        const updated = ElementAttr.applyCommonAttributeToSelection(
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
});
