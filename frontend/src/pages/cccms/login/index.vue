<template>
  <div class="login">
    <div class="login-theme">
      <el-tooltip :content="setting.isDark ? '切换到亮色' : '切换到暗色'" placement="bottom">
        <el-button text circle @click="setting.toggleDark()">
          <el-icon :size="18">
            <Sunny v-if="setting.isDark" />
            <Moon v-else />
          </el-icon>
        </el-button>
      </el-tooltip>
    </div>

    <div class="login-card">
      <!-- 左侧品牌区（窄屏隐藏） -->
      <div class="login-brand">
        <div class="login-brand-blob is-a" />
        <div class="login-brand-blob is-b" />

        <div class="login-brand-head">
          <img v-if="appStore.logo" class="login-brand-img" :src="appStore.logo" alt="logo" />
          <div v-else class="login-brand-logo">{{ brandInitial }}</div>
          <span class="login-brand-name">{{ appStore.systemName }}</span>
        </div>

        <h1 class="login-brand-title">企业级后台管理系统</h1>
        <p class="login-brand-desc">权限、组织、数据范围一体化，开箱即用的后台底座</p>

        <ul class="login-brand-list">
          <li v-for="item in FEATURES" :key="item">
            <el-icon :size="14"><Check /></el-icon>
            <span>{{ item }}</span>
          </li>
        </ul>
      </div>

      <!-- 右侧表单 -->
      <div class="login-form">
        <div class="login-form-head">
          <h2 class="login-form-title">欢迎回来</h2>
          <p class="login-form-desc">请使用你的账号登录</p>
        </div>

        <!-- 维护模式（system.maintenance）开启时，仅超管可登录 -->
        <el-alert
          v-if="appStore.maintenance"
          class="login-maintenance"
          type="warning"
          :closable="false"
          show-icon
          :title="appStore.notice"
        />

        <el-form
          ref="formRef"
          :model="form"
          :rules="rules"
          size="large"
          @keyup.enter="onSubmit"
        >
          <el-form-item prop="username">
            <el-input v-model="form.username" placeholder="用户名" clearable>
              <template #prefix><el-icon><User /></el-icon></template>
            </el-input>
          </el-form-item>

          <el-form-item prop="password">
            <el-input v-model="form.password" type="password" show-password placeholder="密码">
              <template #prefix><el-icon><Lock /></el-icon></template>
            </el-input>
          </el-form-item>

          <el-form-item v-if="captchaImage" prop="captcha">
            <div class="login-captcha">
              <el-input v-model="form.captcha" placeholder="验证码">
                <template #prefix><el-icon><Key /></el-icon></template>
              </el-input>
              <img class="login-captcha-img" :src="captchaImage" alt="验证码" @click="loadCaptcha" />
            </div>
          </el-form-item>

          <div class="login-row">
            <el-checkbox v-model="rememberMe">记住用户名</el-checkbox>
          </div>

          <el-button
            type="primary"
            size="large"
            class="login-submit"
            :loading="loading"
            @click="onSubmit"
          >
            登 录
          </el-button>
        </el-form>

        <p v-if="appStore.icp || appStore.copyright" class="login-footer">
          <span v-if="appStore.copyright">{{ appStore.copyright }}</span>
          <span v-if="appStore.icp">{{ appStore.icp }}</span>
        </p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Check, Key, Lock, Moon, Sunny, User } from '@element-plus/icons-vue'
import { captcha as captchaApi } from '@/api/auth'
import { useAppStore } from '@/stores/app'
import { useSettingStore } from '@/stores/setting'
import { useUserStore } from '@/stores/user'

const FEATURES = ['注解驱动鉴权，按钮级权限', '组织 + 岗位 + 数据范围', '多标签页与主题可定制']
const REMEMBER_KEY = 'cccms_remember_username'

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const setting = useSettingStore()
const appStore = useAppStore()

const brandInitial = computed(() => (appStore.systemName || 'C').charAt(0).toUpperCase())

const loading = ref(false)
const rememberMe = ref(false)
const captchaImage = ref('')
const captchaId = ref('')
const formRef = ref<FormInstance>()

const form = reactive({ username: 'admin', password: '', captcha: '' })

const rules: FormRules = {
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
}

/** 验证码：后端未实现时 data 为 null，此处静默隐藏该输入项 */
async function loadCaptcha(): Promise<void> {
  try {
    const res = await captchaApi()
    if (res?.image) {
      captchaImage.value = res.image
      captchaId.value = res.captcha_id
    }
  } catch {
    captchaImage.value = ''
  }
}

async function onSubmit(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  loading.value = true
  try {
    await userStore.login({
      username: form.username,
      password: form.password,
      ...(captchaImage.value ? { captcha: form.captcha, captcha_id: captchaId.value } : {}),
    })

    if (rememberMe.value) {
      localStorage.setItem(REMEMBER_KEY, form.username)
    } else {
      localStorage.removeItem(REMEMBER_KEY)
    }

    ElMessage.success('登录成功')
    const redirect = (route.query.redirect as string) || '/dashboard'
    router.replace(redirect)
  } catch {
    // 错误提示由 axios 拦截器统一处理；刷新验证码避免复用
    if (captchaImage.value) {
      void loadCaptcha()
    }
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  const saved = localStorage.getItem(REMEMBER_KEY)
  if (saved) {
    form.username = saved
    rememberMe.value = true
  }
  void loadCaptcha()
})
</script>

<style scoped>
.login {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100vh;
  padding: 24px;
  overflow: hidden;
  background:
    radial-gradient(circle at 12% 18%, rgb(43 108 255 / 14%), transparent 42%),
    radial-gradient(circle at 88% 82%, rgb(43 108 255 / 10%), transparent 40%),
    var(--art-body-bg);
}

.login-theme {
  position: absolute;
  top: 18px;
  right: 22px;
}

.login-card {
  display: flex;
  width: 100%;
  max-width: 940px;
  overflow: hidden;
  background: var(--art-card-bg);
  border: 1px solid var(--art-card-border);
  border-radius: calc(var(--art-radius) + 4px);
  box-shadow: var(--art-shadow-2);
}

/* ---- 品牌区 ---- */
.login-brand {
  position: relative;
  display: flex;
  flex: 1 1 52%;
  flex-direction: column;
  /* 品牌头贴顶：原来整组垂直居中，logo 会被顶到区块中间 */
  justify-content: flex-start;
  padding: 44px 44px 48px;
  overflow: hidden;
  color: #fff;
  background: linear-gradient(140deg, var(--art-primary) 0%, #1b3fa8 100%);
}

.login-brand-blob {
  position: absolute;
  background: rgb(255 255 255 / 12%);
  border-radius: 50%;
}

.login-brand-blob.is-a {
  top: -70px;
  right: -60px;
  width: 220px;
  height: 220px;
}

.login-brand-blob.is-b {
  bottom: -90px;
  left: -50px;
  width: 280px;
  height: 280px;
  background: rgb(255 255 255 / 8%);
}

.login-brand-head,
.login-brand-title,
.login-brand-desc,
.login-brand-list {
  position: relative;
  z-index: 1;
}

/* 盾牌 + 系统名：在蓝色品牌区内水平居中、贴顶显示 */
.login-brand-head {
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: center;
  margin-bottom: 60px;
}

/* 兜底字母标：尺寸与 logo 图保持一致，换图前后占位不跳 */
.login-brand-logo {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  font-size: 12px;
  font-weight: 700;
  background: rgb(255 255 255 / 20%);
  border-radius: 6px;
}

.login-brand-img {
  flex-shrink: 0;
  width: 20px;
  height: 20px;
  object-fit: contain;
  border-radius: 6px;
}

.login-maintenance {
  margin-bottom: 18px;
}

.login-footer {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
  margin: 10px 0 0;
  font-size: 12px;
  color: var(--art-muted);
}

.login-brand-name {
  font-size: 16px;
  font-weight: 700;
  /* 字距会在末尾多出一格，用负 margin 抵掉，居中的才是视觉中心 */
  margin-right: -1px;
  letter-spacing: 1px;
}

.login-brand-title {
  margin: 0 0 12px;
  font-size: 26px;
  font-weight: 700;
  line-height: 1.4;
}

.login-brand-desc {
  margin: 0 0 30px;
  font-size: 13px;
  line-height: 1.7;
  opacity: 0.82;
}

.login-brand-list {
  padding: 0;
  margin: 0;
  list-style: none;
}

.login-brand-list li {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 12px;
  font-size: 13px;
  opacity: 0.92;
}

/* ---- 表单区 ---- */
.login-form {
  display: flex;
  flex: 1 1 48%;
  flex-direction: column;
  justify-content: center;
  padding: 48px 44px;
}

.login-form-head {
  margin-bottom: 26px;
}

.login-form-title {
  margin: 0;
  font-size: 22px;
  font-weight: 700;
  color: var(--art-main);
}

.login-form-desc {
  margin: 6px 0 0;
  font-size: 13px;
  color: var(--art-muted);
}

.login-captcha {
  display: flex;
  gap: 10px;
  width: 100%;
}

.login-captcha-img {
  flex-shrink: 0;
  width: 120px;
  height: 40px;
  cursor: pointer;
  object-fit: contain;
  background: #f2f5fa;
  border: 1px solid var(--art-card-border);
  border-radius: var(--el-border-radius-base);
}

.login-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 18px;
}

.login-submit {
  width: 100%;
  letter-spacing: 2px;
}

.login-tip {
  margin: 20px 0 0;
  font-size: 12px;
  text-align: center;
  color: var(--art-muted);
}

/* ---- 窄屏：隐藏品牌区 ---- */
@media (max-width: 860px) {
  .login-card {
    max-width: 420px;
  }

  .login-brand {
    display: none;
  }

  .login-form {
    padding: 40px 28px;
  }
}
</style>
