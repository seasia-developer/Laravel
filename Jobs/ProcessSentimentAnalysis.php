<?php

namespace App\Jobs;

use App\Sentiments;
use App\Services\Utilities;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use JoggApp\NaturalLanguage\NaturalLanguage;
use JoggApp\NaturalLanguage\NaturalLanguageClient;

class ProcessSentimentAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $GREEN = 'green';
    protected $WHITE = 'white';
    protected $ORANGE = 'orange';
    protected $RED = 'red';

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
    public function prepareVerdict(float $score, float $magnitude): string
    {
        if ($score >= 0.4 && $score <= 1.0) {
            return $this->GREEN;
        }
        if ($score >= 0.0 && $score <= 0.3) {
            return $this->WHITE;
        }
        if ($score <= -0.4 && $score >= -1.0) {
            return $this->RED;
        }
        if ($score <= -0.1 && $score >= -0.3) {
            return $this->ORANGE;
        }
        return false; // error
    }

    public function handle()
    {
        Log::info("Processing SentimentAnalysis for audio_id: " . $this->audio->id); //' ,transcription: ' . $this->audio->transcription

        $naturalLanguage = new NaturalLanguage(new NaturalLanguageClient([
            'key_file_path' => config('naturallanguage.key_file_path'),
            'project_id' => config('naturallanguage.project_id')
        ]));
        
        try {
            $wordsCount = 100; // max 100 words
            //pass first 100 words of transcription to natural language api
            $transcription = Utilities::getWords($this->audio->transcription, $wordsCount);
            
            Log::info('first ' . $wordsCount . ' words of transcription: ' . $transcription);
            
            $sentiment = $naturalLanguage->sentiment($transcription);
            if ($sentiment) {
                //insert in sentiments table
                $ourVerdict = $this->prepareVerdict($sentiment['score'], $sentiment['magnitude']);
                Log::info('OurVerdict : ' . $ourVerdict);
                if ($ourVerdict) {
                    $this->audio->sentiment_verdict = $ourVerdict;
                    $this->audio->sentiment_response = json_encode($sentiment);
                    $this->audio->save();
                // Log::info(json_encode($sentiment));
                } else {
                    Log::error('Unable to calculate our verdict as per our logic');
                }
            }
        } catch (\Exception $exception) {
            Log::error(json_encode($exception));
        }
    }
}
