const fs = require('fs');
const path = require('path');

const projectRoot = process.cwd();
const scanRoots = ['src', 'server', 'scripts']
  .map((dir) => path.join(projectRoot, dir))
  .filter((absPath) => fs.existsSync(absPath) && fs.statSync(absPath).isDirectory());

const includeExtensions = new Set(['.js', '.jsx', '.ts', '.tsx', '.json']);

const ignoredDirs = [
  path.join(projectRoot, 'src', 'i18n', 'dictionaries'),
].map((absPath) => path.normalize(absPath).toLowerCase());

const cjkRegex = /[\u3400-\u9fff\uf900-\ufaff]/;
const replacementCharRegex = /\uFFFD/;
const suspiciousMojibakeRegex = /[\u0400-\u04ff]/;

const findings = [];

function normalizeForCompare(targetPath) {
  return path.normalize(targetPath).toLowerCase();
}

function shouldIgnorePath(targetPath) {
  const normalized = normalizeForCompare(targetPath);
  return ignoredDirs.some((ignored) => (
    normalized === ignored || normalized.startsWith(`${ignored}${path.sep}`)
  ));
}

function pushFinding(type, filePath, lineNumber, lineText) {
  findings.push({
    type,
    file: filePath,
    line: lineNumber,
    text: lineText.trim(),
  });
}

function scanFile(filePath) {
  const content = fs.readFileSync(filePath, 'utf8');
  const lines = content.split(/\r?\n/);

  lines.forEach((line, index) => {
    const lineNumber = index + 1;

    if (cjkRegex.test(line)) {
      pushFinding('CJK', filePath, lineNumber, line);
    }
    if (replacementCharRegex.test(line)) {
      pushFinding('U+FFFD', filePath, lineNumber, line);
    }
    if (suspiciousMojibakeRegex.test(line)) {
      pushFinding('SUSPECT_MOJIBAKE', filePath, lineNumber, line);
    }
  });
}

function walk(dirPath) {
  if (shouldIgnorePath(dirPath)) return;

  const entries = fs.readdirSync(dirPath, { withFileTypes: true });
  for (const entry of entries) {
    const fullPath = path.join(dirPath, entry.name);
    if (shouldIgnorePath(fullPath)) continue;

    if (entry.isDirectory()) {
      walk(fullPath);
      continue;
    }

    if (!includeExtensions.has(path.extname(entry.name).toLowerCase())) {
      continue;
    }

    scanFile(fullPath);
  }
}

scanRoots.forEach((rootDir) => walk(rootDir));

if (findings.length === 0) {
  console.log('Check passed: no CJK or mojibake-like characters found outside dictionaries.');
  process.exit(0);
}

const grouped = findings.reduce((acc, item) => {
  if (!acc[item.type]) acc[item.type] = [];
  acc[item.type].push(item);
  return acc;
}, {});

console.error('Check failed. Disallowed content found:');
console.error('Allowed CJK scope: src/i18n/dictionaries/**');

Object.keys(grouped).forEach((type) => {
  const items = grouped[type];
  console.error(`\n[${type}] ${items.length} hit(s)`);
  items.slice(0, 200).forEach((item) => {
    console.error(`${item.file}:${item.line}: ${item.text}`);
  });
  if (items.length > 200) {
    console.error(`...and ${items.length - 200} more.`);
  }
});

process.exit(1);
