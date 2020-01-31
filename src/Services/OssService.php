<?php

namespace mradang\LaravelOss\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use GuzzleHttp\Client;

use mradang\LaravelOss\Models\OssObject;

class OssService
{

    public static function makeUploadParams($class, $key, array $data)
    {
        $client = app('oss');

        // 上传参数
        $host = 'http://'.config('oss.endpoint');
        $dir = Str::finish(config('oss.dir'), '/').\strtolower(class_basename($class)).'/'.$key.'/';
        $callback_vars = [
            'class' => $class,
            'key' => $key,
            'data' => $data,
        ];
        $callback_vars_encrypt = encrypt($callback_vars);

        // 回调参数
        $callbackUrl = self::app_url().'/api/laravel_oss/callback';
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
        debug($callback_param);

        // 上传策略
        $end = time() + 30; // 30秒内有效
        $expiration = self::gmt_iso8601($end);
        $object_name = $dir.self::generateObjectName(); // 指定上传对象名

        $conditions = [
            // 限定存储桶
            ['eq', '$bucket', config('oss.bucket')],
            // 最大文件大小.用户可以自己设置
            ['content-length-range', 0, self::human2byte(config('oss.maxsize'))],
            // 强制上传对象名称
            ['eq', '$key', $object_name],
            // 限制额外的参数
            ['eq', '$x:callbackvars', $callback_vars_encrypt],
        ];

        $policy = json_encode([
            'expiration' => $expiration,
            'conditions' => $conditions,
        ]);
        debug($policy);
        $base64_policy = base64_encode($policy);
        $signature = base64_encode(hash_hmac('sha1', $base64_policy, config('oss.key'), true));

        $response = array();
        $response['accessid'] = config('oss.id');
        $response['host'] = $host;
        $response['policy'] = $base64_policy;
        $response['signature'] = $signature;
        $response['expire'] = $end;
        $response['callback'] = $base64_callback_body;
        $response['key'] = $object_name;  // 这个参数是设置用户上传文件名
        $response['callbackvars'] = $callback_vars_encrypt;
        debug($response);
        return $response;
    }

    public static function app_url()
    {
        if (config('app.env') === 'production') {
            $schemeAndHttpHost = request()->getSchemeAndHttpHost();
        } else {
            $schemeAndHttpHost = config('app.url');
        }
        return $schemeAndHttpHost;
    }

    public static function gmt_iso8601($time)
    {
        $dtStr = date('c', $time);
        $mydatetime = new \DateTime($dtStr);
        $expiration = $mydatetime->format(\DateTime::ISO8601);
        $pos = strpos($expiration, '+');
        $expiration = substr($expiration, 0, $pos);
        return $expiration.'Z';
    }

    public static function generateObjectName()
    {
        $rand = sprintf('%04d', mt_rand(1, 9999));
        return date('YmdHis').'_'.$rand;
    }

    // 将人类可读的文件大小值转换为数字，支持 K, M, G and T，无效的输入返回 0
    public static function human2byte($value) : int
    {
        $ret = preg_replace_callback('/^\s*([\d.]+)\s*(?:([kmgt]?)b?)?\s*$/i', function ($m) {
            switch (strtolower($m[2])) {
                case 't': $m[1] *= 1024;
                case 'g': $m[1] *= 1024;
                case 'm': $m[1] *= 1024;
                case 'k': $m[1] *= 1024;
            }
            return $m[1];
        }, $value);
        return is_numeric($ret) ? (int)$ret : 0;
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
        debug($body);

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
            'name' => Arr::get($params, 'object'),
            'size' => Arr::get($params, 'size'),
            'mimeType' => Arr::get($params, 'mimeType'),
            'imageInfo' => Arr::get($params, 'width') ? Arr::only($params, ['width', 'height', 'format']) : null,
            'data' => Arr::get($callbackvars, 'data', []),
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
        $client = new Client();
        $response = $client->request('GET', $pubKeyUrl);
        $pubKey = $response->getBody()->getContents();

        if (empty($pubKey)) {
            return false;
        }

        // 拼接待签名字符串
        $authStr = '';
        $path = $request_uri;
        $pos = strpos($path, '?');
        if ($pos === false) {
            $authStr = urldecode($path)."\n".$body;
        } else {
            $authStr = urldecode(substr($path, 0, $pos)).substr($path, $pos, strlen($path) - $pos)."\n".$body;
        }

        // 验证签名
        $ok = openssl_verify($authStr, $authorization, $pubKey, OPENSSL_ALGO_MD5);
        if ($ok !== 1) {
            return false;
        }

        // 入库
        $model = new OssObject(self::parseCallbackBody($body));
        $model->sort = OssObject::where([
            'ossobjectable_type' => $model->ossobjectable_type,
            'ossobjectable_id' => $model->ossobjectable_id,
        ])->max('sort') + 1;
        $model->save();
    }

    // $timeout URL 的有效期，最长 3600 秒（1 小时）
    public static function generateObjectUrl($class, $object, $timeout = 300, $options = null)
    {
        $client = app('oss');

        // 检查目录，避免为其它目录内容生成链接
        $dir = Str::finish(config('oss.dir'), '/').\strtolower(class_basename($class)).'/';
        if (!Str::startsWith($object, $dir)) {
            return null;
        }

        return $client->signUrl(config('oss.bucket'), $object, $timeout, 'GET', $options);
    }

    public static function find($class, $key, $object)
    {
        return OssObject::where([
            'ossobjectable_type' => $class,
            'ossobjectable_id' => $key,
            'name' => $object,
        ])->first();
    }

    public static function delete($class, $key, $object)
    {
        // 检查目录，避免操作其它目录内容
        $dir = Str::finish(config('oss.dir'), '/').\strtolower(class_basename($class)).'/'.$key.'/';
        if (Str::startsWith($object, $dir)) {
            \mradang\LaravelOss\Jobs\OssDelete::dispatch($class, $key, $object);
        }
    }

    // 作业处理删除
    public static function handleDelete($class, $key, $object)
    {
        $ret = app('oss')->deleteObject(config('oss.bucket'), $object);
        $http_code = Arr::get($ret, 'info.http_code');
        if ($http_code < 200 || $http_code >= 300) {
            throw new \Exception('OSS Object '.$object.' delete fail.');
        }

        $retry = 10;
        for ($i=0; $i < $retry; $i++) {
            $ossobject = OssObject::where([
                'ossobjectable_type' => $class,
                'ossobjectable_id' => $key,
                'name' => $object,
            ])->first();

            if ($ossobject) {
                $ossobject->delete();
                break;
            } else {
                sleep(5);
            }
        }

        if ($i === $retry) {
            throw new \Exception('数据未入库');
        }
    }

}
