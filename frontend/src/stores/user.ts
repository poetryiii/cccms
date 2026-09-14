import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { clearToken, getToken, setToken } from '@/utils/auth'
import {
  login as loginApi,
  logout as logoutApi,
  me as meApi,
  type LoginParams,
  type Profile,
} from '@/api/auth'

export const useUserStore = defineStore('user', () => {
  const token = ref<string>(getToken())
  const profile = ref<Profile | null>(null)

  const permissions = computed<string[]>(() => profile.value?.permissions ?? [])
  const superAdmin = computed<boolean>(() => !!profile.value?.super_admin)
  const nickname = computed<string>(() => profile.value?.nickname || profile.value?.username || '')
  const cryptoKey = computed<string>(() => (profile.value as Profile & { crypto_key?: string })?.crypto_key || '')

  function hasAuth(node: string | string[]): boolean {
    if (superAdmin.value) {
      return true
    }
    const nodes = Array.isArray(node) ? node : [node]
    return nodes.some((n) => permissions.value.includes(n))
  }

  async function login(params: LoginParams): Promise<void> {
    const res = await loginApi(params)
    setToken(res.token.token)
    token.value = res.token.token
    if (res.user) {
      profile.value = res.user as unknown as Profile
    }
  }

  async function fetchProfile(): Promise<Profile> {
    const p = await meApi()
    profile.value = p
    return p
  }

  async function logout(): Promise<void> {
    try {
      await logoutApi()
    } catch {
      // 忽略登出接口异常
    }
    clearToken()
    token.value = ''
    profile.value = null
  }

  return {
    token,
    profile,
    permissions,
    superAdmin,
    nickname,
    cryptoKey,
    hasAuth,
    login,
    fetchProfile,
    logout,
  }
})
