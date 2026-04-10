# ShortURL - 短链接生成服务

一个轻量级的短链接转换网站，基于 PHP + MySQL，单文件部署，无需框架依赖。

## 功能特性

- **短链接生成** — 粘贴长链接，一键生成短链
- **301 重定向** — 短链访问时自动跳转到目标地址，并统计点击次数
- **重复检测** — 相同 URL 不会生成重复短码
- **管理面板** — 登录后台查看所有链接、点击统计、支持删除和清空
- **响应式设计** — 玻璃拟态风格 UI，适配移动端
- **Bing 每日壁纸** — 首页背景自动使用 Bing 每日图片

## 技术栈

- PHP 7.2+
- MySQL 5.6+ / MariaDB
- Apache (mod_rewrite)

## 部署方式

### 1. 上传文件

将以下文件上传到网站根目录：

- `index.php`
- `.htaccess`

### 2. 修改配置

编辑 `index.php` 顶部的配置区域，填入你的数据库信息：

```php
define('DB_HOST',    'your-db-host');
define('DB_USER',    'your-db-user');
define('DB_PASS',    'your-db-password');
define('DB_NAME',    'your-db-name');
define('DB_PORT',    3306);
define('ADMIN_PATH', 'panel');      // 管理面板路径
define('ADMIN_USER', 'admin');      // 管理员账号
define('ADMIN_PASS', 'admin123');   // 管理员密码（请务必修改！）
```

### 3. 创建数据库

确保数据库已创建，程序会自动创建 `links` 表。

### 4. 访问

- 首页：`https://your-domain.com`
- 管理面板：`https://your-domain.com/panel`

## API 接口

### 缩短链接

```
POST /?action=shorten
Content-Type: application/json

{
  "url": "https://example.com/very-long-url",
  "title": "可选标题"
}
```

**响应：**

```json
{
  "success": true,
  "short_url": "https://your-domain.com/abc123",
  "code": "abc123",
  "clicks": 0,
  "is_new": true
}
```

## 目录结构

```
.
├── index.php      # 主程序（路由、API、页面渲染）
├── .htaccess      # Apache 伪静态规则
└── README.md      # 项目说明
```

## 注意事项

- 需要 Apache 服务器并启用 `mod_rewrite`
- 数据库配置直接写在 `index.php` 中，部署前请修改管理员密码
- 短码使用 `abcdefghjkmnpqrstuvwxyz23456789` 字符集，避免易混淆字符

## License

MIT
