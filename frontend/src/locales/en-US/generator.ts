/**
 * Code generator page.
 *
 * The key set mirrors `zh-CN/generator.ts` exactly (`npm run i18n:check` verifies this).
 * Table names / comments come from user data or the database and are not part of the language pack.
 */
export default {
  alertTitle: 'The code generator writes files to disk and registers a menu automatically',
  alertDesc:
    'Existing files are kept by default (tick "Allow overwrite"). After generating, run {cmd} to sync button nodes.',
  configTitle: 'Generator settings',
  tableLabel: 'Table',
  tablePlaceholder: 'Please select a table',
  tableRequired: 'Please select a table',
  pluginLabel: 'Plugin',
  pluginPlaceholder: 'e.g. cccms or a business plugin name',
  pluginRequired: 'Please enter the plugin name',
  moduleLabel: 'Module',
  modulePlaceholder: 'Leave empty to derive from the table name',
  titleLabel: 'Module title',
  titlePlaceholder: 'e.g. Product',
  titleRequired: 'Please enter the module title',
  overwriteLabel: 'Overwrite',
  overwriteText: 'Allow overwriting existing files',
  preview: 'Preview',
  generate: 'Generate code',
  fieldsTitle: 'Fields ({count})',
  colName: 'Field',
  colType: 'Type',
  colComment: 'Comment',
  colPrimary: 'Primary key',
  yes: 'Yes',
  previewTitle: 'Preview ({count} file(s))',
  resultTitle: 'Result',
  resultMenu: 'Registered menu: {path} (slug: {slug})',
  resultSnippet: 'To include the declarative source file {file}, paste:',
  generateSuccess: 'Generated successfully, please run cccms:perm-scan to sync button nodes',
}
