<?php

namespace App\Jobs;

use App\Api\Controllers\AudioController;
use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class TwillioRecordingsSave implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    
    private $sid;
    private $user_id;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($sid, $user_id)
    {
        $this->sid = $sid;
        $this->user_id = $user_id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $sid = env("TWILIO_ACCOUNT_SID", false);
        $token = env("TWILIO_AUTH_TOKEN", false);
        $twilio = new Client($sid, $token);

        $recording = $twilio->recordings($this->sid)->fetch();
        Log::info('TwillioRecordingsSave Job, recording', $recording->toArray());

        // $transcriptions = $twilio->transcriptions($this->sid)->fetch();
        // Log::info('transcriptions', $transcriptions->toArray());
        
        
        if ($recording->status == 'completed') {
            $audioController = new AudioController();
            $data = [
                'audio_url' => "https://api.twilio.com" . str_replace('.json', '', $recording->uri) . '.wav',
                'post_input_type' => 'phone',
                'twilio_recording' => json_encode($recording->toArray())
            ];

            $transcriptionsurl = "https://api.twilio.com" . $recording->subresourceUris['transcriptions'];
            
            $transcriptions = $twilio->request("GET", $transcriptionsurl);

            Log::info('transcriptions: ', $transcriptions->getContent());

            //check if transcription is completed
            if ($transcriptions->getStatusCode() == 200) {
                $transcription = $transcriptions->getContent()['transcriptions'][0];
                if ($transcription['status'] == 'completed') {
                    $data['transcription'] = $transcription['transcriptionText'];
                } else {
                    Log::error('twilio transcription not completed yet');
                }
            } else {
                Log::info("transcriptions call failed");
            }
            
            $channel_user = User::find($this->user_id);
            $audio = $audioController->saveAudioAndProcess($data, $channel_user, null);
            $msg = 'new audio created, id:' . $audio->id . ' for user: ' . $channel_user->id;
            Log::info($msg);
        } else {
            $msg = 'recording not ready yet, ReDispatching in 5 seconds';
            Log::info($msg);
            //redispatch the job
            //multiply with times trying
            $this->release();
        }
    }

    /**
    * Calculate the number of seconds to wait before retrying the job.
    * how many seconds Laravel should wait before retrying a job that has encountered an exception
    * @return array
    */
    public function backoff()
    {
        return [5, 10, 30];
    }

    public function failed()
    {
        Log::info('TwillioRecordingsSave Job failed');
    }
}
