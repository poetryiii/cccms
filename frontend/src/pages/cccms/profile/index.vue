<template>
  <div class="art-scroll">
    <el-row :gutter="16">
      <!-- 账号信息（只读） -->
      <el-col :xs="24" :md="10">
        <el-card shadow="never" class="profile-card">
          <template #header>
            <span class="profile-card-title">账号信息</span>
          </template>

          <div class="profile-identity">
            <el-avatar :size="56" :src="detail.avatar || undefined">
              {{ avatarText }}
            </el-avatar>
            <div class="profile-identity-text">
              <div class="profile-name">{{ detail.nickname || detail.username || '—' }}</div>
              <div class="profile-account">@{{ detail.username || '—' }}</div>
            </div>
          </div>

          <el-descriptions :column="1" border size="small" class="profile-desc">
            <el-descriptions-item label="角色">
              {{ detail.roles?.length ? detail.roles.join('、') : '—' }}
            </el-descriptions-item>
            <el-descriptions-item label="部门">
              {{ detail.depts?.length ? detail.depts.join('、') : '—' }}
            </el-descriptions-item>
            <el-descriptions-item label="状态">
              <el-tag :type="detail.status === 1 ? 'success' : 'info'" size="small" effect="light">
                {{ detail.status === 1 ? '启用' : '禁用' }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="最后登录">{{ detail.login_time || '—' }}</el-descriptions-item>
            <el-descriptions-item label="登录 IP">{{ detail.login_ip || '—' }}</el-descriptions-item>
            <el-descriptions-item label="创建时间">{{ detail.create_time || '—' }}</el-descriptions-item>
          </el-descriptions>
        </el-card>
      </el-col>

      <el-col :xs="24" :md="14">
        <!-- 基本资料 -->
        <el-card shadow="never" class="profile-card">
          <template #header>
            <span class="profile-card-title">基本资料</span>
          </template>

          <el-form ref="infoRef" :model="infoForm" :rules="infoRules" label-width="80px">
            <el-form-item label="昵称" prop="nickname">
              <el-input v-model="infoForm.nickname" maxlength="64" show-word-limit placeholder="请输入昵称" />
            </el-form-item>
            <el-form-item label="邮箱" prop="email">
              <el-input v-model="infoForm.email" placeholder="选填" />
            </el-form-item>
            <el-form-item label="手机号" prop="phone">
              <el-input v-model="infoForm.phone" placeholder="选填" />
            </el-form-item>
            <el-form-item label="头像" prop="avatar">
              <el-input v-model="infoForm.avatar" placeholder="图片 URL，选填" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="saving" @click="onSaveInfo">保存</el-button>
              <el-button @click="load">重置</el-button>
            </el-form-item>
          </el-form>
        </el-card>

        <!-- 修改密码 -->
        <el-card shadow="never" class="profile-card">
          <template #header>
            <span class="profile-card-title">修改密码</span>
          </template>

          <el-form ref="pwdRef" :model="pwdForm" :rules="pwdRules" label-width="80px">
            <el-form-item label="原密码" prop="old_password">
              <el-input v-model="pwdForm.old_password" type="password" show-password autocomplete="off" />
            </el-form-item>
            <el-form-item label="新密码" prop="new_password">
              <el-input v-model="pwdForm.new_password" type="password" show-password autocomplete="off" />
            </el-form-item>
            <el-form-item label="确认密码" prop="confirm">
              <el-input v-model="pwdForm.confirm" type="password" show-password autocomplete="off" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="changing" @click="onChangePassword">修改密码</el-button>
              <span class="profile-tip">长度规则由后台「安全 → 密码最小长度」决定</span>
            </el-form-item>
          </el-form>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup lang="ts">
// 组件名与静态路由名保持一致，keep-alive 的 include 才能匹配上
defineOptions({ name: 'profile' })

import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { profileChangePassword, profileRead, profileUpdate, type ProfileDetail } from '@/api/profile'
import { useUserStore } from '@/stores/user'

const userStore = useUserStore()

/** 只读信息 */
const detail = ref<ProfileDetail>({} as ProfileDetail)
const avatarText = computed(() => (detail.value.nickname || detail.value.username || 'U').charAt(0).toUpperCase())

/* ---- 基本资料 ---- */
const infoRef = ref<FormInstance>()
const saving = ref(false)
const infoForm = reactive({ nickname: '', email: '', phone: '', avatar: '' })

const infoRules: FormRules = {
  nickname: [{ required: true, message: '请输入昵称', trigger: 'blur' }],
  email: [{ type: 'email', message: '邮箱格式不正确', trigger: 'blur' }],
}

async function load(): Promise<void> {
  const data = await profileRead()
  detail.value = data
  infoForm.nickname = data.nickname || ''
  infoForm.email = data.email || ''
  infoForm.phone = data.phone || ''
  infoForm.avatar = data.avatar || ''
}

async function onSaveInfo(): Promise<void> {
  const valid = await infoRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  saving.value = true
  try {
    await profileUpdate({ ...infoForm })
    ElMessage.success('保存成功')
    await load()
    // 顶栏的昵称 / 头像要跟着变
    await userStore.fetchProfile()
  } finally {
    saving.value = false
  }
}

/* ---- 修改密码 ---- */
const pwdRef = ref<FormInstance>()
const changing = ref(false)
const pwdForm = reactive({ old_password: '', new_password: '', confirm: '' })

const pwdRules: FormRules = {
  old_password: [{ required: true, message: '请输入原密码', trigger: 'blur' }],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 6, message: '密码至少 6 位', trigger: 'blur' },
  ],
  confirm: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    {
      validator: (_rule, value, callback) => {
        if (value !== pwdForm.new_password) {
          callback(new Error('两次输入的密码不一致'))
          return
        }
        callback()
      },
      trigger: 'blur',
    },
  ],
}

async function onChangePassword(): Promise<void> {
  const valid = await pwdRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  changing.value = true
  try {
    await profileChangePassword({
      old_password: pwdForm.old_password,
      new_password: pwdForm.new_password,
    })
    ElMessage.success('密码已修改')
    pwdRef.value?.resetFields()
  } finally {
    changing.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.profile-card + .profile-card {
  margin-top: 16px;
}

.profile-card-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--art-main);
}

.profile-identity {
  display: flex;
  gap: 14px;
  align-items: center;
  padding-bottom: 16px;
  margin-bottom: 16px;
  border-bottom: 1px solid var(--art-card-border);
}

.profile-identity-text {
  min-width: 0;
}

.profile-name {
  font-size: 16px;
  font-weight: 600;
  color: var(--art-main);
}

.profile-account {
  margin-top: 2px;
  font-size: 12px;
  color: var(--art-muted);
}

.profile-desc :deep(.el-descriptions__label) {
  width: 92px;
}

.profile-tip {
  margin-left: 10px;
  font-size: 12px;
  color: var(--art-muted);
}
</style>
