import axios, { type AxiosInstance } from 'axios'
import { ElMessage } from 'element-plus'
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

    const body = response.data as ApiEnvelope
    if (body && typeof body === 'object' && 'code' in body) {
      if (body.code === 0) {
        return body.data as never
      }
      ElMessage.error(body.message || '请求失败')
      return Promise.reject(new Error(body.message || '请求失败'))
    }
    return body as never
  },
  (error) => {
    endProgress(error?.config as TrackedConfig | undefined)

    const status = error?.response?.status
    const message = error?.response?.data?.message

    if (status === 401) {
      clearToken()
      ElMessage.error(message || '登录已失效，请重新登录')
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
      ElMessage.error(message || `请求错误 (${status})`)
    } else {
      ElMessage.error('网络异常，请检查服务是否启动')
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

export default instance
