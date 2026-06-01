const childProcess = require('child_process');
const fs = require('fs');
const path = require('path');

const { runImagePackageBatch, runImagePackagePipeline } = require('./pipeline');
const { parseZipEntries } = require('./templateImporter');

const repoRoot = path.resolve(__dirname, '../..');
const samplePng = path.resolve(repoRoot, '../preview_import_original/img_uploads_1727243565.png');
const sampleZip = path.resolve(repoRoot, '../机房环境温湿度监测系统 (1)[79] (1).zip');
const sampleWetOcr = [
  { text: '6#', x: 320, y: 420, width: 24, height: 18 },
  { text: '24.8', x: 362, y: 417, width: 42, height: 18 },
  { text: '51.6', x: 362, y: 445, width: 42, height: 18 },
];
const sampleMultiWetOcr = [
  ...sampleWetOcr,
  { text: '7#', x: 390, y: 420, width: 24, height: 18 },
  { text: '26.1', x: 432, y: 417, width: 42, height: 18 },
  { text: '49.3', x: 432, y: 445, width: 42, height: 18 },
];

function runTestSuite() {
  childProcess.execFileSync(process.execPath, ['scripts/image-package/__tests__/run-tests.js'], {
    cwd: repoRoot,
    stdio: 'pipe',
  });
}

function readStageFromZip(zipPath) {
  const txtEntry = parseZipEntries(fs.readFileSync(zipPath))
    .find((entry) => path.extname(entry.name).toLowerCase() === '.txt');
  return JSON.parse(JSON.parse(txtEntry.data.toString('utf8')));
}

function countComponentsInZip(zipPath) {
  const stage = readStageFromZip(zipPath);
  return stage.children[0].children.reduce((counts, node) => {
    if (node.attrs && node.attrs.moduleJson) {
      const name = node.attrs.moduleJson.children[0].className;
      counts[name] = (counts[name] || 0) + 1;
    }
    return counts;
  }, {});
}

function summarizeResult(label, result) {
  const manifest = JSON.parse(fs.readFileSync(result.manifestPath, 'utf8'));
  return {
    label,
    status: manifest.status,
    zipPath: result.package.zipPath,
    manifestPath: result.manifestPath,
    recognitionPath: result.recognitionPath,
    components: countComponentsInZip(result.package.zipPath),
    elementCounts: manifest.elementCounts,
  };
}

function runAcceptance() {
  runTestSuite();
  const outputDir = path.resolve(repoRoot, 'public/Images/exports');
  const timestamp = Date.now();
  const single = runImagePackagePipeline({
    imagePath: samplePng,
    templateZip: sampleZip,
    pageName: 'acceptance-single',
    pageIndex: '201',
    outputDir,
    includeTemplateElements: false,
    detectShapes: false,
    detectWetHtml: true,
    ocrItems: sampleWetOcr,
    chartElements: [{ cat: 'bar', x: 600, y: 200, width: 320, height: 180, title: 'Load' }],
  });
  const templateAssisted = runImagePackagePipeline({
    imagePath: samplePng,
    templateZip: sampleZip,
    pageName: 'acceptance-template-assisted',
    pageIndex: '204',
    outputDir,
    includeTemplateElements: false,
    detectShapes: false,
    recognizeTemplateComponents: true,
    templateComponentClasses: ['wetHtml', 'leakWater'],
  });
  const batch = runImagePackageBatch({
    imagePath: samplePng,
    templateZip: sampleZip,
    outputDir,
    includeTemplateElements: false,
    detectShapes: false,
    detectWetHtml: true,
  }, [
    {
      pageName: 'acceptance-batch-a',
      pageIndex: '202',
      ocrItems: sampleWetOcr,
    },
    {
      pageName: 'acceptance-batch-b',
      pageIndex: '203',
      ocrItems: sampleMultiWetOcr,
      chartElements: [{ cat: 'bar', x: 600, y: 200, width: 320, height: 180, title: 'Load' }],
    },
  ]);
  const results = [
    summarizeResult('single', single),
    summarizeResult('template-assisted', templateAssisted),
    ...batch.map((item, index) => summarizeResult(`batch-${index + 1}`, item)),
  ];
  const failed = results.filter((item) => item.status !== 'PASS');
  return {
    status: failed.length === 0 ? 'PASS' : 'FAIL',
    generatedAt: new Date().toISOString(),
    reportPath: path.join(outputDir, `image-package-acceptance_${timestamp}.json`),
    testSuite: 'PASS',
    results,
  };
}

function writeAcceptanceReport(report) {
  const latestPath = path.join(path.dirname(report.reportPath), 'image-package-acceptance-latest.json');
  const markdownPath = report.reportPath.replace(/\.json$/i, '.md');
  const latestMarkdownPath = path.join(path.dirname(report.reportPath), 'image-package-acceptance-latest.md');
  const reportWithLatest = {
    ...report,
    latestReportPath: latestPath,
    markdownPath,
    latestMarkdownPath,
  };
  fs.writeFileSync(report.reportPath, `${JSON.stringify(reportWithLatest, null, 2)}\n`, 'utf8');
  fs.writeFileSync(latestPath, `${JSON.stringify(reportWithLatest, null, 2)}\n`, 'utf8');
  fs.writeFileSync(markdownPath, formatAcceptanceMarkdown(reportWithLatest), 'utf8');
  fs.writeFileSync(latestMarkdownPath, formatAcceptanceMarkdown(reportWithLatest), 'utf8');
  return reportWithLatest;
}

function formatComponentCounts(components) {
  const entries = Object.entries(components || {});
  return entries.length > 0
    ? entries.map(([name, count]) => `${name}=${count}`).join(', ')
    : 'none';
}

function formatAcceptanceMarkdown(report) {
  const lines = [
    '# Image Package Acceptance',
    '',
    `Status: ${report.status}`,
    `Generated At: ${report.generatedAt || ''}`,
    `Test Suite: ${report.testSuite || ''}`,
    '',
    '| Label | Status | Components | Zip |',
    '| --- | --- | --- | --- |',
  ];
  (Array.isArray(report.results) ? report.results : []).forEach((item) => {
    lines.push(`| ${item.label} | ${item.status} | ${formatComponentCounts(item.components)} | ${item.zipPath} |`);
  });
  lines.push('');
  return `${lines.join('\n')}\n`;
}

function main() {
  const report = writeAcceptanceReport(runAcceptance());
  console.log(JSON.stringify(report, null, 2));
  if (report.status !== 'PASS') process.exit(1);
}

if (require.main === module) main();

module.exports = {
  countComponentsInZip,
  formatAcceptanceMarkdown,
  runAcceptance,
  writeAcceptanceReport,
};
