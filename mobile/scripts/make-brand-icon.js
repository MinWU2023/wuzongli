/**
 * Generate light-blue tech icons for 「好线索」 without external deps.
 */
const fs = require('fs');
const zlib = require('zlib');
const path = require('path');

const dir = path.join(__dirname, '..', 'assets');
fs.mkdirSync(dir, { recursive: true });

function crc32(buf) {
  let c = ~0;
  for (let i = 0; i < buf.length; i++) {
    c ^= buf[i];
    for (let k = 0; k < 8; k++) c = (c >>> 1) ^ (0xedb88320 & -(c & 1));
  }
  return ~c >>> 0;
}

function chunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
}

function pngFromRgb(w, h, paint) {
  const rows = [];
  for (let y = 0; y < h; y++) {
    const row = Buffer.alloc(1 + w * 3);
    row[0] = 0;
    for (let x = 0; x < w; x++) {
      const [r, g, b] = paint(x, y, w, h);
      const i = 1 + x * 3;
      row[i] = r;
      row[i + 1] = g;
      row[i + 2] = b;
    }
    rows.push(row);
  }
  const raw = Buffer.concat(rows);
  const sig = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8;
  ihdr[9] = 2;
  return Buffer.concat([
    sig,
    chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(raw, { level: 9 })),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

function lerp(a, b, t) {
  return Math.round(a + (b - a) * t);
}

function mix(c1, c2, t) {
  return [lerp(c1[0], c2[0], t), lerp(c1[1], c2[1], t), lerp(c1[2], c2[2], t)];
}

function dist(x, y, cx, cy) {
  const dx = x - cx;
  const dy = y - cy;
  return Math.sqrt(dx * dx + dy * dy);
}

function inRoundedRect(x, y, x0, y0, x1, y1, r) {
  const cx = Math.min(Math.max(x, x0 + r), x1 - r);
  const cy = Math.min(Math.max(y, y0 + r), y1 - r);
  return dist(x, y, cx, cy) <= r;
}

function paintIcon(x, y, w, h) {
  const nx = x / (w - 1);
  const ny = y / (h - 1);
  // Soft light-blue tech gradient
  let c = mix([232, 244, 255], [110, 188, 255], nx * 0.55 + ny * 0.45);

  const cx = w * 0.5;
  const cy = h * 0.5;
  const R = w * 0.34;

  // Outer ring
  const d = dist(x, y, cx, cy);
  if (Math.abs(d - R) < w * 0.028) {
    c = mix(c, [20, 110, 210], 0.85);
  }
  if (Math.abs(d - R * 0.78) < w * 0.012) {
    c = mix(c, [70, 170, 255], 0.7);
  }

  // Circuit ticks on ring
  for (let i = 0; i < 8; i++) {
    const a = (i / 8) * Math.PI * 2 - Math.PI / 2;
    const tx = cx + Math.cos(a) * R;
    const ty = cy + Math.sin(a) * R;
    if (dist(x, y, tx, ty) < w * 0.035) {
      c = [30, 120, 220];
    }
  }

  // Phone body (rounded rect, tilted slightly by offset)
  const px0 = w * 0.38;
  const py0 = h * 0.30;
  const px1 = w * 0.62;
  const py1 = h * 0.70;
  if (inRoundedRect(x, y, px0, py0, px1, py1, w * 0.06)) {
    c = [255, 255, 255];
    // inner screen
    if (inRoundedRect(x, y, px0 + w * 0.035, py0 + h * 0.05, px1 - w * 0.035, py1 - h * 0.08, w * 0.035)) {
      c = mix([180, 220, 255], [70, 160, 245], (y - py0) / (py1 - py0));
    }
    // speaker bar
    if (y > py0 + h * 0.03 && y < py0 + h * 0.045 && x > cx - w * 0.05 && x < cx + w * 0.05) {
      c = [140, 190, 240];
    }
  }

  // Lead spark / node at top-right of phone
  const sx = w * 0.66;
  const sy = h * 0.34;
  const sd = dist(x, y, sx, sy);
  if (sd < w * 0.055) {
    c = [255, 255, 255];
  } else if (sd < w * 0.09) {
    c = [40, 140, 255];
  }
  // small ray
  if (Math.abs((y - sy) - (x - sx) * 0.2) < w * 0.01 && x > sx && x < sx + w * 0.1 && y < sy + w * 0.04) {
    c = [90, 180, 255];
  }

  // Soft vignette for depth
  const edge = Math.max(Math.abs(nx - 0.5), Math.abs(ny - 0.5)) * 2;
  if (edge > 0.82) {
    c = mix(c, [200, 225, 245], (edge - 0.82) / 0.18);
  }
  return c;
}

function paintSplash(x, y, w, h) {
  const nx = x / (w - 1);
  const ny = y / (h - 1);
  let c = mix([236, 246, 255], [160, 210, 255], ny * 0.7 + nx * 0.2);
  // centered soft orb
  const d = dist(x, y, w * 0.5, h * 0.42) / (w * 0.35);
  if (d < 1) {
    c = mix(c, [255, 255, 255], (1 - d) * 0.35);
  }
  // draw scaled icon mark in center by sampling paintIcon in a square region
  const side = Math.min(w, h) * 0.36;
  const left = (w - side) / 2;
  const top = h * 0.28;
  if (x >= left && x < left + side && y >= top && y < top + side) {
    const ix = ((x - left) / side) * 511;
    const iy = ((y - top) / side) * 511;
    return paintIcon(ix, iy, 512, 512);
  }
  return c;
}

const icon = pngFromRgb(512, 512, paintIcon);
fs.writeFileSync(path.join(dir, 'icon.png'), icon);
fs.writeFileSync(path.join(dir, 'adaptive-icon.png'), icon);
fs.writeFileSync(path.join(dir, 'splash.png'), pngFromRgb(720, 1280, paintSplash));
fs.writeFileSync(path.join(dir, 'favicon.png'), pngFromRgb(48, 48, paintIcon));
console.log('brand icons written to', dir);
