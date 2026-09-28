/**
 * 简体中文语言包聚合（默认语言 / fallback）。
 *
 * **自动加载**：本文件用 `import.meta.glob` 按「文件名 = 命名空间」自动收集同目录下
 * 除 `index.ts` 外的所有 `*.ts` 语言模块，**新增语言包无需再手动 import / 注册**。
 *
 * 只需保证 `zh-CN` / `en-US` 两个目录的**文件名与 key 集合完全一致**
 * （`npm run i18n:check` 在 CI 里校验）。命名空间 = 文件名：
 * `log.ts` → `log`、`data_rule.ts` → `data_rule`、`export.ts` → `export`。
 *
 * 后端下发的菜单标题、配置项名等不属于前端语言包（由后端按当前请求语言翻译）。
 */
const modules = import.meta.glob<{ default: Record<string, unknown> }>('./*.ts', { eager: true })

const messages: Record<string, Record<string, unknown>> = {}
for (const [path, mod] of Object.entries(modules)) {
  const name = path.replace(/^\.\/(.+)\.ts$/, '$1')
  if (name === 'index') {
    continue
  }
  messages[name] = mod.default
}

export default messages
