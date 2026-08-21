import fs from 'fs';
import path from 'path';

const sourceRoot = path.join(__dirname, '..');
const readSource = (name) => fs.readFileSync(path.join(sourceRoot, name), 'utf8');

describe('legacy component rendering', () => {
    test('loads temperature and bubble backgrounds through the shared bundle', () => {
        const stylePath = path.join(sourceRoot, 'Assets', 'componentBackgrounds.css');

        expect(readSource('Assets/base.css')).toContain("@import './componentBackgrounds.css';");
        expect(fs.existsSync(stylePath)).toBe(true);

        const styles = fs.readFileSync(stylePath, 'utf8');
        expect(styles).toContain('.numstatus');
        expect(styles).toContain("url('./images/status.png')");
        expect(styles).toContain('.tipstxt');
        expect(styles).toContain("url('./images/pao.png')");
    });

    test('renders paoHtml components in both designer and preview modes', () => {
        expect(readSource('Page/ConElement.js')).toContain("Ele === 'paoHtml'");
        expect(readSource('Page/PreviewElement.js')).toContain("Ele === 'paoHtml'");
    });

    test('keeps other legacy component backgrounds in the shared bundle', () => {
        const styles = readSource('Assets/componentBackgrounds.css');

        expect(styles).toContain('.param-status');
        expect(styles).toContain("url('./images/lang.png')");
        expect(styles).toContain('.alarmList thead tr');
        expect(styles).toContain("url('./images/list-head.png')");
        expect(styles).toContain('.rope');
        expect(styles).toContain("url('./images/rope.jpg')");
    });

    test('keeps leak rope dialog styling in the shared bundle', () => {
        const styles = readSource('Assets/componentBackgrounds.css');

        expect(styles).toContain('.bubble {');
        expect(styles).toContain('.bubbleTop');
        expect(styles).toContain('.bubbleBottom');
        expect(styles).toContain('.bubbleLeft');
        expect(styles).toContain('.bubbleRight');
        expect(styles).toContain('.bubble::after');
        expect(styles).toContain('.bubble span');
    });
});
