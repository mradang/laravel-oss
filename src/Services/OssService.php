<?php

namespace mradang\LaravelOss\Services;

use Illuminate\Http\File;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use mradang\LaravelOss\Models\OssObject;
use mradang\LaravelOss\Models\OssTrack;
use OSS\Core\MimeTypes;

class OssService
{
    public static function makeUploadParams($class, $key, $extension, $group, array $data)
    {
        // 上传参数
        $host = config('oss.endpoint');
        $dir = Str::finish(config('oss.dir'), '/') . \strtolower(class_basename($class)) . '/' . $key . '/';
        $callback_vars = [
            'class' => $class,
            'key' => $key,
            'group' => $group,
            'data' => $data,
        ];
        $callback_vars_encrypt = encrypt($callback_vars);

        // 回调参数
        $callbackUrl = self::app_url() . '/api/laravel_oss/callback';
        $callback_param = [
            'callbackUrl' => $callbackUrl,
            'callbackBody' => \implode('&', [
                'bucket=${bucket}',
                'object=${object}',
                'etag=${etag}',
                'size=${size}',
                'mimeType=${mimeType}',
                'height=${imageInfo.height}',
                'width=${imageInfo.width}',
                'format=${imageInfo.format}',
                'callbackvars=${x:callbackvars}',
            ]),
            'callbackBodyType' => 'application/x-www-form-urlencoded',
        ];
        $callback_string = json_encode($callback_param);
        $base64_callback_body = base64_encode($callback_string);

        // 上传策略
        $end = time() + 30; // 30秒内有效
        $expiration = self::gmt_iso8601($end);
        $object_name = $dir . self::generateObjectName() . '.' . $extension; // 指定上传对象名

        $conditions = [
            // 限定存储桶
            ['eq', '$bucket', config('oss.bucket')],
            // 最大文件大小.用户可以自己设置
            ['content-length-range', 0, self::human2byte(config('oss.maxsize'))],
            // 强制上传对象名称
            ['eq', '$key', $object_name],
            // 限制 Content-Type 类型
            ['eq', '$Content-Type', MimeTypes::getMimetype('filename.' . $extension)],
            // 限制额外的参数
            ['eq', '$x:callbackvars', $callback_vars_encrypt],
        ];

        $policy = json_encode([
            'expiration' => $expiration,
            'conditions' => $conditions,
        ]);
        $base64_policy = base64_encode($policy);
        $signature = base64_encode(hash_hmac('sha1', $base64_policy, config('oss.key'), true));

        // 跟踪对象
        OssTrack::create([
            'osstracktable_type' => $class,
            'osstracktable_id' => $key,
            'name' => $object_name,
        ]);

        // 返回
        $response = [];
        $response['accessid'] = config('oss.id');
        $response['host'] = $host;
        $response['policy'] = $base64_policy;
        $response['signature'] = $signature;
        $response['expire'] = $end;
        $response['callback'] = $base64_callback_body;
        $response['key'] = $object_name;  // 这个参数是设置用户上传文件名
        $response['callbackvars'] = $callback_vars_encrypt;

        return $response;
    }

    public static function app_url()
    {
        return config('oss.callback') ?: request()->getSchemeAndHttpHost();
    }

    public static function gmt_iso8601($time)
    {
        $dtStr = date('c', $time);
        $mydatetime = new \DateTime($dtStr);
        $expiration = $mydatetime->format(\DateTime::ISO8601);
        $pos = strpos($expiration, '+');
        $expiration = substr($expiration, 0, $pos);

        return $expiration . 'Z';
    }

    public static function generateObjectName()
    {
        $rand = sprintf('%04d', mt_rand(1, 9999));

        return date('Ymd_His') . '_' . $rand;
    }

    // 将人类可读的文件大小值转换为数字，支持 K, M, G and T，无效的输入返回 0
    public static function human2byte($value): int
    {
        $ret = preg_replace_callback('/^\s*([\d.]+)\s*(?:([kmgt]?)b?)?\s*$/i', function ($m) {
            switch (strtolower($m[2])) {
                case 't':
                    $m[1] *= 1024;
                case 'g':
                    $m[1] *= 1024;
                case 'm':
                    $m[1] *= 1024;
                case 'k':
                    $m[1] *= 1024;
            }

            return $m[1];
        }, $value);

        return is_numeric($ret) ? (int) $ret : 0;
    }

    // OSS 上传回调
    public static function callback()
    {
        // 从 HTTP 头中获取 OSS 的签名和公钥 url
        $authorizationBase64 = Arr::get($_SERVER, 'HTTP_AUTHORIZATION', '');
        $pubKeyUrlBase64 = Arr::get($_SERVER, 'HTTP_X_OSS_PUB_KEY_URL', '');

        if (empty($authorizationBase64) || empty($pubKeyUrlBase64)) {
            return false;
        }

        // 获取回调 body
        $body = file_get_contents('php://input');

        // 节约时间，直接返回结果，后续业务由 Job 处理
        dispatch(new \mradang\LaravelOss\Jobs\OssCallback(
            $_SERVER['REQUEST_URI'],
            $pubKeyUrlBase64,
            $authorizationBase64,
            $body
        ));

        return self::parseCallbackBody($body);
    }

    // 将 OSS 回调参数解析为数据库格式
    public static function parseCallbackBody($body)
    {
        parse_str($body, $params);
        $callbackvars = decrypt($params['callbackvars']);

        return [
            'ossobjectable_type' => Arr::get($callbackvars, 'class'),
            'ossobjectable_id' => Arr::get($callbackvars, 'key'),
            'group' => Arr::get($callbackvars, 'group'),
            'name' => Arr::get($params, 'object'),
            'size' => Arr::get($params, 'size'),
            'mimeType' => Arr::get($params, 'mimeType'),
            'imageInfo' => Arr::get($params, 'width') ? Arr::only($params, ['width', 'height', 'format']) : null,
            'data' => Arr::get($callbackvars, 'data', []),
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    // 作业处理 OSS 回调
    public static function handleCallback($request_uri, $pubKeyUrlBase64, $authorizationBase64, $body)
    {
        // 解码 OSS 签名
        $authorization = base64_decode($authorizationBase64);

        // 解码公钥 URL
        $pubKeyUrl = base64_decode($pubKeyUrlBase64);
        $pubKeyHost = \parse_url($pubKeyUrl, \PHP_URL_HOST);
        if ($pubKeyHost !== 'gosspublic.alicdn.com') {
            return false;
        }

        // 获取公钥内容
        $pubKey = Http::connectTimeout(10)->get($pubKeyUrl)->body();

        if (empty($pubKey)) {
            return false;
        }

        // 拼接待签名字符串
        $authStr = '';
        $path = $request_uri;
        $pos = strpos($path, '?');
        if ($pos === false) {
            $authStr = urldecode($path) . "\n" . $body;
        } else {
            $authStr = urldecode(substr($path, 0, $pos)) . substr($path, $pos, strlen($path) - $pos) . "\n" . $body;
        }

        // 验证签名
        $ok = openssl_verify($authStr, $authorization, $pubKey, OPENSSL_ALGO_MD5);
        if ($ok !== 1) {
            return false;
        }

        // 验证主模型是否存在
        $data = self::parseCallbackBody($body);
        $model = \call_user_func([$data['ossobjectable_type'], 'find'], $data['ossobjectable_id']);
        if (empty($model)) {
            info('模型不存在，忽略 OSS Callback');

            return false;
        }

        // 入库
        $model = new OssObject($data);
        $model->sort = OssObject::where([
            'ossobjectable_type' => $model->ossobjectable_type,
            'ossobjectable_id' => $model->ossobjectable_id,
        ])->max('sort') + 1;
        $model->save();

        // 取消跟踪对象
        OssTrack::where([
            'osstracktable_type' => $model->ossobjectable_type,
            'osstracktable_id' => $model->ossobjectable_id,
            'name' => $model->name,
        ])->delete();
    }

    // $timeout URL 的有效期，最长 3600 秒（1 小时）
    public static function generateObjectUrl($class, $object, $timeout = 300, $options = null)
    {
        if (empty($object)) {
            return null;
        }

        // 检查目录，避免为其它目录内容生成链接
        if (!config('app.debug')) {
            $dir = Str::finish(config('oss.dir'), '/') . \strtolower(class_basename($class)) . '/';
            if (!Str::startsWith($object, $dir)) {
                return null;
            }
        }

        return app('oss')->signUrl(config('oss.bucket'), $object, $timeout, 'GET', $options);
    }

    public static function find($class, $key, $object)
    {
        return OssObject::where([
            'ossobjectable_type' => $class,
            'ossobjectable_id' => $key,
            'name' => $object,
        ])->first();
    }

    public static function delete($class, $key, $object): bool
    {
        // 检查目录，避免操作其它目录内容
        $dir = Str::finish(config('oss.dir'), '/') . \strtolower(class_basename($class)) . '/' . $key . '/';
        if (!Str::startsWith($object, $dir)) {
            return false;
        }

        // 尝试删除数据
        $row = OssObject::where([
            'ossobjectable_type' => $class,
            'ossobjectable_id' => $key,
            'name' => $object,
        ])->delete();
        if (empty($row)) {
            return false;
        }

        // OSS Object 删除作业
        \mradang\LaravelOss\Jobs\OssDelete::dispatch($class, $key, $object);

        return true;
    }

    // 删除 OSS Object，仅限数据库相关数据删除后调用
    // 仅限 job 调用，可利用 job 失败重试机制确保删除成功
    public static function handleDelete($class, $key, $object)
    {
        $ret = app('oss')->deleteObject(config('oss.bucket'), $object);
        $http_code = Arr::get($ret, 'info.http_code');
        if ($http_code != 204) {
            throw new \Exception('OSS Object ' . $object . ' delete fail');
        }
    }

    public static function clear($class, $key, $group)
    {
        $objects = OssObject::where([
            'ossobjectable_type' => $class,
            'ossobjectable_id' => $key,
        ])->where(function ($query) use ($group) {
            if ($group) {
                $query->where('group', $group);
            }
        })->get();
        foreach ($objects as $object) {
            self::delete($class, $key, $object->name);
        }
    }

    // 保存排序值
    public static function saveSort($class, $key, array $data)
    {
        foreach ($data as $item) {
            OssObject::where([
                'id' => $item['id'],
                'ossobjectable_type' => $class,
                'ossobjectable_id' => $key,
            ])->update(['sort' => $item['sort']]);
        }
    }

    // 通过本地文件上传
    public static function createByFile($class, $key, $filename, $group, array $data)
    {
        // 文件扩展名
        $file = new File($filename);
        $extension = $file->guessExtension();
        $extension = \strtolower($extension ?? pathinfo($filename, PATHINFO_EXTENSION));
        if (empty($extension)) {
            throw new \Exception('文件扩展名为空');
        }

        // 生成 OSS Object 名
        $dir = Str::finish(config('oss.dir'), '/') . \strtolower(class_basename($class)) . '/' . $key . '/';
        $object_name = $dir . self::generateObjectName() . '.' . $extension; // 指定上传对象名

        // 上传选项
        $options = [];
        $mime = MimeTypes::getMimetype('filename.' . $extension);
        if ($mime) {
            $options['Content-Type'] = $mime;
        }

        // 上传
        $ret = app('oss')->uploadFile(config('oss.bucket'), $object_name, $filename, $options);
        $http_code = Arr::get($ret, 'info.http_code');
        if ($http_code != 200) {
            throw new \Exception('OSS Object ' . $object_name . ' upload fail.');
        }

        // 入库
        $imagesize = @getimagesize($filename);
        $model = new OssObject([
            'ossobjectable_type' => $class,
            'ossobjectable_id' => $key,
            'group' => $group,
            'name' => $object_name,
            'size' => \filesize($filename),
            'mimeType' => Arr::get($ret, 'oss-requestheaders.Content-Type'),
            'imageInfo' => is_array($imagesize) ? ['width' => $imagesize[0], 'height' => $imagesize[1]] : null,
            'data' => $data,
            'sort' => OssObject::where([
                'ossobjectable_type' => $class,
                'ossobjectable_id' => $key,
            ])->max('sort') + 1,
        ]);
        $model->save();

        return $model;
    }

    public static function updateData($class, $key, int $id, array $data)
    {
        $model = OssObject::where([
            'id' => $id,
            'ossobjectable_type' => $class,
            'ossobjectable_id' => $key,
        ])->first();

        if ($model) {
            $model->data = [
                ...$model->data,
                ...$data,
            ];
            $model->save();
        }
    }

    // 同步上传指定 URL 文件
    public static function createByUrl($class, $key, $url, $group, array $data, $content_timeout = 10)
    {
        // 下载
        $temp_file = tempnam(sys_get_temp_dir(), 'laravel-oss');
        Http::sink($temp_file)->connectTimeout($content_timeout)->get($url);

        // 上传文件
        $ret = self::createByFile($class, $key, $temp_file, $group, $data);
        @\unlink($temp_file);

        return $ret;
    }

    // 异步上传指定 Url 文件
    public static function asyncCreateByUrl($class, $key, $url, $group, array $data)
    {
        \mradang\LaravelOss\Jobs\OssUploadUrl::dispatch($class, $key, $url, $group, $data);
    }

    // 调度执行跟踪器，检查上传 OSS Object 的数据入库情况
    public static function scheduleTracker()
    {
        // 处理超过指定时间之前的数据
        // 能查到跟踪数据，可能的原因：
        // - callback 出错
        // - callback 作业未处理完成（job 任务过多，无法及时处理，数据没有存入数据库）
        // - 用户上传失败
        // - 用户放弃上传
        $before_time = now()->subHours(24); // 24 小时之前
        $tracks = OssTrack::where('created_at', '<', $before_time)->inRandomOrder()->take(5)->get();
        $tracks->each(function ($track) {
            // 取出常用值
            $class = $track->osstracktable_type;
            $key = $track->osstracktable_id;
            $object = $track->name;

            // 检查对象是否入库，避免误删 OSS Object
            $exists = OssObject::where([
                'ossobjectable_type' => $class,
                'ossobjectable_id' => $key,
                'name' => $object,
            ])->exists();

            if (!$exists) {
                \mradang\LaravelOss\Jobs\OssDelete::dispatch($class, $key, $object);
                $track->delete();
            }
        });
    }
}
