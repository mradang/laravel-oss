<?php

namespace mradang\LaravelOss\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use GuzzleHttp\Client;

use mradang\LaravelOss\Models\OssObject;

class OssService
{

    public static function makeUploadParams($class, $key)
    {
        debug(class_basename($class));

        $client = app('oss');

        // $id = env('OSS_ACCESS_KEY_ID');          // 请填写您的AccessKeyId。
        // $key = env('OSS_ACCESS_KEY_SECRET');     // 请填写您的AccessKeySecret。
        $host = 'http://'.env('OSS_BUCKET').'.'.env('OSS_ENDPOINT');
        $callbackUrl = self::app_url().'/api/activity/ossCallback';
        $dir = Str::finish(config('oss.dir'), '/');

        $callback_param = [
            'callbackUrl' => $callbackUrl,
            'callbackBody' => 'filename=${object}&size=${size}&mimeType=${mimeType}&height=${imageInfo.height}&width=${imageInfo.width}',
            'callbackBodyType' => 'application/x-www-form-urlencoded',
        ];
        debug($callback_param);
        $callback_string = json_encode($callback_param);

        $base64_callback_body = base64_encode($callback_string);
        $now = time();
        $expire = 30;  // 设置该policy超时时间是10s. 即这个policy过了这个有效时间，将不能访问。
        $end = $now + $expire;
        $expiration = self::gmt_iso8601($end);

        // 最大文件大小.用户可以自己设置
        $condition = array(0=>'content-length-range', 1=>0, 2=>1048576000);
        $conditions[] = $condition;

        // 表示用户上传的数据，必须是以$dir开始，不然上传会失败，这一步不是必须项，只是为了安全起见，防止用户通过policy上传到别人的目录。
        $start = array(0=>'starts-with', 1=>'$key', 2=>$dir);
        $conditions[] = $start;

        $arr = array('expiration'=>$expiration,'conditions'=>$conditions);
        $policy = json_encode($arr);
        debug($arr, $policy);
        $base64_policy = base64_encode($policy);
        $string_to_sign = $base64_policy;
        $signature = base64_encode(hash_hmac('sha1', $string_to_sign, $key, true));

        $response = array();
        $response['accessid'] = $id;
        $response['host'] = $host;
        $response['policy'] = $base64_policy;
        $response['signature'] = $signature;
        $response['expire'] = $end;
        $response['callback'] = $base64_callback_body;
        $response['dir'] = $dir;  // 这个参数是设置用户上传文件时指定的前缀。
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

    public static function callback()
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
            return ['Status' => 'Ok'];
        } else {
            return false;
        }
    }

}
