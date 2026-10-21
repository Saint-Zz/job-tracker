# 秋招投递助手

一个可自托管的秋招投递管理网页，支持账号隔离、投递记录导入、简历库、岗位默认简历，以及历史简历查看和下载。

## 运行环境

- Windows + WSL2（默认 `Ubuntu-22.04`）
- Podman
- Nginx 1.20.1
- PHP 7.0 FPM
- MySQL 5.7.36

## 首次配置

复制环境变量示例：

```powershell
Copy-Item .env.example .env
```

打开 `.env`，至少替换以下两个值：

```dotenv
MYSQL_PASSWORD=请设置新的应用数据库密码
MYSQL_ROOT_PASSWORD=请设置新的数据库管理员密码
DB_PASSWORD=与MYSQL_PASSWORD保持一致
```

`.env` 已被 `.gitignore` 排除，不要将真实密码提交到 GitHub。`.env.example` 只用于说明所需配置，不应包含任何真实凭据。

> MySQL 的初始化变量只在数据卷首次创建时生效。如果已经使用旧密码创建了 `tpshop-mysql` 数据卷，请先在数据库中修改账号密码，再同步修改 `.env`；不要为了改密码直接删除仍包含重要数据的卷。

## 启动

在 PowerShell 中进入项目目录后运行：

```powershell
.\scripts\start.ps1
```

脚本会读取 `.env`，重新创建服务容器，并保留名为 `tpshop-mysql` 的数据库卷。默认访问地址为 <http://localhost:8080>。

首次打开页面时创建管理员账号。之后可由管理员创建其他账号。

## 验证、停止和重置管理员密码

```powershell
.\scripts\verify.ps1
.\scripts\stop.ps1
.\scripts\reset-admin.ps1
```

## 数据说明

投递记录、账号和上传的简历保存在 MySQL 数据卷中，不会随源代码提交到 GitHub。生产部署前应配置自动备份、HTTPS、访问日志保护和文件安全扫描。

## 公开仓库安全检查

提交前运行：

```powershell
git status --short
git check-ignore .env
git grep -n -I -E "password|secret|token|api[_-]?key"
```

确认 `.env` 显示为被忽略，并人工检查搜索结果中没有真实凭据。公开仓库只应包含 `.env.example`。

