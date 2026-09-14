<template>
  <div class="art-scroll">
    <div class="art-page">
      <el-alert type="warning" :closable="false" show-icon class="gen-alert">
        <template #title>代码生成器会直接写入磁盘文件并自动登记菜单</template>
        <div>
          已存在的文件默认不覆盖（可勾选「允许覆盖」）。生成后请执行
          <code>php webman cccms:perm-scan</code> 同步按钮节点。
        </div>
      </el-alert>

      <el-card shadow="never">
        <template #header><span>生成配置</span></template>

        <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" class="gen-form">
          <el-form-item label="数据表" prop="table">
            <el-select
              v-model="form.table"
              filterable
              placeholder="请选择数据表"
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
              <el-form-item label="插件名" prop="plugin">
                <el-input v-model="form.plugin" placeholder="如 cccms 或业务插件名" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="模块名" prop="module">
                <el-input v-model="form.module" placeholder="留空则按表名推导" />
              </el-form-item>
            </el-col>
          </el-row>

          <el-form-item label="模块标题" prop="title">
            <el-input v-model="form.title" placeholder="如 商品" />
          </el-form-item>

          <el-form-item label="覆盖文件">
            <el-checkbox v-model="form.overwrite">允许覆盖已存在的文件</el-checkbox>
          </el-form-item>

          <el-form-item>
            <el-button type="primary" :loading="previewing" :icon="View" @click="onPreview">
              预览
            </el-button>
            <el-button
              v-auth="'cccms:generator:generate'"
              type="danger"
              plain
              :loading="generating"
              :icon="MagicStick"
              @click="onGenerate"
            >
              生成代码
            </el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card v-if="columns.length" shadow="never" class="gen-block">
        <template #header><span>字段（{{ columns.length }}）</span></template>
        <el-table :data="columns" stripe border size="small">
          <el-table-column prop="name" label="字段" min-width="160" />
          <el-table-column prop="type" label="类型" width="140" />
          <el-table-column prop="comment" label="注释" min-width="180" />
          <el-table-column label="主键" width="80" align="center">
            <template #default="{ row }">
              <el-tag v-if="row.primary" type="success" effect="light" size="small">是</el-tag>
            </template>
          </el-table-column>
        </el-table>
      </el-card>

      <el-card v-if="files.length" shadow="never" class="gen-block">
        <template #header><span>生成预览（{{ files.length }} 个文件）</span></template>
        <el-tabs>
          <el-tab-pane
            v-for="f in files"
            :key="f.path"
            :label="f.path.split('/').pop()"
          >
            <div class="file-path">{{ f.path }}</div>
            <pre class="code-block">{{ f.content }}</pre>
          </el-tab-pane>
        </el-tabs>
      </el-card>

      <el-card v-if="result" shadow="never" class="gen-block">
        <template #header><span>生成结果</span></template>
        <div class="result-files">
          <el-tag v-for="f in result.files" :key="f" type="success" effect="light">{{ f }}</el-tag>
        </div>
        <el-alert type="info" :closable="false" class="result-alert">
          <div>已登记菜单：<b>{{ result.menu.path }}</b>（slug: {{ result.menu.slug }}）</div>
          <div class="result-tip">
            如需纳入声明式源文件 <code>plugin/{{ form.plugin }}/db/menu.php</code>，可粘贴：
          </div>
          <pre class="code-block">{{ result.menu_snippet }}</pre>
        </el-alert>
      </el-card>
    </div>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'cccms:generator' })

import { onMounted, reactive, ref } from 'vue'
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

const rules: FormRules = {
  table: [{ required: true, message: '请选择数据表', trigger: 'change' }],
  plugin: [{ required: true, message: '请输入插件名', trigger: 'blur' }],
  title: [{ required: true, message: '请输入模块标题', trigger: 'blur' }],
}

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
    ElMessage.success('生成成功，请执行 cccms:perm-scan 同步按钮节点')
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
