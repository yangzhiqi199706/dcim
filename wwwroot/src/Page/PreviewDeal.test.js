import PreviewDeal, { createProtocolNormalizer } from './PreviewDeal';
import { t } from '../i18n';

describe('createProtocolNormalizer', () => {
    test('reuses the normalized protocol list for the same response object', () => {
        const normalize = jest.fn(PreviewDeal.normalizeProtocol);
        const getNormalizedProtocol = createProtocolNormalizer(normalize);
        const response = { data: [{ ProtocolCode: 'P', comType: '1', keyName: 'run', keyDesc: ['1=on'] }] };

        const first = getNormalizedProtocol(response);
        const second = getNormalizedProtocol(response);

        expect(second).toBe(first);
        expect(normalize).toHaveBeenCalledTimes(1);
    });

    test('normalizes again when a replacement protocol response arrives', () => {
        const normalize = jest.fn(PreviewDeal.normalizeProtocol);
        const getNormalizedProtocol = createProtocolNormalizer(normalize);

        getNormalizedProtocol({ data: [] });
        getNormalizedProtocol({ data: [] });

        expect(normalize).toHaveBeenCalledTimes(2);
    });
});

describe('PreviewDeal', () => {
    test('returns a realtime text binding in the same call', () => {
        const source = {
            id: 'live-text',
            moduleJson: {
                attrs: {
                    dataKey: [{ key: '1', name: 'temperature' }],
                    moduleAttr: [{
                        attrGroupName: t('auto.k0601'),
                        attrGroupContent: [{ attrCode: 'dataKey' }]
                    }]
                },
                children: [{
                    className: 'Text',
                    attrs: { text: 'placeholder' }
                }]
            }
        };
        const realtime = {
            data: [{
                DevID: '1',
                CommandType: 'A1',
                ProtocolCode: '1004',
                LastReceiveData: "{'temperature':'27.3(C)'}"
            }]
        };

        const result = PreviewDeal.PreviewDeal([source], { data: [] }, realtime);

        expect(result).toHaveLength(1);
        expect(result[0].moduleJson.children[0].attrs.text).toBe('27.3');
    });

});
