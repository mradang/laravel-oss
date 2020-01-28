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
        $host = 'http://'.config('oss.bucket').'.'.config('oss.endpoint');
        $dir = Str::finish(config('oss.dir'), '/') . \strtolower(class_basename($class)) . '/';

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
                'class=${x:class}',
                'key=${x:key}',
                'data=${x:data}',
            ]),
            'callbackBodyType' => 'application/x-www-form-urlencoded',
        ];
        $callback_string = json_encode($callback_param);
        $base64_callback_body = base64_encode($callback_string);
        debug($callback_param);

        // 上传策略
        $end = time() + 30; // 30秒内有效
        $expiration = self::gmt_iso8601($end);
        $object_name = $dir.self::generateObjectName()."_${key}"; // 指定上传对象名

        $conditions = [
            // 最大文件大小.用户可以自己设置
            ['content-length-range', 0, 1048576000],
            // 强制上传对象名称
            ['eq', '$key', $object_name],
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
        $response['callback_vars'] = [
            'class' => $class,
            'key' => $key,
            'data' => \json_encode($data),
        ];
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

    public static function handleCallback()
    {
        // 1.获取OSS的签名header和公钥url header
        $authorizationBase64 = Arr::get($_SERVER, 'HTTP_AUTHORIZATION', '');
        $pubKeyUrlBase64 = Arr::get($_SERVER, 'HTTP_X_OSS_PUB_KEY_URL', '');

        if (empty($authorizationBase64) || empty($pubKeyUrlBase64)) {
            return false;
        }

        // 2.获取OSS的签名
        $authorization = base64_decode($authorizationBase64);

        // 3.获取公钥
        $pubKeyUrl = base64_decode($pubKeyUrlBase64);

        $client = new Client();
        $response = $client->request('GET', $pubKeyUrl);
        $pubKey = $response->getBody()->getContents();

        if (empty($pubKey)) {
            return false;
        }

        // 4.获取回调body
        $body = file_get_contents('php://input');
        debug($body);

        // 5.拼接待签名字符串
        $authStr = '';
        $path = $_SERVER['REQUEST_URI'];
        $pos = strpos($path, '?');
        if ($pos === false) {
            $authStr = urldecode($path)."\n".$body;
        } else {
            $authStr = urldecode(substr($path, 0, $pos)).substr($path, $pos, strlen($path) - $pos)."\n".$body;
        }

        // 6.验证签名
        $ok = openssl_verify($authStr, $authorization, $pubKey, OPENSSL_ALGO_MD5);
        if ($ok === 1) {
            parse_str($body, $params);
            debug($params);
            return self::create($params);
        } else {
            return false;
        }
    }

    public static function create(array $body)
    {
        $model = new OssObject([
            'ossobjectable_type' => Arr::get($body, 'class'),
            'ossobjectable_id' => Arr::get($body, 'key'),
            'name' => Arr::get($body, 'object'),
            'size' => Arr::get($body, 'size'),
            'mimeType' => Arr::get($body, 'mimeType'),
            'imageInfo' => Arr::only($body, ['width', 'height', 'format']),
            'sort' => OssObject::where([
                'ossobjectable_type' => Arr::get($body, 'class'),
                'ossobjectable_id' => Arr::get($body, 'key'),
            ])->max('sort') + 1,
            'data' => json_decode(Arr::get($body, 'data', []), true),
        ]);

        if ($model->save()) {
            return $model;
        }
    }

}
