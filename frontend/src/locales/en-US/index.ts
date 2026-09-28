/**
 * English language pack aggregate.
 *
 * **Auto-loaded**: uses `import.meta.glob` to collect every `*.ts` module in this
 * directory (except `index.ts`) with "filename = namespace", so adding a new pack
 * no longer requires a manual import/registration.
 *
 * Keep the `zh-CN` / `en-US` directories' file names and key sets identical
 * (`npm run i18n:check` verifies this in CI). Namespace = filename:
 * `log.ts` → `log`, `data_rule.ts` → `data_rule`, `export.ts` → `export`.
 *
 * Backend-provided menu titles and config item names are not part of the frontend packs
 * (the backend translates them for the current request locale).
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
