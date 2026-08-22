import enUS from '../i18n/dictionaries/en-US';
import zhCN from '../i18n/dictionaries/zh-CN';

const requiredKeys = [
    'triggerLabel',
    'title',
    'original',
    'replacement',
    'selectionRequired',
    'sameDeviceRequired',
    'protocolBindingRequired',
    'unavailable',
    'selectOriginal',
    'selectReplacement',
    'nothingMatched',
    'selectionChanged',
    'replacedCount',
];

describe('parameter replacement translations', () => {
    test.each([
        ['zh-CN', zhCN],
        ['en-US', enUS],
    ])('%s provides every parameter replacement message', (locale, dictionary) => {
        requiredKeys.forEach((key) => {
            expect(dictionary.parameterReplacement[key]).toEqual(expect.any(String));
            expect(dictionary.parameterReplacement[key]).not.toBe('');
        });
    });
});
