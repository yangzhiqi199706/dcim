const fs = require('fs');
const zlib = require('zlib');

const PNG_SIGNATURE_HEX = '89504e470d0a1a0a';
const BYTES_PER_PIXEL = 4;

function paethPredictor(left, above, upperLeft) {
  const p = left + above - upperLeft;
  const pa = Math.abs(p - left);
  const pb = Math.abs(p - above);
  const pc = Math.abs(p - upperLeft);
  if (pa <= pb && pa <= pc) return left;
  if (pb <= pc) return above;
  return upperLeft;
}

function unfilterScanline(filter, raw, previous, out) {
  for (let i = 0; i < raw.length; i += 1) {
    const left = i >= BYTES_PER_PIXEL ? out[i - BYTES_PER_PIXEL] : 0;
    const above = previous ? previous[i] : 0;
    const upperLeft = previous && i >= BYTES_PER_PIXEL ? previous[i - BYTES_PER_PIXEL] : 0;
    let predictor = 0;
    if (filter === 1) predictor = left;
    else if (filter === 2) predictor = above;
    else if (filter === 3) predictor = Math.floor((left + above) / 2);
    else if (filter === 4) predictor = paethPredictor(left, above, upperLeft);
    else if (filter !== 0) throw new Error(`unsupported png filter: ${filter}`);
    out[i] = (raw[i] + predictor) & 0xff;
  }
}

function readPngRgba(filePath) {
  const buffer = fs.readFileSync(filePath);
  if (buffer.length < 24 || buffer.slice(0, 8).toString('hex') !== PNG_SIGNATURE_HEX) {
    throw new Error('unsupported image format: expected png');
  }

  let offset = 8;
  let width = 0;
  let height = 0;
  let colorType = 0;
  const idatParts = [];

  while (offset + 8 <= buffer.length) {
    const length = buffer.readUInt32BE(offset);
    const type = buffer.slice(offset + 4, offset + 8).toString('ascii');
    const dataStart = offset + 8;
    const dataEnd = dataStart + length;
    if (dataEnd + 4 > buffer.length) break;

    if (type === 'IHDR') {
      width = buffer.readUInt32BE(dataStart);
      height = buffer.readUInt32BE(dataStart + 4);
      const bitDepth = buffer[dataStart + 8];
      colorType = buffer[dataStart + 9];
      if (bitDepth !== 8 || colorType !== 6) {
        throw new Error('unsupported png format: expected 8-bit rgba');
      }
    } else if (type === 'IDAT') {
      idatParts.push(buffer.slice(dataStart, dataEnd));
    } else if (type === 'IEND') {
      break;
    }

    offset = dataEnd + 4;
  }

  const inflated = zlib.inflateSync(Buffer.concat(idatParts));
  const stride = width * 4;
  const pixels = Buffer.alloc(width * height * 4);
  let srcOffset = 0;
  let previous = null;
  for (let y = 0; y < height; y += 1) {
    const filter = inflated[srcOffset];
    srcOffset += 1;
    const row = Buffer.alloc(stride);
    unfilterScanline(filter, inflated.slice(srcOffset, srcOffset + stride), previous, row);
    row.copy(pixels, y * stride);
    previous = row;
    srcOffset += stride;
  }

  return { width, height, pixels };
}

function brightnessAt(image, x, y) {
  const offset = (y * image.width + x) * 4;
  const r = image.pixels[offset];
  const g = image.pixels[offset + 1];
  const b = image.pixels[offset + 2];
  const a = image.pixels[offset + 3];
  if (a < 32) return 0;
  return Math.max(r, g, b) - Math.min(r, g, b) + Math.max(r, g, b) * 0.35;
}

function findRuns(values, minLength) {
  const runs = [];
  let start = -1;
  for (let i = 0; i <= values.length; i += 1) {
    const active = i < values.length && values[i];
    if (active && start < 0) start = i;
    if ((!active || i === values.length) && start >= 0) {
      const end = i - 1;
      if (end - start + 1 >= minLength) runs.push({ start, end });
      start = -1;
    }
  }
  return runs;
}

function lineKey(line) {
  return [line.x1, line.y1, line.x2, line.y2].join(':');
}

function detectSimpleShapesFromPng(filePath, options = {}) {
  const image = readPngRgba(filePath);
  const minLineLength = options.minLineLength || 32;
  const colorThreshold = options.colorThreshold || 120;
  const lines = [];
  const seen = new Set();

  for (let y = 0; y < image.height; y += 1) {
    const values = [];
    for (let x = 0; x < image.width; x += 1) {
      values.push(brightnessAt(image, x, y) >= colorThreshold);
    }
    findRuns(values, minLineLength).forEach((run) => {
      const line = { x1: run.start, y1: y, x2: run.end, y2: y };
      const key = lineKey(line);
      if (!seen.has(key)) {
        seen.add(key);
        lines.push(line);
      }
    });
  }

  for (let x = 0; x < image.width; x += 1) {
    const values = [];
    for (let y = 0; y < image.height; y += 1) {
      values.push(brightnessAt(image, x, y) >= colorThreshold);
    }
    findRuns(values, minLineLength).forEach((run) => {
      const line = { x1: x, y1: run.start, x2: x, y2: run.end };
      const key = lineKey(line);
      if (!seen.has(key)) {
        seen.add(key);
        lines.push(line);
      }
    });
  }

  return { rects: [], lines };
}

module.exports = {
  detectSimpleShapesFromPng,
  readPngRgba,
};
