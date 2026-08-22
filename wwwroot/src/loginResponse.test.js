import fs from 'fs';
import path from 'path';

const helperPath = path.join(__dirname, '..', 'public', 'js', 'login-response.js');
const loginPath = path.join(__dirname, '..', 'public', 'login.html');

describe('login response compatibility', () => {
  test('provides a local response parser', () => {
    expect(fs.existsSync(helperPath)).toBe(true);
  });

  test('accepts JSON strings and pre-parsed objects', () => {
    if (!fs.existsSync(helperPath)) return;
    const parseLoginResponse = require('../public/js/login-response');
    const response = { code: 100, data: { token: 'token' } };

    expect(parseLoginResponse(JSON.stringify(response))).toEqual(response);
    expect(parseLoginResponse(response)).toBe(response);
  });

  test('rejects empty or malformed responses', () => {
    if (!fs.existsSync(helperPath)) return;
    const parseLoginResponse = require('../public/js/login-response');

    expect(() => parseLoginResponse('{')).toThrow('Invalid login response');
    expect(() => parseLoginResponse(null)).toThrow('Invalid login response');
  });

  test('login page loads and uses the parser with an error boundary', () => {
    const source = fs.readFileSync(loginPath, 'utf8');

    expect(source).toContain('<script type="text/JavaScript" src="./js/login-response.js"></script>');
    expect(source).toContain('res = window.parseLoginResponse(r);');
    expect(source).toContain('Login response parse error');
  });
});
