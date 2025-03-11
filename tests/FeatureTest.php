<?php

namespace Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use mradang\LaravelOss\Models\OssTrack;

class FeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_basic_features()
    {
        $user1 = User::create(['name' => 'user1']);
        $user2 = User::create(['name' => 'user2']);
        $this->assertSame(1, $user1->id);
        $this->assertSame(2, $user2->id);

        $group = 'photo';

        // 下载 URL 并上传
        $url = 'https://img.alicdn.com/tfs/TB1_uT8a5ERMeJjSspiXXbZLFXa-143-59.png';
        $oss1 = $user1->ossobjectCreateByUrl($url, $group, [
            'name' => 'image1',
            'time' => time(),
        ]);
        $this->assertSame('image/png', $oss1->mimeType);
        $this->assertSame('image1', Arr::get($oss1->data, 'name'));

        // 上传文件
        $width = 240;
        $height = 160;
        $fakeImage = UploadedFile::fake()->image('image2.jpg', $width, $height);
        $oss2 = $user1->ossobjectCreateByFile($fakeImage->getRealPath(), $group);
        $this->assertSame($width, $oss2->imageInfo['width']);
        $this->assertSame($height, $oss2->imageInfo['height']);
        $this->assertSame([], $oss2->data);

        // 现有 2 个 oss 对象
        $this->assertSame(2, $user1->ossobjects->count());

        // 查找 oss 对象
        $find_oss2 = $user1->ossobjectFind($oss2->name);
        $this->assertSame($height, $find_oss2->imageInfo['height']);
        $this->assertNull($user1->ossobjectFind('abc123'));

        // 生成加密 URL
        $url = $user1->ossobjectGenerateUrl($oss2->name);
        $response = Http::get($url);
        $this->assertSame(200, $response->status());
        $this->assertEquals($oss2->size, Arr::get($response->headers()['Content-Length'], 0));

        // 删除第 2 个 oss 对象
        $user1->ossobjectDelete($oss2->name);
        $user1->load('ossobjects');
        $this->assertSame(1, $user1->ossobjects->count());

        // 清空所有 oss 对象
        $user1->ossobjectClear();
        $user1->load('ossobjects');
        $this->assertSame(0, $user1->ossobjects->count());

        // 生成上传参数
        $params = $user2->ossobjectUploadParams('jpg', $group, ['name' => 'test']);
        $this->assertEquals($params['host'], $this->app['config']['oss.endpoint']);
        $this->assertTrue(OssTrack::where([
            'osstracktable_type' => User::class,
            'osstracktable_id' => $user2->id,
            'name' => $params['key'],
        ])->exists());

        // 自动删除 oss 对象
        $fakeImage = UploadedFile::fake()->image('image3.jpg');
        $oss3 = $user2->ossobjectCreateByFile($fakeImage->getRealPath(), $group);
        $this->assertSame([], $oss3->data);
        $this->assertEquals($fakeImage->getSize(), $oss3->size);
        $user2->delete();
    }

    public function test_config()
    {
        $this->assertEquals('test', $this->app['config']['oss.dir']);
        $this->assertEquals('10mb', $this->app['config']['oss.maxsize']);
    }
}
