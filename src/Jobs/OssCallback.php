<?php

namespace mradang\LaravelOss\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OssCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $request_uri, $pubKeyUrlBase64, $authorizationBase64, $body;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($request_uri, $pubKeyUrlBase64, $authorizationBase64, $body)
    {
        $this->request_uri = $request_uri;
        $this->pubKeyUrlBase64 = $pubKeyUrlBase64;
        $this->authorizationBase64 = $authorizationBase64;
        $this->body = $body;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        \mradang\LaravelOss\Services\OssService::handleCallback(
            $this->request_uri,
            $this->pubKeyUrlBase64,
            $this->authorizationBase64,
            $this->body
        );
    }
}
