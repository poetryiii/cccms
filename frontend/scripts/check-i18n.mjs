/**
 * 语言包体检（CI 用）。
 *
 * 检查两件事，前者是**硬性失败**，后者只做提示：
 *  ① zh-CN / en-US 的命名空间文件与 key 集合必须完全一致
 *     —— 不一致时缺 key 的一侧会在界面上直接暴露 `user.xxx` 这样的 key 原文。
 *  ② 扫描页面/组件里残留的硬编码中文（info）—— 用户录入数据与注释无法静态区分，
 *     所以只统计不判失败，避免出现「为了过 CI 而乱加 key」的反效果。
 *
 * 语言包是 TS，Node 不能直接 import，这里复用 vite 自带的 esbuild 编译成 CJS 再加载，
 * 不额外引入依赖。
 */

import { readdir, readFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { build } from 'esbuild'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const LOCALES = path.join(ROOT, 'src/locales')
const DEFAULT_LOCALE = 'zh-CN'

/** 把某个语言目录的 index.ts 打包成 CJS 并在内存里求值，返回语言包对象 */
async function loadLocale(locale) {
  const result = await build({
    entryPoints: [path.join(LOCALES, locale, 'index.ts')],
    bundle: true,
    format: 'cjs',
    platform: 'node',
    write: false,
    logLevel: 'silent',
  })

  const code = result.outputFiles[0].text
  const module = { exports: {} }
  new Function('module', 'exports', 'require', code)(module, module.exports, () => ({}))
  return module.exports.default ?? module.exports
}

/** 把嵌套语言包拍平成 `命名空间.键名` 的集合 */
function flatten(messages, prefix = '') {
  const keys = new Set()
  for (const [key, value] of Object.entries(messages)) {
    const full = prefix ? `${prefix}.${key}` : key
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      for (const nested of flatten(value, full)) {
        keys.add(nested)
      }
    } else {
      keys.add(full)
    }
  }
  return keys
}

/** 列出语言目录下的模块文件名（不含 index.ts），用于发现「只在一侧存在的文件」 */
async function moduleFiles(locale) {
  const entries = await readdir(path.join(LOCALES, locale), { withFileTypes: true })
  return entries
    .filter((entry) => entry.isFile() && entry.name.endsWith('.ts') && entry.name !== 'index.ts')
    .map((entry) => entry.name)
    .sort()
}

/** 递归收集待扫描的源码文件 */
async function sourceFiles(dir, out = []) {
  let entries
  try {
    entries = await readdir(dir, { withFileTypes: true })
  } catch {
    return out
  }
  for (const entry of entries) {
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) {
      await sourceFiles(full, out)
    } else if (/\.(vue|ts)$/.test(entry.name) && !entry.name.endsWith('.d.ts')) {
      out.push(full)
    }
  }
  return out
}

/** 粗略统计一行的可见文案里是否含中文（排除注释行） */
const CHINESE = /[\u4e00-\u9fff]/

function countHardcodedChinese(source) {
  let hits = 0
  for (const rawLine of source.split('\n')) {
    const line = rawLine.trim()
    if (line.startsWith('//') || line.startsWith('*') || line.startsWith('/*')) {
      continue
    }
    // 模板里 {{ t('x') }} 之外的中文，以及作为属性值的整句中文
    if (CHINESE.test(line)) {
      hits++
    }
  }
  return hits
}

async function main() {
  const problems = []

  // ---- ① 文件级对齐 ----
  const [zhFiles, enFiles] = await Promise.all([moduleFiles(DEFAULT_LOCALE), moduleFiles('en-US')])
  for (const name of zhFiles.filter((n) => !enFiles.includes(n))) {
    problems.push(`语言模块只存在于 ${DEFAULT_LOCALE}：${name}`)
  }
  for (const name of enFiles.filter((n) => !zhFiles.includes(n))) {
    problems.push(`语言模块只存在于 en-US：${name}`)
  }

  // ---- ② key 级对齐 ----
  const [zh, en] = await Promise.all([loadLocale(DEFAULT_LOCALE), loadLocale('en-US')])
  const zhKeys = flatten(zh)
  const enKeys = flatten(en)

  const missingInEn = [...zhKeys].filter((key) => !enKeys.has(key))
  const missingInZh = [...enKeys].filter((key) => !zhKeys.has(key))

  for (const key of missingInEn.slice(0, 20)) {
    problems.push(`en-US 缺少 key：${key}`)
  }
  for (const key of missingInZh.slice(0, 20)) {
    problems.push(`${DEFAULT_LOCALE} 缺少 key：${key}`)
  }

  // ---- ③ 硬编码中文（提示，不判失败） ----
  const targets = [path.join(ROOT, 'src/pages'), path.join(ROOT, 'src/layouts'), path.join(ROOT, 'src/components')]
  const files = (await Promise.all(targets.map((dir) => sourceFiles(dir)))).flat()
  const dirty = []
  for (const file of files) {
    const source = await readFile(file, 'utf8')
    const hits = countHardcodedChinese(source)
    if (hits > 0) {
      dirty.push({ file: path.relative(ROOT, file), hits })
    }
  }
  dirty.sort((a, b) => b.hits - a.hits)

  // ---- 输出 ----
  console.log(`语言包：${DEFAULT_LOCALE} ${zhKeys.size} key，en-US ${enKeys.size} key`)
  if (problems.length === 0) {
    console.log('✓ 两语言命名空间与 key 集合完全一致')
  } else {
    console.error(`\n✗ 语言包不一致（共 ${problems.length} 处）：`)
    for (const problem of problems) {
      console.error(`  - ${problem}`)
    }
  }

  if (dirty.length > 0) {
    console.log(`\n提示：以下文件仍有硬编码中文（含用户数据与注释，仅供参考，不影响结果）：`)
    for (const { file, hits } of dirty.slice(0, 15)) {
      console.log(`  ${String(hits).padStart(4)} 行  ${file}`)
    }
    if (dirty.length > 15) {
      console.log(`  … 其余 ${dirty.length - 15} 个文件省略`)
    }
  }

  process.exit(problems.length === 0 ? 0 : 1)
}

main().catch((error) => {
  console.error(error)
  process.exit(1)
})
