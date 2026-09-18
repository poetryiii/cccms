<template>
  <!-- Element Plus 语言包跟随当前语言：切换后日期选择器 / 分页等内置文案立即生效 -->
  <el-config-provider :locale="elementLocale">
    <router-view />
  </el-config-provider>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import en from 'element-plus/es/locale/lang/en'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import { currentLocale } from '@/locales'

const elementLocale = computed(() => (currentLocale.value === 'en-US' ? en : zhCn))
</script>
