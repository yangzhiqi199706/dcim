import fs from 'fs';
import path from 'path';

describe('RemoteSyncModal', () => {
    const file = path.join(__dirname, 'RemoteSyncModal.js');

    test('provides preflight, start, and status requests without storing credentials', () => {
        const source = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : '';

        expect(source).toContain("getDataLocal('remoteSyncPreflight'");
        expect(source).toContain("getDataLocal('remoteSyncStart'");
        expect(source).toContain("getDataLocal('remoteSyncStatus'");
        expect(source).toContain('type="password"');
        expect(source).toContain('data-remote-sync-confirm');
        expect(source).not.toContain('localStorage.setItem');
        expect(source).not.toContain('sessionStorage.setItem');
        expect(source).not.toContain('console.log');
    });
});
