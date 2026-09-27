/**
 * 6 个高频接口的性能基线（P2-11）。
 *
 * 用 k6 跑：
 *   k6 run loadtest/baseline.js -e BASE_URL=http://127.0.0.1:8787 -e TOKEN=xxx
 *
 * ## 鉴权
 *   优先读 `TOKEN` 环境变量（生产压测推荐：用真实账号换一个长期令牌，避免压测过程里
 *   反复登录被限流 / 验证码挡住）。不传 `TOKEN` 时回退到 `USERNAME` / `PASSWORD` 登录
 *   —— 此时必须先关掉「系统设置 → 配置管理 → 安全 → 登录验证码」(`security.login_captcha=0`)，
 *   否则登录接口要验证码，脚本登不进去。
 *
 * ## 接口与阈值
 *   每组的 `thresholds` 是**建议起步线**，不是绝对值 —— 真实基线以「在目标数据量、目标硬件上
 *   跑一次得到的 P95 / QPS」为准，记录到 docs/11-常用命令与运维.md §13.3 的基线表里，
 *   作为后续改动的回归对照。改代码前先跑一遍，改完再跑，P95 明显劣化即回归。
 */
import http from 'k6/http'
import { check, sleep } from 'k6'
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.1/index.js'

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8787'
const TOKEN = __ENV.TOKEN || ''

export const options = {
  // 6 个场景并行；各接口负载不同（列表页最重，树 / 会话较轻）
  scenarios: {
    user_list: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '10s', target: 20 }, { duration: '20s', target: 20 }, { duration: '10s', target: 0 }], exec: 'userList' },
    role_tree: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '10s', target: 10 }, { duration: '20s', target: 10 }, { duration: '10s', target: 0 }], exec: 'roleTree' },
    menu_tree: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '10s', target: 10 }, { duration: '20s', target: 10 }, { duration: '10s', target: 0 }], exec: 'menuTree' },
    log_list: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '10s', target: 15 }, { duration: '20s', target: 15 }, { duration: '10s', target: 0 }], exec: 'logList' },
    online: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '10s', target: 5 }, { duration: '20s', target: 5 }, { duration: '10s', target: 0 }], exec: 'online' },
    my_notice: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '10s', target: 5 }, { duration: '20s', target: 5 }, { duration: '10s', target: 0 }], exec: 'myNotice' },
  },
  thresholds: {
    // 起步线：真实基线跑出来后再按实测收紧（见 §13.3 基线表）
    http_req_duration: ['p(95)<500'],
    http_req_failed: ['rate<0.01'],
  },
}

/** 用 setup 登录得到的令牌发请求；返回 { token } 由 k6 分发给每个 VU */
export function setup() {
  if (TOKEN) {
    return { token: TOKEN }
  }
  if (!__ENV.USERNAME || !__ENV.PASSWORD) {
    throw new Error('未提供 TOKEN，也未提供 USERNAME/PASSWORD，无法登录')
  }
  const res = http.post(
    `${BASE_URL}/auth/login`,
    JSON.stringify({ username: __ENV.USERNAME, password: __ENV.PASSWORD }),
    { headers: { 'Content-Type': 'application/json' } },
  )
  if (res.status !== 200) {
    throw new Error('登录失败（记得关掉 security.login_captcha，或直接传 TOKEN）：' + res.status + ' ' + res.body)
  }
  return { token: JSON.parse(res.body).data.token }
}

function get(data, path) {
  const headers = data?.token ? { Authorization: `Bearer ${data.token}` } : {}
  const res = http.get(`${BASE_URL}${path}`, { headers, tags: { path } })
  check(res, { [`${path} -> 200`]: (r) => r.status === 200 })
  return res
}

// ---- 6 个高频接口 ----
export function userList(data) {
  get(data, '/user?page=1&limit=15')
  sleep(0.1)
}
export function roleTree(data) {
  get(data, '/role/tree')
  sleep(0.1)
}
export function menuTree(data) {
  get(data, '/menu/userTree')
  sleep(0.1)
}
export function logList(data) {
  get(data, '/log?page=1&limit=15')
  sleep(0.1)
}
export function online(data) {
  get(data, '/online?page=1&limit=15')
  sleep(0.1)
}
export function myNotice(data) {
  get(data, '/notice/my?page=1&limit=10')
  sleep(0.1)
}

export function handleSummary(data) {
  return {
    stdout: textSummary(data, { indent: ' ', enableColors: true }),
  }
}
