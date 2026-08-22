const fs = require('fs');
const path = require('path');

const prototypeFiles = [
  'workbench-graphite.html',
  'workbench-paper.html',
  'workbench-signal.html',
];

const requiredMarkers = [
  '<meta name="viewport"',
  'data-design-prototype=',
  '<main class="workbench"',
  'aria-label=',
  '<button',
  '@media (prefers-reduced-motion: reduce)',
];

const failures = [];

for (const file of prototypeFiles) {
  const filePath = path.join(__dirname, file);
  if (!fs.existsSync(filePath)) {
    failures.push(`${file} is missing`);
    continue;
  }

  const source = fs.readFileSync(filePath, 'utf8');
  for (const marker of requiredMarkers) {
    if (!source.includes(marker)) failures.push(`${file} is missing ${marker}`);
  }
  if (/https?:\/\//i.test(source)) failures.push(`${file} must not load remote resources`);
}

const indexPath = path.join(__dirname, 'index.html');
if (!fs.existsSync(indexPath)) failures.push('index.html is missing');

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log(`Validated ${prototypeFiles.length} offline design prototypes.`);
