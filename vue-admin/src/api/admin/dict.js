import api from '@/api';

// 字典类型
export function dictTypeList(data) { return api.get('admin/dict/index', data); }
export function dictTypeCreate(data) { return api.post('admin/dict/create', data); }
export function dictTypeUpdate(data) { return api.put('admin/dict/update', data); }
export function dictTypeDelete(data) { return api.delete('admin/dict/delete', data); }
// 字典数据
export function dictDataList(data) { return api.get('admin/dict/data', data); }
export function dictDataCreate(data) { return api.post('admin/dict/createData', data); }
export function dictDataUpdate(data) { return api.put('admin/dict/updateData', data); }
export function dictDataDelete(data) { return api.delete('admin/dict/deleteData', data); }
