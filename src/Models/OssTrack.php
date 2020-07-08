<?php

namespace mradang\LaravelOss\Models;

use Illuminate\Database\Eloquent\Model;

class OssTrack extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'osstracktable_type',
        'osstracktable_id',
    ];

    public function osstracktable()
    {
        return $this->morphTo();
    }
}
