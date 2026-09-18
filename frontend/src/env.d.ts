/// <reference types="vite/client" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  // 不写泛型实参：默认即为最宽松的组件类型，同时避免 no-empty-object-type 报错
  const component: DefineComponent
  export default component
}

interface ImportMetaEnv {
  readonly VITE_API_BASE: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
