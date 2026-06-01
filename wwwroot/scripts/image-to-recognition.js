#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const { createImageRecognition } = require('./image-package/recognizer');

function parseArgs(argv) {
  const result = {};
  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];
    if (!arg.startsWith('--')) continue;
    const key = arg.slice(2);
    const value = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[i + 1] : 'true';
    result[key] = value;
    if (value !== 'true') i += 1;
  }
  return result;
}

function readJsonArray(filePath, fallback = []) {
  if (!filePath) return fallback;
  const absPath = path.resolve(filePath);
  if (!fs.existsSync(absPath)) return fallback;
  const parsed = JSON.parse(fs.readFileSync(absPath, 'utf8'));
  return Array.isArray(parsed) ? parsed : fallback;
}

function ensureDirectory(filePath) {
  const dir = path.dirname(path.resolve(filePath));
  fs.mkdirSync(dir, { recursive: true });
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  if (!args.image) {
    console.error('Usage: node scripts/image-to-recognition.js --image <png> [--ocr ocr.json] [--detect-wet-html] [--detect-charts] [--out recognition.json]');
    process.exit(1);
  }

  const recognition = createImageRecognition({
    imagePath: args.image,
    ocrItems: readJsonArray(args.ocr),
    detectWetHtml: args['detect-wet-html'] === 'true' || args['detect-business-components'] === 'true',
    detectBusinessComponents: args['detect-business-components'] === 'true',
    detectCharts: args['detect-charts'] === 'true',
  });
  const json = `${JSON.stringify(recognition, null, 2)}\n`;

  if (args.out) {
    ensureDirectory(args.out);
    fs.writeFileSync(path.resolve(args.out), json, 'utf8');
  } else {
    process.stdout.write(json);
  }
}

main();
