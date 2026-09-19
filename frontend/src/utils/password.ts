/**
 * 密码强度校验（前端侧，仅用于即时反馈）。
 *
 * 为什么在前端复制一份规则：用户体验——不填表单时就能看到「哪一条不满足」，
 * 而不是提交后弹一个后端的错误。与 `utils/cron.ts` 同一取舍。
 *
 * **后端才是权威**：`plugin/cccms/support/PasswordPolicy.php` 还包含内置弱口令黑名单
 * 等无法在前端穷举的规则（列表大、易漂移，不适合复制），超长 / 弱口令等拒绝
 * 以后端返回的提示为准。这里只镜像**形状类**规则（长度、字符类别、与身份相同），
 * 阈值与后端默认值保持一致（`security.password_min_length` / `_max_length` / `_strength`）。
 */

const MIN_LENGTH = 6
const MAX_LENGTH = 64
const MIN_CLASSES = 2

/** 已包含的字符类别数（大写 / 小写 / 数字 / 符号） */
function classesOf(value: string): number {
  let count = 0
  if (/[a-z]/.test(value)) count++
  if (/[A-Z]/.test(value)) count++
  if (/\d/.test(value)) count++
  if (/[^A-Za-z0-9]/.test(value)) count++
  return count
}

/** 身份信息（用户名 / 昵称），用于拒绝可猜口令 */
export interface PasswordIdentity {
  username?: string
  nickname?: string
}

/**
 * 校验密码，通过返回空串，否则返回提示语。
 *
 * @param value    待校验的密码
 * @param identity 身份信息
 */
export function checkPassword(value: string, identity: PasswordIdentity = {}): string {
  if (value.length > MAX_LENGTH) {
    return `密码不能超过 ${MAX_LENGTH} 位`
  }
  if (value.length < MIN_LENGTH) {
    return `密码至少 ${MIN_LENGTH} 位`
  }

  const lower = value.toLowerCase()
  for (const [key, label] of [
    ['username', '用户名'],
    ['nickname', '昵称'],
  ] as const) {
    const item = (identity[key] || '').trim().toLowerCase()
    if (!item) continue
    if (lower === item) {
      return `密码不能与${label}相同`
    }
    // 短于 4 个字符的身份信息不做包含判断，否则误报率过高（与后端一致）
    if (item.length >= 4 && lower.includes(item)) {
      return `密码不能包含${label}`
    }
  }

  if (classesOf(value) < MIN_CLASSES) {
    return `密码需包含大写字母、小写字母、数字、符号中的 ${MIN_CLASSES} 类`
  }

  return ''
}

/**
 * Element Plus 表单校验器：与 `checkPassword` 同规则，直接挂到 FormRules 上。
 *
 * 身份信息用**取值函数**而不是对象：调用方在 `setup` 阶段就构造 FormRules，
 * 此时表单可能还没填（`form.username` 为空）、store 里的资料也还没拉回来。
 * 传函数才能在每次校验时读到最新值。
 */
export function passwordValidator(getIdentity: () => PasswordIdentity = () => ({})) {
  return (_rule: unknown, value: string, callback: (error?: Error) => void): void => {
    const message = checkPassword(value || '', getIdentity())
    callback(message ? new Error(message) : undefined)
  }
}
