import { cpSync, existsSync, rmSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = dirname(fileURLToPath(import.meta.url))
const src = join(root, '../dist')
const dest = join(root, '../../api/public/stories-editor')

if (!existsSync(src)) {
  console.error('Build output not found:', src)
  process.exit(1)
}

if (existsSync(dest)) {
  rmSync(dest, { recursive: true })
}

cpSync(src, dest, { recursive: true })
console.log('Copied stories-editor build to', dest)
