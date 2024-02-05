<?php

namespace App\Jobs;

use App\Audio;
use Aws\TranscribeService\TranscribeServiceClient;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class TranscribeAudio implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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
        // dd("Transcribing Audio: " . $this->audio->id);
        // upload file on S3 Bucket
        try {
            $audio = Audio::find($this->audio->id);

            // check ifn AUDIO_TRANSCRIPTION status is true if yes do transcription otherwise just update status
            if (!env('AUDIO_TRANSCRIPTION_PROFANITY_SENTIMENT', false)) {
                Log::info("Audio Transcription is disabled, audio: " . $this->audio->id);

                $audio->profanity = 'green';
                $audio->transcription = 'transcription disabled';
                $audio->confidence = 99;
                $audio->transcription_status = "success";
                $audio->sentiment_verdict = 'green';
                $audio->sentiment_response = 'sentiment disabled';
                $audio->save();
                
                return null;
            }
            //update transcription status as in progress
            $audio->transcription_status = "in_progress";
            $audio->save();

            // Create Amazon Transcribe Client
            $credentials = new \Aws\Credentials\Credentials(
                env('AWS_TRANSCRIBE_ACCESS_KEY_ID'),
                env('AWS_TRANSCRIBE_SECRET_ACCESS_KEY')
            );

            $awsTranscribeClient = new TranscribeServiceClient([
                'region' => env('AWS_DEFAULT_REGION'),
                'version' => 'latest',
                'credentials' => $credentials
            ]);

            // check if one job already exists
            $existingJob = $awsTranscribeClient->listTranscriptionJobs([
                'JobNameContains' => "{$this->audio->audioFileName}",
            ]);

            $job_id = $this->audio->audioFileName;
            if (count($existingJob->get('TranscriptionJobSummaries')) > 0) {
                Log::info('Transcription job already exists for audio id: ' . $this->audio->id .' url: ' . $this->audio->audioURL);
            } else {
                // Start a Transcription Job
                $params = [
                    'IdentifyMultipleLanguages' => true,
                    'Media' => [
                        'MediaFileUri' => $this->audio->audioURL,
                    ],
                    'TranscriptionJobName' => "{$job_id}",
                    'Settings' => [
                        'ShowSpeakerLabels' => true,
                        'MaxSpeakerLabels' => ($this->audio->speakers < 2) ? 2 : $this->audio->speakers,
                        'ChannelIdentification' => false,
                        'EnableSpeakerIdentification' => true,
                    ],
                ];
                // Log::info('Starting Transcription Job for audio: ', $params);
                $awsTranscribeClient->startTranscriptionJob($params);
            }

            Log::info('Transcription job started successully, id: ' . $this->audio->id . ' url: ' . $this->audio->audioURL);
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }
    }

    /**
     * handle if job fails
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(Throwable $exception)
    {
        Log::info('TranscribeAudio Job failed to start transcription, id: ' . $this->audio->audioFileName . ' url: ' . $this->audio->audioURL);
        // $this->audio->transcription_status = 'failed';
        // $this->audio->save();
    }
}
