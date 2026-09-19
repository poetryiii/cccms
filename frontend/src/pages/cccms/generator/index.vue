<template>
  <div class="art-scroll">
    <div class="art-page">
      <el-alert type="warning" :closable="false" show-icon class="gen-alert">
        <template #title>{{ t('generator.alertTitle') }}</template>
        <i18n-t keypath="generator.alertDesc" scope="global" tag="div">
          <template #cmd><code>php webman cccms:perm-scan</code></template>
        </i18n-t>
      </el-alert>

      <el-card shadow="never">
        <template #header
          ><span>{{ t('generator.configTitle') }}</span></template
        >

        <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" class="gen-form">
          <el-form-item :label="t('generator.tableLabel')" prop="table">
            <el-select
              v-model="form.table"
              filterable
              :placeholder="t('generator.tablePlaceholder')"
              style="width: 100%"
              @change="onTableChange"
            >
              <el-option
                v-for="t in tables"
                :key="t.name"
                :label="t.comment ? `${t.name}（${t.comment}）` : t.name"
                :value="t.name"
              />
            </el-select>
          </el-form-item>

          <el-row :gutter="16">
            <el-col :span="12">
              <el-form-item :label="t('generator.pluginLabel')" prop="plugin">
                <el-input v-model="form.plugin" :placeholder="t('generator.pluginPlaceholder')" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item :label="t('generator.moduleLabel')" prop="module">
                <el-input v-model="form.module" :placeholder="t('generator.modulePlaceholder')" />
              </el-form-item>
            </el-col>
          </el-row>

          <el-form-item :label="t('generator.titleLabel')" prop="title">
            <el-input v-model="form.title" :placeholder="t('generator.titlePlaceholder')" />
          </el-form-item>

          <el-form-item :label="t('generator.overwriteLabel')">
            <el-checkbox v-model="form.overwrite">{{ t('generator.overwriteText') }}</el-checkbox>
          </el-form-item>

          <el-form-item>
            <el-button type="primary" :loading="previewing" :icon="View" @click="onPreview">
              {{ t('generator.preview') }}
            </el-button>
            <el-button
              v-auth="'cccms:generator:generate'"
              type="danger"
              plain
              :loading="generating"
              :icon="MagicStick"
              @click="onGenerate"
            >
              {{ t('generator.generate') }}
            </el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card v-if="columns.length" shadow="never" class="gen-block">
        <template #header>
          <span>{{ t('generator.fieldsTitle', { count: columns.length }) }}</span>
        </template>
        <el-table :data="columns" stripe border size="small">
          <el-table-column prop="name" :label="t('generator.colName')" min-width="160" />
          <el-table-column prop="type" :label="t('generator.colType')" width="140" />
          <el-table-column prop="comment" :label="t('generator.colComment')" min-width="180" />
          <el-table-column :label="t('generator.colPrimary')" width="80" align="center">
            <template #default="{ row }">
              <el-tag v-if="row.primary" type="success" effect="light" size="small">
                {{ t('generator.yes') }}
              </el-tag>
            </template>
          </el-table-column>
        </el-table>
      </el-card>

      <el-card v-if="files.length" shadow="never" class="gen-block">
        <template #header>
          <span>{{ t('generator.previewTitle', { count: files.length }) }}</span>
        </template>
        <el-tabs>
          <el-tab-pane v-for="f in files" :key="f.path" :label="f.path.split('/').pop()">
            <div class="file-path">{{ f.path }}</div>
            <pre class="code-block">{{ f.content }}</pre>
          </el-tab-pane>
        </el-tabs>
      </el-card>

      <el-card v-if="result" shadow="never" class="gen-block">
        <template #header
          ><span>{{ t('generator.resultTitle') }}</span></template
        >
        <div class="result-files">
          <el-tag v-for="f in result.files" :key="f" type="success" effect="light">{{ f }}</el-tag>
        </div>
        <el-alert type="info" :closable="false" class="result-alert">
          <i18n-t keypath="generator.resultMenu" scope="global" tag="div">
            <template #path
              ><b>{{ result.menu.path }}</b></template
            >
            <template #slug>{{ result.menu.slug }}</template>
          </i18n-t>
          <i18n-t keypath="generator.resultSnippet" scope="global" tag="div" class="result-tip">
            <template #file
              ><code>plugin/{{ form.plugin }}/db/menu.php</code></template
            >
          </i18n-t>
          <pre class="code-block">{{ result.menu_snippet }}</pre>
        </el-alert>
      </el-card>
    </div>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:generator' })

import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { MagicStick, View } from '@element-plus/icons-vue'
import {
  generatorColumns,
  generatorGenerate,
  generatorPreview,
  generatorTables,
  type ColumnInfo,
  type GeneratedFile,
  type GenerateResult,
  type TableInfo,
} from '@/api/generator'

const { t } = useI18n({ useScope: 'global' })

const tables = ref<TableInfo[]>([])
const columns = ref<ColumnInfo[]>([])
const files = ref<GeneratedFile[]>([])
const result = ref<GenerateResult | null>(null)

const previewing = ref(false)
const generating = ref(false)

const formRef = ref<FormInstance>()
const form = reactive({
  table: '',
  plugin: 'cccms',
  module: '',
  title: '',
  overwrite: false,
})

// 校验提示同样走 i18n：用 computed 保证切换语言后规则文案立即更新
const rules = computed<FormRules>(() => ({
  table: [{ required: true, message: t('generator.tableRequired'), trigger: 'change' }],
  plugin: [{ required: true, message: t('generator.pluginRequired'), trigger: 'blur' }],
  title: [{ required: true, message: t('generator.titleRequired'), trigger: 'blur' }],
}))

function config(): Record<string, unknown> {
  return { ...form }
}

async function onTableChange(value: unknown): Promise<void> {
  const table = String(value || '')
  columns.value = []
  files.value = []
  result.value = null
  if (!table) {
    return
  }
  columns.value = await generatorColumns(table)
  if (!form.title) {
    const hit = tables.value.find((t) => t.name === table)
    form.title = hit?.comment || table
  }
}

async function onPreview(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  previewing.value = true
  try {
    files.value = await generatorPreview(config())
    result.value = null
  } finally {
    previewing.value = false
  }
}

async function onGenerate(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  generating.value = true
  try {
    result.value = await generatorGenerate(config())
    files.value = []
    ElMessage.success(t('generator.generateSuccess'))
  } finally {
    generating.value = false
  }
}

onMounted(async () => {
  tables.value = await generatorTables()
})
</script>

<style scoped>
.gen-alert {
  margin-bottom: 14px;
}

.gen-form {
  max-width: 720px;
}

.gen-block {
  margin-top: 14px;
}

.file-path {
  margin-bottom: 8px;
  font-size: 12px;
  color: var(--art-muted);
}

.code-block {
  max-height: 420px;
  padding: 12px;
  margin: 0;
  overflow: auto;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  line-height: 1.6;
  color: var(--art-sub);
  word-break: break-all;
  white-space: pre-wrap;
  background: var(--art-hover-bg);
  border-radius: calc(var(--art-radius) - 4px);
}

.result-files {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
}

.result-tip {
  margin-top: 6px;
}
</style>
