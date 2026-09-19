<?php

declare(strict_types=1);

/** 角色模块相关文案。 */
return [
    'not_found'              => '角色不存在',
    'code_required'          => '角色标识不能为空',
    'code_exists'            => '角色标识已存在',
    'code_in_trash'          => '角色标识 {code} 在回收站中，请先恢复或彻底删除',
    'code_generate_failed'   => '无法自动生成角色标识，请手动指定',
    'parent_not_found'       => '父角色不存在',
    'inherit_depth_exceeded' => '角色继承深度不能超过 5 层',
    'cannot_delete_super'    => '不能删除超管角色',
    'has_children'           => '存在子角色，无法删除',
    'copy_suffix'            => ' 副本',
];