<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\SoftDelete;
use think\facade\Db;

/** 数据字典逻辑。 */
final class DictLogic
{
    // ---- 类型 ----
    public static function typePaginate(array $params): array
    {
        $query = SoftDelete::listQuery('dict_type', $params);
        if (!empty($params['name'])) {
            $query->where('name', 'like', '%' . $params['name'] . '%');
        }
        // 分类筛选：-1 未分类，>0 该分类及其下级（见 CategoryLogic::scopeIds）
        $categoryIds = CategoryLogic::scopeIds($params['category_id'] ?? null);
        if ($categoryIds !== null) {
            $query->whereIn('category_id', $categoryIds);
        }

        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 15));
        $total = $query->count();
        $list  = $query->page($page, $limit)->order('id', 'desc')->select()->toArray();

        $categories = SoftDelete::apply(Db::name('category'))->column('name', 'id');
        foreach ($list as &$row) {
            $row['category_name'] = $categories[(int)$row['category_id']] ?? '';
        }
        unset($row);

        return ['total' => $total, 'list' => $list];
    }

    public static function typeCreate(array $data): int
    {
        // 唯一索引不做软删特例：回收站里的字典类型仍占用标识
        $type = (string)($data['type'] ?? '');
        if ($type !== '') {
            $exist = Db::name('dict_type')->where('type', $type)->find();
            if ($exist) {
                throw new ApiException(
                    SoftDelete::isTrashed($exist)
                        ? "字典标识 {$type} 在回收站中，请先恢复或彻底删除"
                        : '字典标识已存在',
                    422
                );
            }
        }

        return (int)Db::name('dict_type')->insertGetId($data);
    }

    public static function typeUpdate(int $id, array $data): void
    {
        Db::name('dict_type')->where('id', $id)->update($data);
    }

    public static function typeDelete(int $id): void
    {
        // 软删除：类型与其下数据一起进回收站
        $dataIds = SoftDelete::apply(Db::name('dict_data'))->where('type_id', $id)->column('id');
        if ($dataIds !== []) {
            SoftDelete::remove(Db::name('dict_data'), $dataIds);
        }
        SoftDelete::remove(Db::name('dict_type'), $id);
    }

    // ---- 数据 ----
    public static function dataList(int $typeId, bool $trashed = false): array
    {
        return SoftDelete::scope(Db::name('dict_data'), $trashed)
            ->where('type_id', $typeId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    public static function dataCreate(array $data): int
    {
        return (int)Db::name('dict_data')->insertGetId($data);
    }

    public static function dataUpdate(int $id, array $data): void
    {
        Db::name('dict_data')->where('id', $id)->update($data);
    }

    public static function dataDelete(int $id): void
    {
        SoftDelete::remove(Db::name('dict_data'), $id);
    }
}
