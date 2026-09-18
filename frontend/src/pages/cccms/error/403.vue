<template>
  <div class="forbidden">
    <div class="forbidden-inner">
      <div class="forbidden-code">
        <span class="forbidden-digit">4</span>
        <span class="forbidden-zero" />
        <span class="forbidden-digit">3</span>
      </div>
      <h1 class="forbidden-title">{{ t('error.forbiddenTitle') }}</h1>
      <p class="forbidden-desc">{{ t('error.forbiddenDesc') }}</p>
      <div class="forbidden-actions">
        <el-button type="primary" @click="goHome">{{ t('error.backHome') }}</el-button>
        <el-button @click="goBack">{{ t('error.backPrev') }}</el-button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { HOME_PATH } from '@/stores/worktab'

const { t } = useI18n({ useScope: 'global' })

const router = useRouter()

function goHome(): void {
  router.push(HOME_PATH)
}

function goBack(): void {
  router.back()
}
</script>

<style scoped>
.forbidden {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  min-height: 100vh;
  background: var(--art-body-bg);
}

.forbidden-inner {
  padding: 40px;
  text-align: center;
}

.forbidden-code {
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: center;
  font-size: 110px;
  font-weight: 800;
  line-height: 1;
  color: var(--art-primary);
  letter-spacing: 4px;
}

.forbidden-zero {
  display: block;
  width: 92px;
  height: 92px;
  border: 10px solid var(--art-primary);
  border-radius: 50%;
  opacity: 0.9;
}

.forbidden-title {
  margin: 28px 0 8px;
  font-size: 22px;
  font-weight: 600;
  color: var(--art-main);
}

.forbidden-desc {
  margin: 0 0 28px;
  font-size: 14px;
  color: var(--art-sub);
}

.forbidden-actions {
  display: flex;
  gap: 12px;
  justify-content: center;
}
</style>
