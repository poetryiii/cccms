import axios, { type AxiosInstance, type AxiosResponse } from 'axios'
import { ElMessage } from 'element-plus'
import { currentLocale, t } from '@/locales'
import { clearToken, getToken } from '@/utils/auth'
import { progressDone, progressStart } from '@/utils/progress'

export interface ApiEnvelope<T = unknown> {
  code: number
  message: string
  data: T
}

/** 请求级进度条令牌：挂在 config 上，保证 start / done 一一对应 */
interface TrackedConfig {
  __progressToken?: number
}

let progressSeq = 0

const instance: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_BASE || '/api',
  timeout: 15000,
})

/** 请求结束（成功/失败/超时/取消）统一收尾，避免进度条卡住 */
function endProgress(config?: TrackedConfig): void {
  const token = config?.__progressToken
  if (token !== undefined) {
    progressDone(token)
  }
}

instance.interceptors.request.use((config) => {
  const token = getToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  // 带上当前语言，后端据此返回对应语言的错误文案（优先 ?lang=，其次该请求头）
  config.headers['Accept-Language'] = currentLocale.value

  // 每个请求都推进度条：并发请求按令牌聚合，全部结束才收尾
  const tracked = config as typeof config & TrackedConfig
  if (tracked.__progressToken === undefined) {
    tracked.__progressToken = ++progressSeq
    progressStart(tracked.__progressToken)
  }
  return config
})

/** 401 风暴时避免反复写 hash 触发重复跳转 */
let redirecting = false

instance.interceptors.response.use(
  (response) => {
    endProgress(response.config as TrackedConfig)

    // 文件下载（CSV 导出）：保留完整响应，调用方需要读 Content-Disposition 里的文件名
    if (response.config.responseType === 'blob') {
      return response as never
    }

    const body = response.data as ApiEnvelope
    if (body && typeof body === 'object' && 'code' in body) {
      if (body.code === 0) {
        return body.data as never
      }
      const failed = body.message || t('common.requestFailed')
      ElMessage.error(failed)
      return Promise.reject(new Error(failed))
    }
    return body as never
  },
  (error) => {
    endProgress(error?.config as TrackedConfig | undefined)

    const status = error?.response?.status
    const message = error?.response?.data?.message

    if (status === 401) {
      clearToken()
      ElMessage.error(message || t('common.loginExpired'))
      if (!redirecting && !window.location.hash.startsWith('#/login')) {
        redirecting = true
        // 动态路由与标签页都在内存里：不清掉的话，换账号登录会残留上一个账号的菜单。
        // 用动态 import 避免 request ←→ router ←→ store 的循环依赖。
        void import('@/router').then(({ resetAfterLogout }) => {
          resetAfterLogout()
          window.location.hash = '#/login'
          window.setTimeout(() => {
            redirecting = false
          }, 800)
        })
      }
    } else if (status) {
      ElMessage.error(message || t('common.requestError', { status }))
    } else {
      ElMessage.error(t('common.networkError'))
    }
    return Promise.reject(error)
  },
)

export const http = {
  get<T = unknown>(url: string, params?: Record<string, unknown>): Promise<T> {
    return instance.get(url, { params }) as unknown as Promise<T>
  },
  post<T = unknown>(url: string, data?: unknown): Promise<T> {
    return instance.post(url, data) as unknown as Promise<T>
  },
}

/** 从 Content-Disposition 解析文件名（优先 RFC 5987 的 filename*） */
function filenameFromDisposition(disposition: string): string {
  const star = /filename\*=(?:UTF-8'')?([^;]+)/i.exec(disposition)
  if (star?.[1]) {
    try {
      return decodeURIComponent(star[1].trim().replace(/^"|"$/g, ''))
    } catch {
      // 解码失败则回落到普通 filename
    }
  }

  const plain = /filename="?([^";]+)"?/i.exec(disposition)
  return plain?.[1] ? plain[1].trim() : ''
}

/**
 * 下载后端返回的文件（CSV 导出）。
 *
 * 复用同一个 axios 实例：自动带鉴权头、401 处理与进度条；
 * 文件名取 `Content-Disposition`，拿不到时用 `fallbackName`。
 */
export async function downloadFile(
  url: string,
  params?: Record<string, unknown>,
  fallbackName = 'export.csv',
): Promise<void> {
  const response = (await instance.get(url, { params, responseType: 'blob' })) as unknown as AxiosResponse<Blob>

  const name = filenameFromDisposition(String(response.headers['content-disposition'] ?? '')) || fallbackName
  const objectUrl = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = name
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(objectUrl)
}

export default instance
