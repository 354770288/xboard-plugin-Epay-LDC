# EpayLDC - LINUX DO Credit 积分支付插件

适用于 [Xboard](https://github.com/cedar2025/Xboard) 的 LINUX DO Credit 积分支付插件。

## 特性

- ✅ 兼容 LINUX DO Credit 易支付接口
- ✅ 通过主动查询确认支付状态（不依赖异步通知回调）
- ✅ 每分钟自动检查待支付订单
- ✅ 与 Xboard 自带的 Epay 插件完全隔离

## 安装

### 方法一：直接下载
1. 下载本仓库的 ZIP 文件
2. 解压后将 `EpayLDC` 文件夹放入 Xboard 的 `plugins/` 目录
3. 在 Xboard 后台 -> 插件管理 中启用插件

### 方法二：Git Clone
```bash
cd /path/to/xboard/plugins
git clone https://github.com/354770288/xboard-plugin-epay-ldc. git EpayLDC
```

## 配置

### 1. Xboard 后台配置

在 Xboard 后台 -> 插件管理 -> EpayLDC 中填写：

| 配置项 | 说明 | 示例 |
|-------|-----|------|
| 支付网关地址 | LINUX DO Credit 网关 | `https://credit.linux.do/epay` |
| 商户ID | 创建应用后的 pid | `xxxxxxxx` |
| 通信密钥 | 创建应用后的 key | `xxxxxxxx` |
| 支付类型 | 固定填写 | `epay` |
| 显示名称 | 前端显示名称 | `LINUX DO Credit` |
| 图标 | 支付方式图标 | `💎` |

### 2.  LINUX DO Credit 后台配置

在 [credit.linux.do](https://credit.linux.do) 创建应用时：

| 配置项 | 建议填写 |
|-------|---------|
| 应用名称 | 你的应用名称 |
| 回调地址 (notify_url) | `https://你的域名/` |
| 跳转地址 (return_url) | `https://你的域名/` |

> ⚠️ 由于本插件通过主动查询确认支付状态，notify_url 不需要精确配置。

## 工作原理

```
用户下单 → 跳转 LINUX DO Credit 支付 → 支付成功 → 返回 Xboard
                                                    ↓
                              插件每分钟查询待支付订单 ← 订单显示待支付
                                                    ↓
                              查询到已支付 → 自动完成订单 → 订单显示已完成
```

## 注意事项

1. **支付确认延迟**：由于采用轮询机制，支付完成后最多等待 1 分钟订单状态才会更新
2. **定时任务**：确保 Xboard 的 Laravel 定时任务正常运行
3. **日志**：支付确认日志记录在 Laravel 日志中，便于排查问题

## 更新日志

### v1.0.0
- 初始版本
- 支持 LINUX DO Credit 积分支付
- 实现主动查询确认支付状态

## License

MIT
