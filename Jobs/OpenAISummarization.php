<?php

namespace App\Jobs;

use App\Traits\OpenAITrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OpenAISummarization implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, OpenAITrait;

    protected $audio;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($audio)
    {
        $this->audio = $audio;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info("Processing OpenAISummarization for audio_id: " . $this->audio->id);
        $this->callOpenAIApi($this->audio);
    }
}
