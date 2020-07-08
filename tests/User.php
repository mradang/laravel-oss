<?php

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use mradang\LaravelOss\Traits\OssObjectTrait;

class User extends Model
{
    use OssObjectTrait;

    protected $fillable = ['name'];
}
