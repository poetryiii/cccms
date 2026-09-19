/**
 * 简体中文语言包聚合（默认语言 / fallback）。
 *
 * 按模块拆文件：每个业务模块一个文件，只维护自己的 key，避免多人改动同一个大文件。
 * 所有语言目录（`zh-CN` / `en-US`）必须保持**完全一致的文件与 key 集合**，
 * `npm run i18n:check` 会在 CI 里校验。
 *
 * 命名空间划分：
 *   common  通用按钮与全局提示
 *   layout  布局壳层（侧边栏 / 顶栏 / 标签栏 / 消息 / 快捷导航）
 *   login   登录页
 *   error   403 / 404 错误页
 *   table   ArtTable 工具栏与分页相关固定文案
 *   setting 主题设置抽屉
 *   route   前端静态路由标题
 *   其余    业务页面模块（user / role / dept / post / dict / config / menu / log / profile / online）
 *
 * 后端下发的菜单标题、配置项名等不属于前端语言包（由后端按当前请求语言翻译）。
 */
import common from './common'
import layout from './layout'
import login from './login'
import error from './error'
import table from './table'
import setting from './setting'
import route from './route'

// 业务页面模块
import user from './user'
import role from './role'
import dept from './dept'
import post from './post'
import dict from './dict'
import config from './config'
import menu from './menu'
import log from './log'
import profile from './profile'
import online from './online'
import dataRule from './data_rule'
import crontab from './crontab'
import file from './file'
import upgrade from './upgrade'
import generator from './generator'
import dashboard from './dashboard'
import notice from './notice'
import richEditor from './richEditor'
import tenant from './tenant'

export default {
  common,
  layout,
  login,
  error,
  table,
  setting,
  route,
  user,
  role,
  dept,
  post,
  dict,
  config,
  menu,
  log,
  profile,
  online,
  data_rule: dataRule,
  crontab,
  file,
  upgrade,
  generator,
  dashboard,
  notice,
  richEditor,
  tenant,
}
