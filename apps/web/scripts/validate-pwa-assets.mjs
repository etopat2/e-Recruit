import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { inflateSync } from 'node:zlib'

const root = fileURLToPath(new URL('..', import.meta.url))
const publicRoot = join(root, 'public')
const distRoot = join(root, 'dist')

function fail(message) {
  throw new Error(`PWA validation failed: ${message}`)
}

function paeth(left, above, upperLeft) {
  const prediction = left + above - upperLeft
  const leftDistance = Math.abs(prediction - left)
  const aboveDistance = Math.abs(prediction - above)
  const upperLeftDistance = Math.abs(prediction - upperLeft)
  if (leftDistance <= aboveDistance && leftDistance <= upperLeftDistance) return left
  return aboveDistance <= upperLeftDistance ? above : upperLeft
}

function decodeRgbaPng(path) {
  const bytes = readFileSync(path)
  if (bytes.subarray(1, 4).toString() !== 'PNG') fail(`${path} is not a PNG file`)
  const width = bytes.readUInt32BE(16)
  const height = bytes.readUInt32BE(20)
  const bitDepth = bytes[24]
  const colorType = bytes[25]
  const interlace = bytes[28]
  if (bitDepth !== 8 || colorType !== 6 || interlace !== 0) fail(`${path} must be a non-interlaced 8-bit RGBA PNG`)

  const chunks = []
  for (let offset = 8; offset < bytes.length;) {
    const length = bytes.readUInt32BE(offset)
    const type = bytes.subarray(offset + 4, offset + 8).toString()
    if (type === 'IDAT') chunks.push(bytes.subarray(offset + 8, offset + 8 + length))
    offset += length + 12
  }
  const compressed = Buffer.concat(chunks)
  const filtered = inflateSync(compressed)
  const bytesPerPixel = 4
  const stride = width * bytesPerPixel
  const pixels = Buffer.alloc(stride * height)
  let sourceOffset = 0
  for (let row = 0; row < height; row += 1) {
    const filter = filtered[sourceOffset]
    sourceOffset += 1
    for (let column = 0; column < stride; column += 1) {
      const raw = filtered[sourceOffset + column]
      const target = row * stride + column
      const left = column >= bytesPerPixel ? pixels[target - bytesPerPixel] : 0
      const above = row > 0 ? pixels[target - stride] : 0
      const upperLeft = row > 0 && column >= bytesPerPixel ? pixels[target - stride - bytesPerPixel] : 0
      pixels[target] = filter === 0 ? raw
        : filter === 1 ? (raw + left) & 255
          : filter === 2 ? (raw + above) & 255
            : filter === 3 ? (raw + Math.floor((left + above) / 2)) & 255
              : filter === 4 ? (raw + paeth(left, above, upperLeft)) & 255
                : fail(`${path} uses unsupported PNG filter ${filter}`)
    }
    sourceOffset += stride
  }
  return { width, height, pixels, alphaAt: (x, y) => pixels[(y * width + x) * 4 + 3] }
}

const transparentIcons = new Map([
  ['icons/favicon-16.png', 16],
  ['icons/favicon-32.png', 32],
  ['icons/app-icon-64.png', 64],
  ['icons/app-icon-192.png', 192],
  ['icons/app-icon-512.png', 512],
])
for (const [relativePath, size] of transparentIcons) {
  const icon = decodeRgbaPng(join(publicRoot, relativePath))
  if (icon.width !== size || icon.height !== size) fail(`${relativePath} must be exactly ${size}x${size}`)
  if ([icon.alphaAt(0, 0), icon.alphaAt(size - 1, 0), icon.alphaAt(0, size - 1), icon.alphaAt(size - 1, size - 1)].some((alpha) => alpha !== 0)) {
    fail(`${relativePath} must have fully transparent corner pixels`)
  }
  if (icon.alphaAt(Math.floor(size / 2), Math.floor(size / 2)) !== 255) fail(`${relativePath} must have an opaque centre`)
}

const brandLogo = decodeRgbaPng(join(publicRoot, 'brand/ups-logo.png'))
if ([brandLogo.alphaAt(0, 0), brandLogo.alphaAt(brandLogo.width - 1, 0), brandLogo.alphaAt(0, brandLogo.height - 1), brandLogo.alphaAt(brandLogo.width - 1, brandLogo.height - 1)].some((alpha) => alpha !== 0)) {
  fail('brand/ups-logo.png must retain its transparent background')
}

for (const [relativePath, size] of [['icons/maskable-icon-192.png', 192], ['icons/maskable-icon-512.png', 512], ['icons/apple-touch-icon.png', 180]]) {
  const icon = decodeRgbaPng(join(publicRoot, relativePath))
  if (icon.width !== size || icon.height !== size) fail(`${relativePath} must be exactly ${size}x${size}`)
  if (icon.alphaAt(0, 0) !== 255) fail(`${relativePath} must be full-bleed for operating-system masking`)
}

const manifestPath = join(distRoot, 'manifest.webmanifest')
if (!existsSync(manifestPath) || !existsSync(join(distRoot, 'sw.js'))) fail('production build must contain a manifest and service worker')
for (const relativePath of [...transparentIcons.keys(), 'icons/maskable-icon-192.png', 'icons/maskable-icon-512.png', 'icons/apple-touch-icon.png', 'brand/ups-logo.png', 'favicon.ico']) {
  const builtPath = join(distRoot, relativePath)
  if (!existsSync(builtPath) || !readFileSync(builtPath).equals(readFileSync(join(publicRoot, relativePath)))) {
    fail(`${relativePath} must be copied unchanged into the production build`)
  }
}
const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'))
if (manifest.display !== 'standalone' || manifest.start_url !== '/' || manifest.scope !== '/') fail('manifest must launch as a root-scoped standalone app')
for (const expected of [
  ['/icons/app-icon-192.png', '192x192', 'any'],
  ['/icons/app-icon-512.png', '512x512', 'any'],
  ['/icons/maskable-icon-192.png', '192x192', 'maskable'],
  ['/icons/maskable-icon-512.png', '512x512', 'maskable'],
]) {
  if (!manifest.icons?.some((icon) => icon.src === expected[0] && icon.sizes === expected[1] && icon.purpose === expected[2])) {
    fail(`manifest is missing ${expected.join(' ')}`)
  }
}
const index = readFileSync(join(distRoot, 'index.html'), 'utf8')
if (!index.includes('rel="manifest"') || !index.includes('apple-mobile-web-app-capable')) fail('built HTML must advertise PWA installation metadata')

console.log('PWA manifest, service worker, icon dimensions, maskability, and transparent corners are valid.')
