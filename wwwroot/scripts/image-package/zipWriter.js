const zlib = require('zlib');

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
  return {
    dosTime: (hours << 11) | (minutes << 5) | seconds,
    dosDate: ((year - 1980) << 9) | (month << 5) | day,
  };
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

module.exports = {
  createZipBuffer,
  crc32,
};
