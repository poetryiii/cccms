<?php

declare(strict_types=1);

/** 附件管理模块文案。 */
return [
    'not_found'            => '附件不存在',
    'file_required'        => '请选择文件',
    'uploaded'             => '上传成功',
    'no_permission'        => '无权删除该附件',
    'move_select_required' => '请选择要移动的附件',
    'category_not_found'   => '目标分类不存在',
    'file_name_required'   => '缺少文件名',
    'file_empty'           => '文件为空',
    'chunk_size_exceeded'  => '文件超出大小限制（最大 {max}MB）',
    'chunk_count_invalid'  => '分片数量非法（应为 1 ~ {max} 片）',
    'chunk_dir_failed'     => '创建分片临时目录失败，请检查 runtime 目录权限',
    'chunk_index_invalid'  => '分片序号非法（应为 0 ~ {max}）',
    'chunk_upload_failed'  => '分片上传失败，请重试该片',
    'chunk_too_large'      => '单个分片超出上限（最大 {max}MB）',
    'chunk_missing'        => '还有 {count} 个分片未上传（如第 {first} 片），请续传后再提交',
    'chunk_size_mismatch'  => '合并后大小（{actual} 字节）与声明不符（{expected} 字节），请重新上传',
    'chunk_hash_mismatch'  => '文件内容与声明哈希不符：可能有分片损坏，请重新上传',
    'chunk_merge_failed'   => '合并分片失败，请重试',
    'chunk_redis_required' => '分片上传需要 Redis 可用（用于保存上传会话）',
    'upload_session_invalid' => '上传会话非法',
    'upload_session_expired' => '上传会话不存在或已过期，请重新上传',
];
