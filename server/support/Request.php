<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace support;

use plugin\cccms\support\UserContext;

/**
 * Class Request
 * @package support
 *
 * @property UserContext|null $user   当前登录用户上下文（CheckLogin 注入）
 * @property string           $encode 本次响应编码（ResponseEncode 注入）
 */
class Request extends \Webman\Http\Request
{
    /** @var UserContext|null */
    public ?UserContext $user = null;

    /** @var string */
    public string $encode = 'json';
}
