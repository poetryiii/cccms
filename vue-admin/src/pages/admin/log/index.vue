<template>
  <a-card>
    <Table
      hideCardBorder
      :fields="table.fields"
      :ignoreFields="table.ignoreFields"
      v-model:columns="table.columns"
      v-model:pagination="table.pagination"
      :data="table.datas"
      row-key="id"
      @reload="getLogs"
    >
      <template #headerButton>
        <a-popconfirm
          content="是否清空30天前的日志？"
          @ok="delLog()"
          type="warning"
        >
          <a-button
            type="primary"
            status="danger"
            v-permission="'admin/log/delete'"
          >
            清空30天前日志
          </a-button>
        </a-popconfirm>
      </template>
      <template #userFilter>
        <a-card :style="{ width: '200px' }">
          <a-input
            v-model="table.form.user"
            placeholder="请输入用户账号或昵称"
          />
          <template #actions>
            <a-button size="mini" type="primary" @click="getLogs">
              确定
            </a-button>
          </template>
        </a-card>
      </template>
      <template #methodFilter>
        <a-card :style="{ width: '200px' }">
          <a-select
            v-model="table.form.req_method"
            placeholder="请选择请求类型..."
            allow-clear
          >
            <a-option value="GET">GET</a-option>
            <a-option value="HEAD">HEAD</a-option>
            <a-option value="POST">POST</a-option>
            <a-option value="PUT">PUT</a-option>
            <a-option value="DELETE">DELETE</a-option>
            <a-option value="CONNECT">CONNECT</a-option>
            <a-option value="OPTIONS">OPTIONS</a-option>
            <a-option value="TRACE">TRACE</a-option>
            <a-option value="PATCH">PATCH</a-option>
          </a-select>
          <template #actions>
            <a-button size="mini" type="primary" @click="getLogs">
              确定
            </a-button>
          </template>
        </a-card>
      </template>
      <template #paramsFilter>
        <a-card :style="{ width: '200px' }">
          <a-input
            v-model="table.form.req_params"
            placeholder="模糊查询请求参数..."
          />
          <template #actions>
            <a-button size="mini" type="primary" @click="getLogs">
              确定
            </a-button>
          </template>
        </a-card>
      </template>
      <template #user="{ record }">
        <a-typography-paragraph
          copyable
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
        >
          <span>{{ record.nickname }}({{ record.username }})</span>
        </a-typography-paragraph>
      </template>
      <template #reqParams="{ record }">
        <a-button
          type="primary"
          size="mini"
          @click="showParamModal(record.req_params)"
        >
          查看
        </a-button>
      </template>
      <template #reqIp="{ record }">
        <a-typography-paragraph
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
        >
          {{ record.req_ip }}
        </a-typography-paragraph>
      </template>
      <template #reqUa="{ record }">
        <a-typography-paragraph
          copyable
          :ellipsis="{
            rows: 1,
            showTooltip: true,
          }"
        >
          {{ record.req_ua }}
        </a-typography-paragraph>
      </template>
      <template #operation="{ record }">
        <a-button
          type="text"
          size="mini"
          @click="showDetailModal(record.id)"
        >
          <template #icon>
            <i class="ri-file-list-3-line"></i>
          </template>
        </a-button>
      </template>
    </Table>
    <!-- 请求参数弹窗 -->
    <a-modal
      unmount-on-close
      :visible="paramsVisible"
      @cancel="cancelParamsModal"
      :closable="false"
      :footer="false"
    >
      <a-typography class="params">
        <a-typography-paragraph blockquote>
          <pre>{{ JSON.parse(paramsData) }}</pre>
        </a-typography-paragraph>
      </a-typography>
    </a-modal>
    <!-- 日志详情弹窗 -->
    <a-modal
      :visible="detailVisible"
      title="日志详情"
      :width="720"
      @cancel="cancelDetailModal"
      :footer="false"
      unmount-on-close
    >
      <div v-if="detailLoading" style="text-align:center;padding:40px">
        <a-spin :loading="true" />
        <p style="color:#999;margin-top:8px">加载中...</p>
      </div>
      <a-empty v-else-if="!detail" description="加载失败，请重试" />
      <a-descriptions
        v-else
        :column="1"
        bordered
        size="small"
        layout="inline-horizontal"
        :label-style="{ width: '100px' }"
      >
          <a-descriptions-item label="行为名称">
            {{ detail.name }}
          </a-descriptions-item>
          <a-descriptions-item label="操作节点">
            {{ detail.node }}
          </a-descriptions-item>
          <a-descriptions-item label="操作用户">
            {{ detail.nickname || '未知' }}({{ detail.username || '-' }})
          </a-descriptions-item>
          <a-descriptions-item label="请求方式">
            <a-tag :color="methodColor(detail.req_method)" size="small">
              {{ detail.req_method }}
            </a-tag>
          </a-descriptions-item>
          <a-descriptions-item label="请求IP">
            {{ detail.req_ip }}
          </a-descriptions-item>
          <a-descriptions-item label="User-Agent">
            <a-typography-paragraph :ellipsis="{ rows: 1 }">
              {{ detail.req_ua }}
            </a-typography-paragraph>
          </a-descriptions-item>
          <a-descriptions-item label="请求参数">
            <pre v-if="detail.info && detail.info.req_params">{{
              formatJson(detail.info.req_params)
            }}</pre>
            <span v-else style="color: #999">-</span>
          </a-descriptions-item>
          <a-descriptions-item label="修改参数">
            <pre v-if="detail.info && detail.info.upd_params">{{
              formatJson(detail.info.upd_params)
            }}</pre>
            <span v-else style="color: #999">-</span>
          </a-descriptions-item>
          <a-descriptions-item label="请求结果">
            <pre v-if="detail.info && detail.info.req_result">{{
              formatJson(detail.info.req_result)
            }}</pre>
            <span v-else style="color: #999">-</span>
          </a-descriptions-item>
          <a-descriptions-item label="操作时间">
            {{ detail.create_time }}
          </a-descriptions-item>
        </a-descriptions>
    </a-modal>
  </a-card>
</template>

<script setup>
import { reactive, ref, watch, onMounted } from 'vue';
import { Message } from '@arco-design/web-vue';
import { logQuery, logDelete, logInfoQuery } from '@/api/admin/log';
import Table from '@/components/table/index.vue';

const getLogs = async () => {
  const {
    data: { fields, total, data },
  } = await logQuery({ ...table.form, ...table.pagination });
  table.datas = data;
  table.fields = fields;
  table.pagination.total = total;
};

const delLog = (row) => {
  logDelete(row).then((res) => {
    Message.success('删除成功');
    getLogs();
  });
};

// 查看参数弹窗
const paramsVisible = ref(false);
const paramsData = ref(null);

const showParamModal = (param) => {
  paramsData.value = param;
  paramsVisible.value = true;
};

const cancelParamsModal = () => {
  paramsVisible.value = false;
};

// 日志详情弹窗
const detailVisible = ref(false);
const detailLoading = ref(false);
const detail = ref(null);

const showDetailModal = async (id) => {
  detailVisible.value = true;
  detailLoading.value = true;
  detail.value = null;
  try {
    const res = await logInfoQuery({ id });
    detail.value = res.data;
  } catch (e) {
    detail.value = null;
  } finally {
    detailLoading.value = false;
  }
};

const cancelDetailModal = () => {
  detailVisible.value = false;
};

const formatJson = (val) => {
  if (typeof val === 'string') {
    try { return JSON.stringify(JSON.parse(val), null, 2); } catch (e) {}
    return val;
  }
  return JSON.stringify(val, null, 2);
};

const methodColor = (method) => {
  const map = { GET: 'blue', POST: 'green', PUT: 'orange', DELETE: 'red', PATCH: 'purple' };
  return map[method] || 'gray';
};

const table = reactive({
  form: {
    user: undefined,
    req_method: undefined,
    req_params: undefined,
  },
  pagination: {
    page: 1,
    limit: 15,
    total: 0,
  },
  users: [],
  datas: [],
  fields: [],
  ignoreFields: ['operation'],
  columns: [
    {
      dataIndex: 'user_id',
      title: '用户昵称(账号)',
      width: 160,
      slotName: 'user',
      filterable: {
        slotName: 'userFilter',
      },
    },
    {
      dataIndex: 'req_method',
      title: '请求类型',
      width: 90,
      filterable: {
        slotName: 'methodFilter',
      },
    },
    { dataIndex: 'name', title: '行为名称', width: 180 },
    { dataIndex: 'node', title: '操作节点', width: 250 },
    {
      dataIndex: 'req_params',
      title: '请求参数',
      width: 100,
      slotName: 'reqParams',
      filterable: {
        slotName: 'paramsFilter',
      },
    },
    { dataIndex: 'req_ip', title: '请求IP', width: 150, slotName: 'reqIp' },
    { dataIndex: 'req_ua', title: 'User-Agent', width: 230, slotName: 'reqUa' },
    { dataIndex: 'create_time', title: '创建时间', width: 180 },
    {
      dataIndex: 'operation',
      title: '操作',
      width: 60,
      align: 'center',
      fixed: 'right',
      slotName: 'operation',
    },
  ],
});
</script>

<style scoped lang="less">
.params {
  max-height: 500px !important;
}
.detail-content {
  pre {
    margin: 0;
    max-height: 200px;
    overflow: auto;
    background: var(--color-fill-2);
    padding: 8px 12px;
    border-radius: 4px;
    font-size: 12px;
    white-space: pre-wrap;
    word-break: break-all;
  }
}
</style>
