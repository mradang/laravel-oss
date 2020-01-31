<?php

namespace mradang\LaravelOss\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OssDelete implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $class, $key, $object;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($class, $key, $object)
    {
        $this->class = $class;
        $this->key = $key;
        $this->object = $object;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        \mradang\LaravelOss\Services\OssService::handleDelete($this->class, $this->key, $this->object);
    }
}
