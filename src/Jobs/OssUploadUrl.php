<?php

namespace mradang\LaravelOss\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OssUploadUrl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $class, $key, $url, $data;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($class, $key, $url, array $data)
    {
        $this->class = $class;
        $this->key = $key;
        $this->url = $url;
        $this->data = $data;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        \mradang\LaravelOss\Services\OssService::createByUrl($this->class, $this->key, $this->url, $this->data);
    }
}
