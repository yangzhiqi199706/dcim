const assert = require('assert');
const fs = require('fs');
const path = require('path');

const { attachLocalApiRoutes } = require('./local-api-routes');

const uploadDir = path.resolve(__dirname, '../public/Images/uploads');
const fileName = 'same-name-overwrite-test.png';
const filePath = path.join(uploadDir, fileName);
const timestampFilePattern = /^same-name-overwrite-test_\d+\.png$/;
const handlers = {};

attachLocalApiRoutes({
  post(route, handler) {
    handlers[route] = handler;
  },
});

function upload(content) {
  let response;
  handlers['/api/local/upload'](
    {
      body: {
        fileName,
        fileData: `data:image/png;base64,${Buffer.from(content).toString('base64')}`,
      },
    },
    {
      json(payload) {
        response = payload;
      },
    }
  );
  return response;
}

function removeTestFiles() {
  if (!fs.existsSync(uploadDir)) return;
  fs.readdirSync(uploadDir).forEach((name) => {
    if (name === fileName || timestampFilePattern.test(name)) {
      fs.unlinkSync(path.join(uploadDir, name));
    }
  });
}

try {
  removeTestFiles();

  assert.strictEqual(upload('first image').code, 100);
  const secondResult = upload('second image');

  assert.strictEqual(secondResult.code, 100);
  assert.strictEqual(secondResult.data.imgUrl, `Images/uploads/${fileName}`);
  assert.strictEqual(fs.readFileSync(filePath, 'utf8'), 'second image');
  assert.deepStrictEqual(
    fs.readdirSync(uploadDir).filter((name) => name === fileName || timestampFilePattern.test(name)),
    [fileName]
  );
} finally {
  removeTestFiles();
}

console.log('Same-name upload overwrite test passed');
