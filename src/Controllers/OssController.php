<?php

namespace mradang\LaravelOss\Controllers;

use Illuminate\Routing\Controller as BaseController;
use mradang\LaravelOss\Services\OssService;

class OssController extends BaseController
{
    public function callback()
    {
        return OssService::callback();
    }
}
