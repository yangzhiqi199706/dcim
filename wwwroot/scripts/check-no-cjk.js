const fs = require('fs');
const path = require('path');

const projectRoot = process.cwd();
const targetRoot = path.join(projectRoot, 'src');

const includeExtensions = new Set(['.js', '.jsx', '.ts', '.tsx', '.json']);
const ignoreDirs = new Set([
  path.normalize(path.join(targetRoot, 'i18n', 'dictionaries')),
  path.normalize(path.join(targetRoot, 'Assets', 'style.css')),
]);

const cjkRegex = /[\u4e00-\u9fff]/;

const findings = [];

function shouldIgnoreDir(dirPath) {
  const normalized = path.normalize(dirPath);
  for (const ignored of ignoreDirs) {
    if (normalized === ignored || normalized.startsWith(ignored + path.sep)) {
      return true;
    }
  }
  return false;
}

function walk(dirPath) {
  if (shouldIgnoreDir(dirPath)) {
    return;
  }

  const entries = fs.readdirSync(dirPath, { withFileTypes: true });
  for (const entry of entries) {
    const fullPath = path.join(dirPath, entry.name);
    if (entry.isDirectory()) {
      walk(fullPath);
      continue;
    }

    if (!includeExtensions.has(path.extname(entry.name))) {
      continue;
    }

    const content = fs.readFileSync(fullPath, 'utf8');
    const lines = content.split(/\r?\n/);
    lines.forEach((line, index) => {
      if (cjkRegex.test(line)) {
        findings.push({ file: fullPath, line: index + 1, text: line.trim() });
      }
    });
  }
}

walk(targetRoot);

if (findings.length > 0) {
  console.error('Found CJK characters outside allowed dictionary files:');
  findings.slice(0, 200).forEach((item) => {
    console.error(`${item.file}:${item.line}: ${item.text}`);
  });
  if (findings.length > 200) {
    console.error(`...and ${findings.length - 200} more matches.`);
  }
  process.exit(1);
}

console.log('No CJK characters found outside dictionary files.');