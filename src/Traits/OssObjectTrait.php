<?php

namespace mradang\LaravelOss\Traits;

use mradang\LaravelOss\Services\OssService;

trait OssObjectTrait
{

    public function ossobjectUploadParams(array $data = [])
    {
        return OssService::makeUploadParams(__CLASS__, $this->getKey(), $data);
    }

    public function ossobjects()
    {
        return $this->morphMany('mradang\LaravelOss\Models\OssObject', 'ossobjectable')->orderBy('sort');
    }

    public function ossobjectFind($name)
    {
        return OssService::find(__CLASS__, $this->getKey(), $name);
    }

    public function ossobjectDelete($name)
    {
        return OssService::delete(__CLASS__, $this->getKey(), $name);
    }

    public function ossobjectClear()
    {
        return OssService::clear(__CLASS__, $this->getKey());
    }

    public function ossobjectSaveSort(array $data)
    {
        return OssService::saveSort(__CLASS__, $this->getKey(), $data);
    }



    public function ossobjectCreateByFile($file, array $data = [])
    {
        return OssService::createByFile(__CLASS__, $this->getKey(), $file, $data);
    }

    public function ossobjectCreateByUrl($url, array $data = [])
    {
        return OssService::createByUrl(__CLASS__, $this->getKey(), $url, $data);
    }





}
