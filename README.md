# laravel-oss

## 安装

```shell
$ composer require mradang/laravel-oss -vvv
```

### 可选项

1. 发布配置文件

```shell
$ php artisan vendor:publish --provider="mradang\\LaravelOss\\LaravelOssServiceProvider"
```

## 配置

1. 添加 .env 环境变量，使用默认值时可省略
```
OSS_ACCESS_KEY_ID=
OSS_ACCESS_KEY_SECRET=
# 接入点应使用 CName 绑定自有域名
OSS_ENDPOINT=
OSS_BUCKET=
# 限定上传目录
OSS_DIR=
# 上传文件最大限制，默认 2MB
OSS_MAXSIZE=2MB
```

2. 添加对象跟踪任务

修改 laravel 工程 app\Console\Kernel.php 文件，在 schedule 函数中增加
```php
// 跟踪 OSS 对象上传
$schedule
->call(function () {
    try {
        \mradang\LaravelOss\Services\OssService::scheduleTracker();
    } catch (\Exception $e) {
        logger()->warning('OSS 对象跟踪失败：'.$e->getMessage());
    }
})
->cron('* * * * *')
->name('OssService::scheduleTracker')
->withoutOverlapping();
```

## 添加的内容

### 添加的数据表迁移
- oss_objects
- oss_tracks

### 添加的路由
- post /api/laravel_oss/callback

## 使用

### 模型 Trait

```php
use mradang\LaravelOss\Traits\OssObjectTrait;
```

### 模型 boot
```php
protected static function boot()
{
    parent::boot();
    // 模型删除时自动清理 OSS 对象
    static::deleting(function($model) {
        $model->ossobjectClear();
    });
}
```

### 模型实例方法
> - array ossobjectUploadParams($extension, array $data = []) 为模型生成前端直传参数
> - morphMany ossobjects 对象关联（一对多）
> - mradang\LaravelOss\Models\OssObject ossobjectFind($name) 查找对象
> - void ossobjectDelete($name) 删除对象（异步）
> - void ossobjectClear() 清空模型的全部对象
> - void ossobjectSaveSort(array $data) 保存对象排序
> - mradang\LaravelOss\Models\OssObject ossobjectCreateByFile($filename, array $data = []) 上传本地文件
> - mradang\LaravelOss\Models\OssObject ossobjectCreateByUrl($url, array $data = []) 上传 Url 文件
> - void ossobjectAsyncCreateByUrl($url, array $data = []) 异步上传 Url 文件

### 模型静态方法
```php
// $timeout 生成 URL 的有效期，最长 3600 秒（1 小时）
// $options OSS 数据处理选项
// -- 图片处理 $options['x-oss-process'] = "image/resize,h_${height},w_${width}";
```
> - string ossobjectGenerateUrl($object, $timeout = 300, $options = null) 为对象生成访问链接
