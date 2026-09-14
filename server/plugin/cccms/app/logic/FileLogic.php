<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\FileStorage;
use plugin\cccms\support\SoftDelete;
use think\facade\Db;
use Webman\Http\UploadFile;


/** 附件管理逻辑。 */
final class FileLogic
{
    public static function paginate(array $params): array
    {
        $query = SoftDelete::listQuery('file', $params);
        if (!empty($params['original_name'])) {
            $query->where('original_name', 'like', '%' . $params['original_name'] . '%');
        }
        if (!empty($params['ext'])) {
            $query->where('ext', strtolower((string)$params['ext']));
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

        // URL 按当前 url_prefix 实时生成，切换 CDN/OSS 后历史数据无需迁移；
        // is_image 由 upload.image_ext 决定，前端据此决定是否显示缩略图
        foreach ($list as &$row) {
            $row['url']           = FileStorage::url((string)$row['path']);
            $row['is_image']      = FileStorage::isImage((string)$row['ext']);
            $row['category_name'] = $categories[(int)$row['category_id']] ?? '';
        }
        unset($row);

        return ['total' => $total, 'list' => $list];
    }

    /** 批量移动到分类（category_id <= 0 表示移出分类，落到「未分类」）。 */
    public static function move(array $ids, int $categoryId): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            throw new ApiException('请选择要移动的附件', 422);
        }

        if ($categoryId > 0) {
            $exists = SoftDelete::apply(Db::name('category'))->where('id', $categoryId)->where('module', 'file')->find();
            if (!$exists) {
                throw new ApiException('目标分类不存在', 404);
            }
        } else {
            $categoryId = 0;
        }

        return SoftDelete::apply(Db::name('file'))->whereIn('id', $ids)->update(['category_id' => $categoryId]);
    }

    public static function upload(UploadFile $file, int $userId, int $categoryId = 0): array
    {
        $info = FileStorage::upload($file);

        // 去重：相同内容已存在则复用记录，丢弃新文件（回收站里的不算，避免「复活」已删附件）
        $exist = SoftDelete::apply(Db::name('file'))->where('hash', $info['hash'])->find();
        if ($exist) {
            FileStorage::delete($info['path']);
            return self::decorate($exist);
        }

        $id = (int)Db::name('file')->insertGetId([
            'name'          => $info['name'],
            'original_name' => $info['original_name'],
            'path'          => $info['path'],
            'url'           => $info['url'],
            'category_id'   => max(0, $categoryId),
            'ext'           => $info['ext'],
            'mime'          => $info['mime'],
            'size'          => $info['size'],
            'driver'        => FileStorage::driver(),
            'hash'          => $info['hash'],
            'create_by'     => $userId,
            'create_time'   => date('Y-m-d H:i:s'),
        ]);

        return self::decorate((array)Db::name('file')->where('id', $id)->find());
    }

    public static function delete(int $id): void
    {
        $file = SoftDelete::apply(Db::name('file'))->where('id', $id)->find();
        if (!$file) {
            throw new ApiException('附件不存在', 404);
        }
        // 软删除：只进回收站，**刻意不删物理文件**——否则回收站恢复出来的是坏链接。
        // 物理文件在「彻底删除」时才清理（见 RecycleLogic::forceDelete）。
        SoftDelete::remove(Db::name('file'), $id);
    }

    /** 补全 url / is_image 这类「按当前配置实时生成」的字段 */
    private static function decorate(array $row): array
    {
        $row['url']      = FileStorage::url((string)$row['path']);
        $row['is_image'] = FileStorage::isImage((string)$row['ext']);
        return $row;
    }
}
