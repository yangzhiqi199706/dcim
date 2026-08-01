import { shouldRequireDesignerLogin } from './designerLoginAccess';

describe('shouldRequireDesignerLogin', () => {
    test('bypasses login for local development hosts', () => {
        expect(shouldRequireDesignerLogin('localhost')).toBe(false);
        expect(shouldRequireDesignerLogin('127.0.0.1')).toBe(false);
        expect(shouldRequireDesignerLogin('[::1]')).toBe(false);
    });

    test('requires login for non-local hosts', () => {
        expect(shouldRequireDesignerLogin('192.168.0.60')).toBe(true);
        expect(shouldRequireDesignerLogin('designer.example.test')).toBe(true);
    });
});
