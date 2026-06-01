const fs = require('fs');
const path = require('path');

const PNG_SIGNATURE_HEX = '89504e470d0a1a0a';

function readPngSize(filePath) {
  const buffer = fs.readFileSync(filePath);
  if (buffer.length < 24 || buffer.slice(0, 8).toString('hex') !== PNG_SIGNATURE_HEX) {
    throw new Error('unsupported image format: expected png');
  }
  return {
    width: buffer.readUInt32BE(16),
    height: buffer.readUInt32BE(20),
  };
}

function getImageInfo(filePath) {
  const absPath = path.resolve(filePath);
  const ext = path.extname(absPath).toLowerCase();
  if (ext !== '.png') {
    throw new Error('unsupported image format: only png is supported in v1');
  }
  const size = readPngSize(absPath);
  return {
    path: absPath,
    ext,
    mime: 'image/png',
    width: size.width,
    height: size.height,
  };
}

module.exports = {
  readPngSize,
  getImageInfo,
};
