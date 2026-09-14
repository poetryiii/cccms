<template>
  <el-drawer v-model="visible" title="主题设置" :size="320" append-to-body>
    <div class="setting-block">
      <div class="setting-label">主题模式</div>
      <el-segmented v-model="mode" :options="MODE_OPTIONS" block />
    </div>

    <div class="setting-block">
      <div class="setting-label">品牌主色</div>
      <div class="setting-colors">
        <button
          v-for="c in PRESET_COLORS"
          :key="c.value"
          class="setting-color"
          :class="{ 'is-active': isCurrent(c.value) }"
          :style="{ background: c.value }"
          :title="c.name"
          type="button"
          @click="setting.setPrimary(c.value)"
        />
        <el-color-picker
          :model-value="setting.theme.primary"
          :predefine="PRESET_COLORS.map((c) => c.value)"
          @change="onCustomColor"
        />
      </div>
    </div>

    <div class="setting-block">
      <div class="setting-label">
        圆角
        <span class="setting-value">{{ setting.theme.radius }}px</span>
      </div>
      <el-slider
        :model-value="setting.theme.radius"
        :min="0"
        :max="20"
        :step="1"
        @input="setting.setRadius"
      />
    </div>

    <div class="setting-block">
      <div class="setting-label">
        内容区宽度
        <span class="setting-value">
          {{ setting.theme.containerWidth > 0 ? `${setting.theme.containerWidth}px` : '全宽' }}
        </span>
      </div>
      <el-slider
        :model-value="setting.theme.containerWidth"
        :min="0"
        :max="2400"
        :step="120"
        @input="setting.setContainerWidth"
      />
    </div>

    <p class="setting-hint">
      {{ setting.hasOwnPref ? '当前使用你的个人主题设置' : '当前跟随后台的系统默认设置' }}
    </p>
    <el-button class="setting-reset" @click="onResetToSystem">跟随系统默认</el-button>
  </el-drawer>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { PRESET_COLORS, type ThemeMode } from '@/utils/theme'
import { useAppStore } from '@/stores/app'
import { useSettingStore } from '@/stores/setting'

const visible = defineModel<boolean>({ required: true })

const setting = useSettingStore()
const appStore = useAppStore()

/** 清除本机偏好，回到后台下发的默认主题 */
function onResetToSystem(): void {
  setting.resetToSystem(appStore.config.ui)
}

const MODE_OPTIONS = [
  { label: '亮色', value: 'light' },
  { label: '暗色', value: 'dark' },
  { label: '跟随系统', value: 'auto' },
]

const mode = computed<ThemeMode>({
  get: () => setting.theme.mode,
  set: (value) => setting.setMode(value),
})

function isCurrent(color: string): boolean {
  return setting.theme.primary.toLowerCase() === color.toLowerCase()
}

function onCustomColor(value: string | null): void {
  if (value) {
    setting.setPrimary(value)
  }
}
</script>

<style scoped>
.setting-block {
  margin-bottom: 26px;
}

.setting-label {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  font-size: 13px;
  color: var(--art-sub);
}

.setting-value {
  font-size: 12px;
  color: var(--art-muted);
}

.setting-colors {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

.setting-color {
  width: 24px;
  height: 24px;
  padding: 0;
  cursor: pointer;
  border: 2px solid transparent;
  border-radius: 6px;
  outline: none;
  transition: transform 0.15s ease;
}

.setting-color:hover {
  transform: scale(1.1);
}

.setting-color.is-active {
  border-color: var(--art-main);
}

.setting-hint {
  margin: 0 0 10px;
  font-size: 12px;
  color: var(--art-muted);
}

.setting-reset {
  width: 100%;
}
</style>
