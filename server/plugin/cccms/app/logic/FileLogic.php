<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Category;
use plugin\cccms\app\model\File;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\FileStorage;
use Webman\Http\UploadFile;

/**
 * 附件管理逻辑。
 *
 * 查询统一走 `File` 模型：附件表**参与**数据权限（见 `app/model/File.php`），
 *   - 「仅本人」= 我上传的附件（create_by）；
 *   - 「本部门及以下」= 可见部门成员上传的附件。
 *
 * 因此越权附件在列表、移动、删除上自然不可见 / 不可动，这里不需要额外判定。
 */
final class FileLogic
{
    public static function paginate(array $params): array
    {
        // trashed=1 → 回收站视图（只看已删除），与正常列表共用同一套列
        $query = !empty($params['trashed']) ? File::onlyTrashed() : File::newScopedQuery();
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

        // 分类是公共基础数据（模型声明不参与数据权限），这里用模型统一查询入口
        $categories = Category::withoutGlobalScope()->column('name', 'id');

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
            $exists = Category::withoutGlobalScope()
                ->where('id', $categoryId)
                ->where('module', 'file')
                ->find();
            if (!$exists) {
                throw new ApiException('目标分类不存在', 404);
            }
        } else {
            $categoryId = 0;
        }

        // 带作用域：范围外的附件不会被移动（更新不到任何行）
        return File::newScopedQuery()
            ->whereIn('id', $ids)
            ->update(['category_id' => $categoryId]);
    }

    public static function upload(UploadFile $file, int $userId, int $categoryId = 0): array
    {
        $info = FileStorage::upload($file);

        // 去重：相同内容已存在则复用记录，丢弃新文件（回收站里的不算，避免「复活」已删附件）。
        // 刻意走**带作用域**的查询：范围外的同 hash 文件不该被「复用」——
        // 那等于把别人上传的附件路径告诉当前用户；查不到就正常新建一条记录。
        $exist = File::newScopedQuery()->where('hash', $info['hash'])->find();
        if ($exist) {
            FileStorage::delete($info['path']);

            return self::decorate($exist->toArray());
        }

        $data = [
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
        ];

        // 新增的归属由 create_by 指定，插入语句不需要数据权限条件
        $id = (int)File::withoutGlobalScope()->insertGetId($data);

        // 直接用刚写入的数据回显，不再按 id 回查：「本部门」档且未分配部门时作用域是
        // fail-closed 的，回查会查不到自己刚上传的记录
        return self::decorate($data + ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        $file = File::newScopedQuery()->where('id', $id)->find();
        if (!$file) {
            // 区分「不存在」与「越权」
            if (File::withoutGlobalScope()->where('id', $id)->find()) {
                throw new ApiException('无权删除该附件', 403);
            }

            throw new ApiException('附件不存在', 404);
        }

        // 软删除：只进回收站，**刻意不删物理文件**——否则回收站恢复出来的是坏链接。
        // 物理文件在「彻底删除」时才清理（见 RecycleLogic::forceDelete）。
        File::destroy($id);
    }

    /** 补全 url / is_image 这类「按当前配置实时生成」的字段 */
    private static function decorate(array $row): array
    {
        $row['url']      = FileStorage::url((string)$row['path']);
        $row['is_image'] = FileStorage::isImage((string)$row['ext']);
        return $row;
    }
}
