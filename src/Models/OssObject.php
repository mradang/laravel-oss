<?php

namespace mradang\LaravelOss\Models;

use Illuminate\Database\Eloquent\Model;

class OssObject extends Model {

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
    ];

    protected $hidden = [
        'ossobjectable_type',
        'ossobjectable_id',
    ];

    public function ossobjectable() {
        return $this->morphTo();
    }

}
