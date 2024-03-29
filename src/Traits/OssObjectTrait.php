<?php

namespace mradang\LaravelOss\Traits;

use mradang\LaravelOss\Services\OssService;

trait OssObjectTrait
{
    private function getOrderByDirection()
    {
        return $this->ossOrderByDirection ? $this->ossOrderByDirection : 'asc';
    }

    public function ossobjectUploadParams($extension, $group, array $data = [])
    {
        return OssService::makeUploadParams(__CLASS__, $this->getKey(), $extension, $group, $data);
    }

    public function ossobjects()
    {
        return $this->morphMany('mradang\LaravelOss\Models\OssObject', 'ossobjectable')
            ->orderBy('sort', $this->getOrderByDirection());
    }

    public function ossobjectFind($name)
    {
        return OssService::find(__CLASS__, $this->getKey(), $name);
    }

    public function ossobjectDelete($name)
    {
        return OssService::delete(__CLASS__, $this->getKey(), $name);
    }

    public function ossobjectClear($group = null)
    {
        return OssService::clear(__CLASS__, $this->getKey(), $group);
    }

    public function ossobjectSaveSort(array $data)
    {
        return OssService::saveSort(__CLASS__, $this->getKey(), $data);
    }

    public function ossobjectCreateByFile($filename, $group, array $data = [])
    {
        return OssService::createByFile(__CLASS__, $this->getKey(), $filename, $group, $data);
    }

    public function ossobjectCreateByUrl($url, $group, array $data = [], $content_timeout = 10)
    {
        return OssService::createByUrl(__CLASS__, $this->getKey(), $url, $group, $data, $content_timeout);
    }

    public function ossobjectAsyncCreateByUrl($url, $group, array $data = [])
    {
        return OssService::asyncCreateByUrl(__CLASS__, $this->getKey(), $url, $group, $data);
    }

    public static function ossobjectGenerateUrl($object, $timeout = 300, $options = null)
    {
        return OssService::generateObjectUrl(__CLASS__, $object, $timeout, $options);
    }

    public function ossobjectUpdateData(int $id, array $data)
    {
        return OssService::updateData(__CLASS__, $this->getKey(), $id, $data);
    }

    protected static function bootOssobjectTrait()
    {
        static::deleting(function ($model) {
            $model->ossobjectClear();
        });
    }
}
