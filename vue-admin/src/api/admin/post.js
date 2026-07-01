import api from '@/api';

// 岗位列表
export function postList(data) {
  return api.get('admin/post/index', data);
}
// 添加岗位
export function postCreate(data) {
  return api.post('admin/post/create', data);
}
// 修改岗位
export function postUpdate(data) {
  return api.put('admin/post/update', data);
}
// 删除岗位
export function postDelete(data) {
  return api.delete('admin/post/delete', data);
}
// 全部岗位（下拉选择）
export function postSelect() {
  return api.get('admin/post/select');
}
