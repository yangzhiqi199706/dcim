const assert = require('assert');
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const { attachLocalApiRoutes } = require('./local-api-routes');

const publicDir = path.resolve(__dirname, '../public');
const pageDir = path.join(publicDir, 'Images/page');
const uploadDir = path.join(publicDir, 'Images/uploads');
const exportDir = path.join(publicDir, 'Images/exports');
const testPrefix = `batch-export-route-test-${process.pid}`;
const firstPageName = `${testPrefix}-first`;
const secondPageName = `${testPrefix}-second`;
const firstPageFile = `${firstPageName}.txt`;
const secondPageFile = `${secondPageName}.txt`;
const uploadFile = `${testPrefix}.png`;
const handlers = {};
const createdExportFiles = [];

attachLocalApiRoutes({
  post(route, handler) {
    handlers[route] = handler;
  },
});

function readZipEntries(buffer) {
  let endOffset = -1;
  for (let offset = buffer.length - 22; offset >= 0; offset -= 1) {
    if (buffer.readUInt32LE(offset) === 0x06054b50) {
      endOffset = offset;
      break;
    }
  }

  assert.notStrictEqual(endOffset, -1, 'ZIP end record should exist');
  const entryCount = buffer.readUInt16LE(endOffset + 10);
  let cursor = buffer.readUInt32LE(endOffset + 16);
  const entries = [];

  for (let index = 0; index < entryCount; index += 1) {
    assert.strictEqual(buffer.readUInt32LE(cursor), 0x02014b50, 'ZIP central entry should exist');
    const compressionMethod = buffer.readUInt16LE(cursor + 10);
    const compressedSize = buffer.readUInt32LE(cursor + 20);
    const nameLength = buffer.readUInt16LE(cursor + 28);
    const extraLength = buffer.readUInt16LE(cursor + 30);
    const commentLength = buffer.readUInt16LE(cursor + 32);
    const localOffset = buffer.readUInt32LE(cursor + 42);
    const name = buffer.slice(cursor + 46, cursor + 46 + nameLength).toString('utf8');
    const localNameLength = buffer.readUInt16LE(localOffset + 26);
    const localExtraLength = buffer.readUInt16LE(localOffset + 28);
    const dataStart = localOffset + 30 + localNameLength + localExtraLength;
    const compressedData = buffer.slice(dataStart, dataStart + compressedSize);
    const data = compressionMethod === 8 ? zlib.inflateRawSync(compressedData) : compressedData;

    entries.push({ name, data });
    cursor += 46 + nameLength + extraLength + commentLength;
  }

  return entries;
}

function postExportAll(body) {
  let response;
  handlers['/api/local/exportAll'](
    { body },
    {
      json(payload) {
        response = payload;
      },
    }
  );
  return response;
}

function postExport(body) {
  let response;
  handlers['/api/local/export'](
    { body },
    {
      json(payload) {
        response = payload;
      },
    }
  );
  return response;
}

function removeTestFiles() {
  [
    path.join(pageDir, firstPageFile),
    path.join(pageDir, secondPageFile),
    path.join(uploadDir, uploadFile),
    ...createdExportFiles,
  ].forEach((filePath) => {
    if (fs.existsSync(filePath)) fs.unlinkSync(filePath);
  });
}

try {
  fs.mkdirSync(pageDir, { recursive: true });
  fs.mkdirSync(uploadDir, { recursive: true });
  fs.mkdirSync(exportDir, { recursive: true });
  removeTestFiles();

  fs.writeFileSync(path.join(uploadDir, uploadFile), 'batch image');
  fs.writeFileSync(
    path.join(pageDir, firstPageFile),
    JSON.stringify({ attrs: { image: `Images/uploads/${uploadFile}` } }),
    'utf8'
  );
  fs.writeFileSync(path.join(pageDir, secondPageFile), JSON.stringify({ attrs: { text: 'second' } }), 'utf8');

  const singleExportResult = postExport({ pageName: 'single page', pageTxt: firstPageFile });
  assert.strictEqual(singleExportResult.code, 100);
  const singleExportPath = path.join(publicDir, singleExportResult.data);
  createdExportFiles.push(singleExportPath);
  assert.deepStrictEqual(
    readZipEntries(fs.readFileSync(singleExportPath)).map((entry) => entry.name).sort(),
    [firstPageFile, 'img/', `img/uploads/${uploadFile}`]
  );

  const result = postExportAll({
    pages: [
      { pageName: 'overview.v2', pageTxt: firstPageFile, pageIndex: '026' },
      { pageName: 'overview', pageTxt: secondPageFile, pageIndex: 1 },
      { pageName: 'missing page', pageTxt: `${testPrefix}-missing.txt`, pageIndex: 2 },
    ],
  });

  assert.strictEqual(result.code, 100);
  assert.strictEqual(result.data.exportedCount, 2);
  assert.deepStrictEqual(result.data.skippedPages, [{
    pageName: 'missing page',
    pageTxt: `${testPrefix}-missing.txt`,
    pageIndex: 2,
  }]);
  assert.match(result.data.fileUrl, /^Images\/exports\/.+\.zip$/);

  const batchExportPath = path.join(publicDir, result.data.fileUrl);
  createdExportFiles.push(batchExportPath);
  const batchEntries = readZipEntries(fs.readFileSync(batchExportPath));
  const pageZipEntries = batchEntries.filter((entry) => entry.name.endsWith('.zip'));

  assert.deepStrictEqual(
    pageZipEntries.map((entry) => entry.name).sort(),
    ['overview.v2[026].zip', 'overview[1].zip']
  );
  pageZipEntries.forEach((entry) => {
    assert.strictEqual(entry.data.readUInt32LE(0), 0x04034b50);
  });

  const firstPageZip = pageZipEntries.find((entry) => entry.name === 'overview.v2[026].zip');
  assert.deepStrictEqual(
    readZipEntries(firstPageZip.data).map((entry) => entry.name).sort(),
    [firstPageFile, 'img/', `img/uploads/${uploadFile}`]
  );

  const allMissingResult = postExportAll({
    pages: [{ pageName: 'missing page', pageTxt: `${testPrefix}-missing.txt`, pageIndex: 3 }],
  });
  assert.strictEqual(allMissingResult.code, 400);
} finally {
  removeTestFiles();
}

console.log('Batch page export route test passed');
