import fs from 'fs';
import path from 'path';

describe('VIBuilder runtime endpoints', () => {
    test('routes local page files through the 8086 API service', () => {
        const source = fs.readFileSync(path.join(__dirname, '../../public/runtime-endpoints.js'), 'utf8');

        expect(source).toContain('localApiBase: `${window.location.protocol}//${window.location.hostname}:8086/api/local/`');
        expect(source).not.toContain('localApiBase: \'/api/local/\'');
    });
});
