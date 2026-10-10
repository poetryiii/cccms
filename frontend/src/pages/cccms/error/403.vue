<template>
  <ErrorPage :code="403" title-key="error.forbiddenTitle" desc-key="error.forbiddenDesc" />
</template>

<script setup lang="ts">
import ErrorPage from './ErrorPage.vue'
</script>
