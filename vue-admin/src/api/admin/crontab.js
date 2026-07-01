import api from '@/api';

export function crontabList(data) { return api.get('admin/crontab/index', data); }
export function crontabCreate(data) { return api.post('admin/crontab/create', data); }
export function crontabUpdate(data) { return api.put('admin/crontab/update', data); }
export function crontabDelete(data) { return api.delete('admin/crontab/delete', data); }
export function crontabToggle(data) { return api.put('admin/crontab/toggle', data); }
export function crontabExecute(data) { return api.post('admin/crontab/execute', data); }
export function crontabLog(data) { return api.get('admin/crontab/log', data); }
export function crontabClearLog(data) { return api.delete('admin/crontab/clearLog', data); }
