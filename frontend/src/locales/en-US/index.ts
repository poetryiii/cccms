/**
 * English language pack aggregate.
 *
 * Split by module: each business module owns one file, so nobody has to edit a single huge file.
 * All locale directories (`zh-CN` / `en-US`) must keep an **identical file and key set**;
 * `npm run i18n:check` verifies this in CI.
 *
 * Namespace layout mirrors `zh-CN/index.ts`.
 * Backend-provided menu titles and config item names are not part of the frontend packs
 * (the backend translates them for the current request locale).
 */
import common from './common'
import layout from './layout'
import login from './login'
import error from './error'
import table from './table'
import setting from './setting'
import route from './route'

// business page modules
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
