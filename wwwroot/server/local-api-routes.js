const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const PUBLIC_DIR = path.resolve(__dirname, '../public');
const IMAGES_DIR = path.join(PUBLIC_DIR, 'Images');
const SYSTEM_DIR = path.join(IMAGES_DIR, 'dcim');
const UPLOAD_DIR = path.join(IMAGES_DIR, 'uploads');
const TEMPLATE_DIR = path.join(IMAGES_DIR, 'pagetpl');
const PAGE_DIR = path.join(IMAGES_DIR, 'page');
const EXPORT_DIR = path.join(IMAGES_DIR, 'exports');

const IMAGE_EXTENSIONS = new Set(['.png', '.jpg', '.jpeg', '.gif', '.bmp', '.svg', '.webp']);
const MIME_EXTENSION_MAP = {
  'image/png': '.png',
  'image/jpeg': '.jpg',
  'image/jpg': '.jpg',
  'image/gif': '.gif',
  'image/bmp': '.bmp',
  'image/svg+xml': '.svg',
  'image/webp': '.webp',
  'text/plain': '.txt',
  'application/json': '.json',
};

function ok(res, data = [], msg = 'success') {
  res.json({ code: 100, msg, data });
}

function fail(res, msg = 'failed') {
  res.json({ code: 400, msg, data: [] });
}

function ensureDirectory(dirPath) {
  if (!fs.existsSync(dirPath)) {
    fs.mkdirSync(dirPath, { recursive: true });
  }
}

function sanitizeName(name, fallback = 'file') {
  const src = String(name || '').trim();
  const noPath = path.basename(src).replace(/[\\/:*?"<>|]/g, '_').replace(/\s+/g, ' ');
  const normalized = noPath.replace(/^\.+/, '').replace(/\.+$/, '').trim();
  return normalized || fallback;
}

function createTimestampName(prefix = 'file', extension = '.txt') {
  return `${sanitizeName(prefix, 'file')}_${Date.now()}${extension}`;
}

function toPosix(filePath) {
  return filePath.split(path.sep).join('/');
}

function toPublicImageUrl(absPath) {
  const relativePath = toPosix(path.relative(PUBLIC_DIR, absPath));
  if (relativePath.startsWith('Images/')) return relativePath;
  return `Images/${path.basename(absPath)}`;
}

function isNonEmptyFile(absPath) {
  try {
    return fs.statSync(absPath).size > 0;
  } catch (error) {
    return false;
  }
}

function listImageFiles(dirPath, recursive = false) {
  if (!fs.existsSync(dirPath)) return [];

  const entries = fs.readdirSync(dirPath, { withFileTypes: true });
  let files = [];

  entries.forEach((entry) => {
    const absPath = path.join(dirPath, entry.name);
    if (entry.isDirectory()) {
      if (recursive) {
        files = files.concat(listImageFiles(absPath, true));
      }
      return;
    }

    const ext = path.extname(entry.name).toLowerCase();
    if (IMAGE_EXTENSIONS.has(ext) && isNonEmptyFile(absPath)) {
      files.push(absPath);
    }
  });

  return files;
}

function listTemplateData() {
  if (!fs.existsSync(TEMPLATE_DIR)) return [];

  const files = fs
    .readdirSync(TEMPLATE_DIR, { withFileTypes: true })
    .filter((entry) => entry.isFile() && path.extname(entry.name).toLowerCase() === '.txt')
    .map((entry) => path.join(TEMPLATE_DIR, entry.name))
    .sort((a, b) => a.localeCompare(b));

  return files.map((filePath) => {
    const moduleName = path.basename(filePath, '.txt');
    const raw = fs.readFileSync(filePath, 'utf8');
    return {
      moduleName,
      iconBase64: 'Images/icon/tpl.png',
      // Keep template payload as raw string to match existing frontend drag logic.
      moduleJson: raw,
    };
  });
}

function resolveExistingFile(baseDir, rawName) {
  const safeName = path.basename(String(rawName || '').split('?')[0]);
  if (!safeName) return null;

  const directPath = path.join(baseDir, safeName);
  if (fs.existsSync(directPath) && fs.statSync(directPath).isFile()) {
    return directPath;
  }

  const txtPath = path.join(baseDir, `${safeName}.txt`);
  if (fs.existsSync(txtPath) && fs.statSync(txtPath).isFile()) {
    return txtPath;
  }

  // Backward compatibility:
  // older imports may have been saved as "<stem>_<timestamp>.txt" while DB still keeps "<stem>".
  const safeStem = path.parse(safeName).name;
  if (!safeStem) return null;

  const fallbackCandidates = fs
    .readdirSync(baseDir, { withFileTypes: true })
    .filter((entry) => entry.isFile() && path.extname(entry.name).toLowerCase() === '.txt')
    .map((entry) => path.join(baseDir, entry.name))
    .filter((filePath) => {
      const fileStem = path.parse(path.basename(filePath)).name;
      return (
        fileStem === safeStem
        || fileStem.startsWith(`${safeStem}_`)
        || fileStem.startsWith(`${safeStem}-`)
      );
    })
    .sort((a, b) => fs.statSync(b).mtimeMs - fs.statSync(a).mtimeMs);

  if (fallbackCandidates.length > 0) {
    return fallbackCandidates[0];
  }

  return null;
}

function deleteFileIfExists(filePath) {
  if (!filePath) return false;
  if (!fs.existsSync(filePath)) return false;
  if (!fs.statSync(filePath).isFile()) return false;
  fs.unlinkSync(filePath);
  return true;
}

function decodeBase64Payload(fileData) {
  const input = String(fileData || '');
  const dataUrlMatch = input.match(/^data:([^;]+);base64,([\s\S]+)$/);
  if (dataUrlMatch) {
    return {
      mime: dataUrlMatch[1].toLowerCase(),
      buffer: Buffer.from(dataUrlMatch[2], 'base64'),
    };
  }

  return {
    mime: '',
    buffer: Buffer.from(input, 'base64'),
  };
}

function extensionFromNameOrMime(fileName, mime = '') {
  const parsed = path.parse(path.basename(String(fileName || '')));
  if (parsed.ext) {
    return parsed.ext.toLowerCase();
  }
  return MIME_EXTENSION_MAP[mime] || '';
}

function ensureTxtFileName(rawName, fallbackPrefix = 'page') {
  const parsed = path.parse(path.basename(String(rawName || '')));
  const stem = sanitizeName(parsed.name || rawName, fallbackPrefix);
  return `${stem}.txt`;
}

function normalizeImageRef(rawValue) {
  if (typeof rawValue !== 'string') return null;

  let value = rawValue.trim();
  if (!value || /^data:/i.test(value)) return null;

  try {
    value = decodeURIComponent(value);
  } catch (error) {
    // Ignore malformed URI sequences.
  }

  value = value.replace(/\\/g, '/');
  const lowerValue = value.toLowerCase();
  const imagesIdx = lowerValue.indexOf('/images/');
  const fallbackIdx = lowerValue.indexOf('images/');
  const startIdx = imagesIdx >= 0 ? imagesIdx + 1 : fallbackIdx;
  if (startIdx < 0) return null;

  const rawPath = value.slice(startIdx).replace(/^\/+/, '');
  const cleanPath = rawPath.split('?')[0].split('#')[0];
  const normalized = path.posix.normalize(cleanPath);
  if (!normalized || normalized.includes('..')) return null;
  if (!normalized.toLowerCase().startsWith('images/')) return null;

  const ext = path.extname(normalized).toLowerCase();
  if (!IMAGE_EXTENSIONS.has(ext)) return null;
  return normalized;
}

function parseNestedJson(rawText) {
  let current = rawText;
  for (let i = 0; i < 3; i += 1) {
    if (typeof current !== 'string') return current;
    const trimmed = current.trim();
    if (!trimmed) return current;
    if (!trimmed.startsWith('{') && !trimmed.startsWith('[') && !trimmed.startsWith('"')) {
      return current;
    }
    try {
      current = JSON.parse(trimmed);
    } catch (error) {
      return current;
    }
  }
  return current;
}

function walkStringValues(value, visitor) {
  if (typeof value === 'string') {
    visitor(value);
    return;
  }
  if (Array.isArray(value)) {
    value.forEach((item) => walkStringValues(item, visitor));
    return;
  }
  if (value && typeof value === 'object') {
    Object.keys(value).forEach((key) => walkStringValues(value[key], visitor));
  }
}

function extractImageRefsFromPageText(rawText) {
  const text = String(rawText || '');
  const refs = new Set();
  const imagePathPattern = /(?:https?:\/\/[^\s"'`]*?)?\/?Images\/[^\s"'`]+?\.(?:png|jpe?g|gif|bmp|svg|webp)(?:\?[^"'`\s]*)?(?:#[^"'`\s]*)?/gi;

  let match = imagePathPattern.exec(text);
  while (match) {
    const normalized = normalizeImageRef(match[0]);
    if (normalized) refs.add(normalized);
    match = imagePathPattern.exec(text);
  }

  const parsed = parseNestedJson(text);
  walkStringValues(parsed, (strValue) => {
    const normalized = normalizeImageRef(strValue);
    if (normalized) refs.add(normalized);
  });

  return Array.from(refs).sort((a, b) => a.localeCompare(b));
}

function isPathWithin(baseDir, targetPath) {
  const normalizedBase = path.resolve(baseDir);
  const normalizedTarget = path.resolve(targetPath);
  if (normalizedBase === normalizedTarget) return true;
  return normalizedTarget.toLowerCase().startsWith(`${normalizedBase}${path.sep}`.toLowerCase());
}

function resolveImageAbsolutePath(imageRef) {
  const normalizedRef = normalizeImageRef(imageRef);
  if (!normalizedRef) return null;
  const relativeToImages = normalizedRef.replace(/^images\//i, '');
  const absPath = path.resolve(IMAGES_DIR, ...relativeToImages.split('/'));
  if (!isPathWithin(IMAGES_DIR, absPath)) return null;
  if (!fs.existsSync(absPath) || !fs.statSync(absPath).isFile()) return null;
  return absPath;
}

function isUserUploadImageRef(imageRef) {
  const normalizedRef = normalizeImageRef(imageRef);
  if (!normalizedRef) return false;
  return /^images\/uploads\//i.test(normalizedRef);
}

const CRC32_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let i = 0; i < 256; i += 1) {
    let crc = i;
    for (let j = 0; j < 8; j += 1) {
      crc = (crc & 1) ? (0xedb88320 ^ (crc >>> 1)) : (crc >>> 1);
    }
    table[i] = crc >>> 0;
  }
  return table;
})();

function crc32(buffer) {
  let crc = 0xffffffff;
  for (let i = 0; i < buffer.length; i += 1) {
    crc = CRC32_TABLE[(crc ^ buffer[i]) & 0xff] ^ (crc >>> 8);
  }
  return (crc ^ 0xffffffff) >>> 0;
}

function toDosDateTime(date = new Date()) {
  const year = Math.min(Math.max(date.getFullYear(), 1980), 2107);
  const month = date.getMonth() + 1;
  const day = date.getDate();
  const hours = date.getHours();
  const minutes = date.getMinutes();
  const seconds = Math.floor(date.getSeconds() / 2);

  const dosTime = (hours << 11) | (minutes << 5) | seconds;
  const dosDate = ((year - 1980) << 9) | (month << 5) | day;
  return { dosTime, dosDate };
}

function createZipBuffer(entries) {
  const localParts = [];
  const centralParts = [];
  let offset = 0;
  const utf8Flag = 0x0800;

  entries.forEach((entry) => {
    const name = String(entry.name || '').replace(/^\/+/, '').replace(/\\/g, '/');
    if (!name) return;

    const isDirectory = name.endsWith('/');
    const nameBuffer = Buffer.from(name, 'utf8');
    const dataBuffer = isDirectory ? Buffer.alloc(0) : Buffer.from(entry.data || Buffer.alloc(0));
    const compressedBuffer = isDirectory ? Buffer.alloc(0) : zlib.deflateRawSync(dataBuffer);
    const compressionMethod = isDirectory ? 0 : 8;
    const checksum = isDirectory ? 0 : crc32(dataBuffer);
    const { dosTime, dosDate } = toDosDateTime(entry.mtime || new Date());

    const localHeader = Buffer.alloc(30);
    localHeader.writeUInt32LE(0x04034b50, 0);
    localHeader.writeUInt16LE(20, 4);
    localHeader.writeUInt16LE(utf8Flag, 6);
    localHeader.writeUInt16LE(compressionMethod, 8);
    localHeader.writeUInt16LE(dosTime, 10);
    localHeader.writeUInt16LE(dosDate, 12);
    localHeader.writeUInt32LE(checksum >>> 0, 14);
    localHeader.writeUInt32LE(compressedBuffer.length >>> 0, 18);
    localHeader.writeUInt32LE(dataBuffer.length >>> 0, 22);
    localHeader.writeUInt16LE(nameBuffer.length, 26);
    localHeader.writeUInt16LE(0, 28);

    localParts.push(localHeader, nameBuffer, compressedBuffer);

    const centralHeader = Buffer.alloc(46);
    centralHeader.writeUInt32LE(0x02014b50, 0);
    centralHeader.writeUInt16LE(20, 4);
    centralHeader.writeUInt16LE(20, 6);
    centralHeader.writeUInt16LE(utf8Flag, 8);
    centralHeader.writeUInt16LE(compressionMethod, 10);
    centralHeader.writeUInt16LE(dosTime, 12);
    centralHeader.writeUInt16LE(dosDate, 14);
    centralHeader.writeUInt32LE(checksum >>> 0, 16);
    centralHeader.writeUInt32LE(compressedBuffer.length >>> 0, 20);
    centralHeader.writeUInt32LE(dataBuffer.length >>> 0, 24);
    centralHeader.writeUInt16LE(nameBuffer.length, 28);
    centralHeader.writeUInt16LE(0, 30);
    centralHeader.writeUInt16LE(0, 32);
    centralHeader.writeUInt16LE(0, 34);
    centralHeader.writeUInt16LE(0, 36);
    centralHeader.writeUInt32LE(isDirectory ? 0x10 : 0, 38);
    centralHeader.writeUInt32LE(offset >>> 0, 42);

    centralParts.push(centralHeader, nameBuffer);
    offset += localHeader.length + nameBuffer.length + compressedBuffer.length;
  });

  const centralDir = Buffer.concat(centralParts);
  const localData = Buffer.concat(localParts);
  const endRecord = Buffer.alloc(22);
  const entryCount = Math.floor(centralParts.length / 2);

  endRecord.writeUInt32LE(0x06054b50, 0);
  endRecord.writeUInt16LE(0, 4);
  endRecord.writeUInt16LE(0, 6);
  endRecord.writeUInt16LE(entryCount, 8);
  endRecord.writeUInt16LE(entryCount, 10);
  endRecord.writeUInt32LE(centralDir.length >>> 0, 12);
  endRecord.writeUInt32LE(localData.length >>> 0, 16);
  endRecord.writeUInt16LE(0, 20);

  return Buffer.concat([localData, centralDir, endRecord]);
}

function isZipBuffer(buffer) {
  if (!buffer || buffer.length < 4) return false;
  const signature = buffer.readUInt32LE(0);
  return signature === 0x04034b50 || signature === 0x06054b50;
}

function normalizeZipEntryName(rawName) {
  const source = String(rawName || '').replace(/\\/g, '/');
  const normalized = path.posix.normalize(source).replace(/^\/+/, '');
  if (!normalized || normalized === '.' || normalized.startsWith('..') || normalized.includes('/../')) {
    return '';
  }
  return normalized;
}

function readUInt64LEAsNumber(buffer, offset) {
  if (offset + 8 > buffer.length) {
    throw new Error('invalid zip64 field length');
  }
  const low = buffer.readUInt32LE(offset);
  const high = buffer.readUInt32LE(offset + 4);
  const value = (high * 0x100000000) + low;
  if (!Number.isSafeInteger(value)) {
    throw new Error('zip64 field exceeds safe integer range');
  }
  return value;
}

function parseZip64Extra(extraFieldBuffer, needs = {}) {
  const result = {};
  let cursor = 0;

  while (cursor + 4 <= extraFieldBuffer.length) {
    const headerId = extraFieldBuffer.readUInt16LE(cursor);
    const dataSize = extraFieldBuffer.readUInt16LE(cursor + 2);
    const dataStart = cursor + 4;
    const dataEnd = dataStart + dataSize;
    if (dataEnd > extraFieldBuffer.length) break;

    if (headerId === 0x0001) {
      let valueCursor = dataStart;
      if (needs.uncompressedSize && valueCursor + 8 <= dataEnd) {
        result.uncompressedSize = readUInt64LEAsNumber(extraFieldBuffer, valueCursor);
        valueCursor += 8;
      }
      if (needs.compressedSize && valueCursor + 8 <= dataEnd) {
        result.compressedSize = readUInt64LEAsNumber(extraFieldBuffer, valueCursor);
        valueCursor += 8;
      }
      if (needs.localHeaderOffset && valueCursor + 8 <= dataEnd) {
        result.localHeaderOffset = readUInt64LEAsNumber(extraFieldBuffer, valueCursor);
      }
      break;
    }

    cursor = dataEnd;
  }

  return result;
}

function findEndOfCentralDirectoryOffset(zipBuffer) {
  if (!zipBuffer || zipBuffer.length < 22) return -1;
  const minOffset = Math.max(0, zipBuffer.length - 22 - 0xffff);
  for (let offset = zipBuffer.length - 22; offset >= minOffset; offset -= 1) {
    if (zipBuffer.readUInt32LE(offset) === 0x06054b50) {
      return offset;
    }
  }
  return -1;
}

function parseZipEntriesFromBuffer(zipBuffer) {
  if (!Buffer.isBuffer(zipBuffer) || zipBuffer.length < 22) {
    throw new Error('invalid zip content');
  }

  const entries = [];
  const eocdOffset = findEndOfCentralDirectoryOffset(zipBuffer);
  if (eocdOffset < 0) {
    throw new Error('invalid zip central directory');
  }

  const centralDirSize = zipBuffer.readUInt32LE(eocdOffset + 12);
  const centralDirOffset = zipBuffer.readUInt32LE(eocdOffset + 16);
  const commentLength = zipBuffer.readUInt16LE(eocdOffset + 20);
  const eocdEnd = eocdOffset + 22 + commentLength;
  if (eocdEnd > zipBuffer.length) {
    throw new Error('invalid zip end record');
  }

  const centralDirEnd = centralDirOffset + centralDirSize;
  if (centralDirOffset < 0 || centralDirEnd > zipBuffer.length || centralDirEnd < centralDirOffset) {
    throw new Error('invalid zip central directory range');
  }

  let offset = centralDirOffset;
  while (offset + 46 <= centralDirEnd) {
    const signature = zipBuffer.readUInt32LE(offset);
    if (signature !== 0x02014b50) {
      throw new Error('invalid zip central file header');
    }

    const flags = zipBuffer.readUInt16LE(offset + 8);
    const compressionMethod = zipBuffer.readUInt16LE(offset + 10);
    let compressedSize = zipBuffer.readUInt32LE(offset + 20);
    let uncompressedSize = zipBuffer.readUInt32LE(offset + 24);
    const fileNameLength = zipBuffer.readUInt16LE(offset + 28);
    const extraFieldLength = zipBuffer.readUInt16LE(offset + 30);
    const fileCommentLength = zipBuffer.readUInt16LE(offset + 32);
    let localHeaderOffset = zipBuffer.readUInt32LE(offset + 42);

    const fileNameStart = offset + 46;
    const fileNameEnd = fileNameStart + fileNameLength;
    const extraFieldStart = fileNameEnd;
    const extraFieldEnd = extraFieldStart + extraFieldLength;
    const centralEntryEnd = extraFieldEnd + fileCommentLength;
    if (centralEntryEnd > centralDirEnd || centralEntryEnd > zipBuffer.length) {
      throw new Error('invalid zip central entry range');
    }

    const extraFieldBuffer = zipBuffer.slice(extraFieldStart, extraFieldEnd);
    const zip64Values = parseZip64Extra(extraFieldBuffer, {
      uncompressedSize: uncompressedSize === 0xffffffff,
      compressedSize: compressedSize === 0xffffffff,
      localHeaderOffset: localHeaderOffset === 0xffffffff,
    });
    if (uncompressedSize === 0xffffffff) {
      if (typeof zip64Values.uncompressedSize !== 'number') {
        throw new Error('zip64 uncompressed size is missing');
      }
      uncompressedSize = zip64Values.uncompressedSize;
    }
    if (compressedSize === 0xffffffff) {
      if (typeof zip64Values.compressedSize !== 'number') {
        throw new Error('zip64 compressed size is missing');
      }
      compressedSize = zip64Values.compressedSize;
    }
    if (localHeaderOffset === 0xffffffff) {
      if (typeof zip64Values.localHeaderOffset !== 'number') {
        throw new Error('zip64 local header offset is missing');
      }
      localHeaderOffset = zip64Values.localHeaderOffset;
    }

    const fileNameBuffer = zipBuffer.slice(fileNameStart, fileNameEnd);
    const useUtf8 = (flags & 0x0800) !== 0;
    const decodedName = useUtf8 ? fileNameBuffer.toString('utf8') : fileNameBuffer.toString('latin1');
    const entryName = normalizeZipEntryName(decodedName);
    const isDirectory = entryName.endsWith('/');

    if (entryName) {
      if (!isDirectory && (flags & 0x0001) !== 0) {
        throw new Error('encrypted zip entry is not supported');
      }

      if (localHeaderOffset + 30 > zipBuffer.length) {
        throw new Error('invalid zip local header');
      }
      const localHeaderSignature = zipBuffer.readUInt32LE(localHeaderOffset);
      if (localHeaderSignature !== 0x04034b50) {
        throw new Error('invalid zip local header');
      }
      const localFileNameLength = zipBuffer.readUInt16LE(localHeaderOffset + 26);
      const localExtraFieldLength = zipBuffer.readUInt16LE(localHeaderOffset + 28);
      const dataStart = localHeaderOffset + 30 + localFileNameLength + localExtraFieldLength;
      const dataEnd = dataStart + compressedSize;
      if (dataEnd > zipBuffer.length || dataEnd < dataStart) {
        throw new Error('invalid zip data range');
      }

      const compressedData = zipBuffer.slice(dataStart, dataEnd);
      let dataBuffer = Buffer.alloc(0);
      if (!isDirectory) {
        if (compressionMethod === 0) {
          dataBuffer = Buffer.from(compressedData);
        } else if (compressionMethod === 8) {
          dataBuffer = zlib.inflateRawSync(compressedData);
          if (uncompressedSize >= 0 && dataBuffer.length !== uncompressedSize) {
            throw new Error('invalid zip uncompressed size');
          }
        } else {
          throw new Error(`unsupported zip compression method: ${compressionMethod}`);
        }
      }

      entries.push({ name: entryName, data: dataBuffer, isDirectory });
    }

    offset = centralEntryEnd;
  }

  if (offset !== centralDirEnd) {
    throw new Error('invalid zip central directory tail');
  }

  return entries;
}

function ensureImportTxtName(rawName, fallback = 'import_page.txt') {
  const parsed = path.parse(path.basename(String(rawName || '')));
  const stem = sanitizeName(parsed.name || fallback, 'import_page');
  return `${stem}.txt`;
}

function writeImportedImagesToUploads(zipEntries) {
  let savedCount = 0;
  zipEntries.forEach((entry) => {
    if (!entry || entry.isDirectory) return;
    if (!/^img\//i.test(entry.name)) return;

    const rawRelative = entry.name.replace(/^img\//i, '');
    let relativePath = normalizeZipEntryName(rawRelative);
    if (!relativePath) return;
    if (/^uploads\//i.test(relativePath)) {
      relativePath = relativePath.replace(/^uploads\//i, '');
    }
    if (!relativePath) return;

    const ext = path.extname(relativePath).toLowerCase();
    if (!IMAGE_EXTENSIONS.has(ext)) return;

    const destinationPath = path.resolve(UPLOAD_DIR, ...relativePath.split('/'));
    if (!isPathWithin(UPLOAD_DIR, destinationPath)) return;

    ensureDirectory(path.dirname(destinationPath));
    fs.writeFileSync(destinationPath, entry.data);
    savedCount += 1;
  });

  return savedCount;
}

function createImgDataHandler() {
  return (req, res) => {
    const payload = req.body || {};
    const action = String(payload.action || '').trim();

    try {
      ensureDirectory(UPLOAD_DIR);
      ensureDirectory(PAGE_DIR);
      ensureDirectory(TEMPLATE_DIR);
      ensureDirectory(EXPORT_DIR);

      if (action === 'system') {
        const data = listImageFiles(SYSTEM_DIR, true)
          .sort((a, b) => a.localeCompare(b))
          .map((filePath) => ({ imgUrl: toPublicImageUrl(filePath) }));
        return ok(res, data);
      }

      if (action === 'upload') {
        const data = listImageFiles(UPLOAD_DIR, true)
          .sort((a, b) => a.localeCompare(b))
          .map((filePath) => ({ imgUrl: toPublicImageUrl(filePath) }));
        return ok(res, data);
      }

      if (action === 'tpl') {
        return ok(res, listTemplateData());
      }

      if (action === 'page') {
        const filePath = resolveExistingFile(PAGE_DIR, payload.name);
        if (!filePath) {
          return fail(res, 'page file not found');
        }
        const moduleJson = fs.readFileSync(filePath, 'utf8');
        return ok(res, [{ moduleJson }], 'read success');
      }

      if (action === 'del') {
        const fileName = path.basename(String(payload.img || '').split('?')[0]);
        const filePath = resolveExistingFile(UPLOAD_DIR, fileName);
        if (!deleteFileIfExists(filePath)) {
          return fail(res, 'image not found');
        }
        return ok(res, [], 'deleted');
      }

      if (action === 'deltpl') {
        const filePath = resolveExistingFile(TEMPLATE_DIR, payload.name);
        if (!deleteFileIfExists(filePath)) {
          return fail(res, 'template not found');
        }
        return ok(res, [], 'deleted');
      }

      if (action === 'delpage') {
        const filePath = resolveExistingFile(PAGE_DIR, payload.name);
        if (!deleteFileIfExists(filePath)) {
          return fail(res, 'page file not found');
        }
        return ok(res, [], 'deleted');
      }

      return fail(res, `unsupported action: ${action || 'empty'}`);
    } catch (error) {
      return fail(res, `imgData local api error: ${error.message}`);
    }
  };
}

function createSaveTplHandler() {
  return (req, res) => {
    try {
      ensureDirectory(TEMPLATE_DIR);
      const payload = req.body || {};
      const fileName = ensureTxtFileName(payload.name, 'template');
      const filePath = path.join(TEMPLATE_DIR, fileName);
      fs.writeFileSync(filePath, String(payload.tplcon || ''), 'utf8');
      return ok(res, toPublicImageUrl(filePath), 'saved');
    } catch (error) {
      return fail(res, `saveTpl local api error: ${error.message}`);
    }
  };
}

function createSavePageHandler() {
  return (req, res) => {
    try {
      ensureDirectory(PAGE_DIR);
      const payload = req.body || {};
      const fileName = ensureTxtFileName(payload.name, 'page');
      const filePath = path.join(PAGE_DIR, fileName);
      fs.writeFileSync(filePath, String(payload.pagecon || ''), 'utf8');
      return ok(res, toPublicImageUrl(filePath), 'saved');
    } catch (error) {
      return fail(res, `savePage local api error: ${error.message}`);
    }
  };
}

function createExportHandler() {
  return (req, res) => {
    try {
      ensureDirectory(PAGE_DIR);
      ensureDirectory(EXPORT_DIR);

      const payload = req.body || {};
      const sourceFile = resolveExistingFile(PAGE_DIR, payload.pageTxt);
      if (!sourceFile) {
        return fail(res, 'page file not found');
      }

      const parsedSource = path.parse(sourceFile);
      const exportPrefix = sanitizeName(payload.pageName || parsedSource.name, 'page');
      const sourceTxtEntryName = sanitizeName(parsedSource.base || `${parsedSource.name}.txt`, 'page.txt');
      const pageText = fs.readFileSync(sourceFile, 'utf8');
      const imageRefs = extractImageRefsFromPageText(pageText);

      const imageEntries = [];
      const imageZipNames = new Set();
      imageRefs.forEach((imageRef) => {
        if (!isUserUploadImageRef(imageRef)) return;
        const normalizedRef = normalizeImageRef(imageRef);
        const absImagePath = resolveImageAbsolutePath(normalizedRef);
        if (!absImagePath) return;

        const relativeToImages = normalizedRef.replace(/^images\//i, '');
        const zipImagePath = `img/${relativeToImages}`;
        if (imageZipNames.has(zipImagePath)) return;
        imageZipNames.add(zipImagePath);

        imageEntries.push({
          name: zipImagePath,
          data: fs.readFileSync(absImagePath),
          mtime: fs.statSync(absImagePath).mtime,
        });
      });

      const zipEntries = [
        { name: sourceTxtEntryName, data: Buffer.from(pageText, 'utf8') },
        { name: 'img/' },
        ...imageEntries,
      ];

      const zipBuffer = createZipBuffer(zipEntries);
      const targetFileName = createTimestampName(exportPrefix, '.zip');
      const targetFilePath = path.join(EXPORT_DIR, targetFileName);
      fs.writeFileSync(targetFilePath, zipBuffer);
      return ok(res, toPublicImageUrl(targetFilePath), 'exported');
    } catch (error) {
      return fail(res, `export local api error: ${error.message}`);
    }
  };
}

function createUploadHandler() {
  return (req, res) => {
    try {
      ensureDirectory(UPLOAD_DIR);
      const payload = req.body || {};
      const decoded = decodeBase64Payload(payload.fileData);
      if (!decoded.buffer || decoded.buffer.length === 0) {
        return fail(res, 'empty upload content');
      }

      const ext = extensionFromNameOrMime(payload.fileName, decoded.mime) || '.png';
      const baseName = sanitizeName(path.parse(String(payload.fileName || '')).name, 'upload');
      const targetFileName = createTimestampName(baseName, ext);
      const targetFilePath = path.join(UPLOAD_DIR, targetFileName);

      fs.writeFileSync(targetFilePath, decoded.buffer);
      return ok(res, { imgUrl: toPublicImageUrl(targetFilePath) }, 'uploaded');
    } catch (error) {
      return fail(res, `upload local api error: ${error.message}`);
    }
  };
}

function createExportImportHandler() {
  return (req, res) => {
    try {
      ensureDirectory(PAGE_DIR);
      ensureDirectory(UPLOAD_DIR);
      const payload = req.body || {};
      const decoded = decodeBase64Payload(payload.fileData);
      if (!decoded.buffer || decoded.buffer.length === 0) {
        return fail(res, 'empty import content');
      }

      const sourceName = String(payload.fileName || '');
      let targetFileName = '';
      let pageText = '';
      let importedImageCount = 0;
      let zipEntriesForImport = null;

      const fileExtension = path.extname(path.basename(sourceName)).toLowerCase();
      if (decoded.mime === 'application/zip' || fileExtension === '.zip' || isZipBuffer(decoded.buffer)) {
        const zipEntries = parseZipEntriesFromBuffer(decoded.buffer);
        const txtEntry = zipEntries.find((entry) => !entry.isDirectory && path.extname(entry.name).toLowerCase() === '.txt');
        if (!txtEntry) {
          return fail(res, 'zip missing txt file');
        }

        targetFileName = ensureImportTxtName(path.basename(txtEntry.name), 'import_page.txt');
        pageText = txtEntry.data.toString('utf8');
        zipEntriesForImport = zipEntries;
      } else {
        targetFileName = ensureImportTxtName(path.basename(sourceName), 'import_page.txt');
        pageText = decoded.buffer.toString('utf8');
      }

      const targetFilePath = path.join(PAGE_DIR, targetFileName);
      if (fs.existsSync(targetFilePath) && fs.statSync(targetFilePath).isFile()) {
        return ok(
          res,
          {
            pageTxt: toPublicImageUrl(targetFilePath),
            importedImages: 0,
            duplicateByTxt: true,
            skippedCreatePage: true,
          },
          'import skipped: page txt already exists'
        );
      }

      fs.writeFileSync(targetFilePath, pageText, 'utf8');
      if (zipEntriesForImport) {
        importedImageCount = writeImportedImagesToUploads(zipEntriesForImport);
      }
      return ok(
        res,
        {
          pageTxt: toPublicImageUrl(targetFilePath),
          importedImages: importedImageCount,
          duplicateByTxt: false,
          skippedCreatePage: false,
        },
        'imported'
      );
    } catch (error) {
      return fail(res, `exportImport local api error: ${error.message}`);
    }
  };
}

function routePath(basePath, endpoint) {
  const normalizedBasePath = basePath.endsWith('/') ? basePath.slice(0, -1) : basePath;
  return `${normalizedBasePath}/${endpoint}`;
}

function attachLocalApiRoutes(app, basePath = '/api/local') {
  app.post(routePath(basePath, 'imgData'), createImgDataHandler());
  app.post(routePath(basePath, 'saveTpl'), createSaveTplHandler());
  app.post(routePath(basePath, 'savePage'), createSavePageHandler());
  app.post(routePath(basePath, 'export'), createExportHandler());
  app.post(routePath(basePath, 'upload'), createUploadHandler());
  app.post(routePath(basePath, 'exportImport'), createExportImportHandler());
}

module.exports = {
  attachLocalApiRoutes,
};
