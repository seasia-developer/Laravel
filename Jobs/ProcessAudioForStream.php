<?php

namespace App\Jobs;

use App\Audio;
use App\Mail\StationContentProcessed;
use App\Services\Azuracast;
use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Facades\Mail;

class ProcessAudioForStream implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        try {
            Log::info("ProcessAudioForStream start ================================");
            Log::info('processing audio for livestream: ' . $this->audio->id);

            if ($this->audio->live_stream_status == 'ready') {
                Log::info('this audio is already live stream ready, no need to process');

                return;
            }
            
            $reqUserId = $this->audio->userID;
            $user = User::find($reqUserId);

            $gapAudio = dosPath(public_path('audio-normalize/2-sec-gap.mp3'));

            $tmpDir = dosPath(storage_path('app/public/audio-normalize-tmp-' . $reqUserId));

            if (!is_dir($tmpDir)) {
                Log::info('making directory: ' . $tmpDir);
                File::makeDirectory($tmpDir, 0755, true, true);
            }

            Log::info('user -> background_music: ' . $user->background_music);
            if (!empty($user->background_music) && $user->background_music != 'no_music') {
                //with extension
                $bg_music_name = last(explode('/', $user->background_music));
                $path = dosPath($tmpDir . '/bg_' . $bg_music_name);
                $bgAudio = $path;
                Log::info('BG music path: ' . $path);
                if (!file_exists($path) && !empty($user->background_music) && $user->background_music != 'no_music') {
                    Log::info('Downloading the bg audio file, path: ' . $path . ' background music url: ' . $user->background_music);
                    Storage::disk('public')->put(dosPath('audio-normalize-tmp-' . $reqUserId . '/bg_' . $bg_music_name), file_get_contents($user->background_music));
                }
            } else {
                $bgAudio = null;
                $bg_music_name = 'no_music';
            }
            Log::info('bg_music_name: ' . $bg_music_name);

            $audio = $this->audio->audioURL;
            $uniqueID = $this->audio->audioFileName;

            $explode = last(explode('/', $audio));
            $extension = last(explode('.', $explode));

            $path = dosPath($tmpDir . '/' . $uniqueID . '.' . $extension);
            $lufs_path = dosPath($tmpDir . '/' . $uniqueID . '_lufs.' . $extension);
            $denoise_path = dosPath($tmpDir . '/' . $uniqueID . '_denoise.' . $extension);
            $gap_path = dosPath($tmpDir . '/' . $uniqueID . '_gap.' . $extension);
            $final_path = dosPath($tmpDir . '/' . $uniqueID . '_edited.' . $extension);

            if (!file_exists($path)) {
                Log::info('Downloading the audio file, path: ' . $path . ' audiourl: ' . $audio);
                Storage::disk('public')->put(dosPath('audio-normalize-tmp-' . $reqUserId . '/' . $uniqueID . '.' . $extension), file_get_contents($audio));
            }

            $audioLength = $this->getAudioLengthInSec($path);
            Log::info("Audio length: {$audioLength}");

            $new_path = $path;
            
            if (env('NOISE_REDUCTION', false)) {
                Log::info('Performing noise reduction');
                if ($this->noiseReduction($new_path, $denoise_path)) {
                    Log::info('Success, Denoise path: ' . $denoise_path);
                    $new_path = $denoise_path;
                }
            }

            if ($bgAudio != null) {
                Log::info('Adding BG music');
                $bgAudioLength = $this->getAudioLengthInSec($bgAudio);
                Log::info('BG music length: ' . $bgAudioLength);

                if ($audioLength > $bgAudioLength) {
                    Log::info('bgAudio: concatAudio');
                    //if audio is bigger than bg audio, concat bg audio to match the audio length
                    $bgAudio = $this->concatAudio($reqUserId, $bgAudio, $audioLength, $bgAudioLength);
                } elseif ($audioLength < $bgAudioLength) {
                    Log::info('bgAudio: dissociateAudio');
                    // if audio is smaller than bg audio, dissociate to match audio length
                    $bgAudio = $this->dissociateAudio($reqUserId, $bgAudio, $audioLength);
                }
                //others keep the bg as it is
                $bg_level = number_format($user->background_music_level / 100, 1, '.', '');

                Log::info('bg_music_level:' . $bg_level);

                Log::info('bgAudio:' . $bgAudio);

                $bgAudio = $this->adjustVolume($reqUserId, $bgAudio, $bg_level);

                Log::info('bgAudio path after adjustVolume:' . $bgAudio);
                
                //if audio is already there delete it first
                if (file_exists($final_path)) {
                    Storage::disk('public')->delete($final_path);
                }

                Log::info('Merging background music');

                $overlayCmd = 'ffmpeg -i ' . $new_path . ' -i ' . $bgAudio . ' -filter_complex amix=inputs=2:duration=longest ' . $final_path . ' -y';
                run_shell_command($overlayCmd);
            } else {
                $final_path = $new_path;
            }

            $s3FileName = 'audios/' . $uniqueID . '_edited.mp3';

            Log::info('Final edited audio length: ' . $this->getAudioLengthInSec($final_path));

            Storage::disk('s3')->put($s3FileName, file_get_contents($final_path), 'public');

            $version = randomStr(10);
            Storage::disk('s3')->put('audios/' . $uniqueID . '_'. $version .'.mp3', file_get_contents($final_path), 'public');

            Log::info('uploaded to s3: ' . $s3FileName);

            $this->audio->live_stream_status = 'ready';

            Log::info($this->audio->id.' Live stream status: '.$this->audio->live_stream_status);

            $this->audio->bg_music_used = $bg_music_name;
            $this->audio->version = $version;
            $this->audio->save();

            //check if it's last audio processed if yes update stream status as ready
            $audioCheck = Audio::where(['userID' => $reqUserId, 'live_stream_status' => 'in_progress'])->first();
            if ($audioCheck == null) {
                Log::info('processed all audios, live stream status update to READY');
                //send all audio processed email
                Log::info('sending station content updated email');
                $channel_name = ucwords(str_replace(['.', '_', '-'], ' ', $user->cast_name));
                Mail::to($user)->queue(new StationContentProcessed($channel_name, $user));
            }
        } catch (\Exception $e) {
            $fullError = $e->getMessage() . ' File: ' . $e->getFile() . ' Line: ' . $e->getLine();

            logger()->error($fullError);
        }

        Log::info("================================ ProcessAudioForStream end");
    }

    protected function noiseReduction($input_path, $output_path): bool
    {
        $cmd = '/usr/bin/php ' . app_path() . '/audio-processing/denoise.php  --input ' . $input_path . ' --output ' . $output_path;
        if (!run_shell_command($cmd)) {
            logger()->error('Error for input_path:' . $input_path);
            //TODO throw error
            return false;
        }

        return true;
    }

    protected function getAudioLufs($audioPath)
    {
        Log::info('getAudioLufs audioPath: ' . $audioPath);
        $output = [];

        $command = 'ffmpeg -i ' . $audioPath . ' -af loudnorm=I=-16:dual_mono=true:TP=-1.5:LRA=11:print_format=summary -f null - 2>&1';
        Log::info('running command: ' . $command);
        exec($command, $output, $returnStatus);

        //Log::info('output: ' . json_encode($output));
        if ($returnStatus == 0) {
            $explode = explode(' ', $output[30]);
            $lufs = isset($explode[5]) ? $explode[5] : null;
            Log::info("lufs: {$lufs}");

            return $lufs;
        }

        return null;
    }

    protected function concatAudio($reqUserId, $filePath, $audioLength, $bgAudioLength)
    {
        $times = ceil($audioLength / $bgAudioLength);
        Log::info('Concatenating Audio, filePath: ' . $filePath . ', times: ' . $times);
        $bgFilePath = '';

        for ($i = 0; $i < $times; $i++) {
            if ($i == ($times - 1)) {
                $bgFilePath .= $filePath;
            } else {
                $bgFilePath .= $filePath . '|';
            }
        }

        $cocatOutputFile = storage_path('app/public/audio-normalize-tmp-' . $reqUserId . '/c_bg.mp3');

        if (file_exists($cocatOutputFile)) {
            Storage::disk('public')->delete($cocatOutputFile);
        }

        $concatCmd = 'ffmpeg -i "concat:' . $bgFilePath . '" -acodec copy ' . $cocatOutputFile . ' -y';

        run_shell_command($concatCmd);

        Log::info('BG Audio length after concatenate: ' . $this->getAudioLengthInSec($cocatOutputFile));

        //it could be bigger so take only the length of audios length
        return $this->dissociateAudio($reqUserId, $cocatOutputFile, $audioLength);
    }

    protected function dissociateAudio($reqUserId, $filePath, $length)
    {
        Log::info('Dissociate Audio, filePath: ' . $filePath . ', length: ' . $length);
        $outputFile = storage_path('app/public/audio-normalize-tmp-' . $reqUserId . '/bg.mp3');

        if (file_exists($outputFile)) {
            //if file exist delete the file
            Storage::disk('public')->delete($outputFile);
        }

        $concatCmd = 'ffmpeg -ss 00 -i ' . $filePath . ' -t ' . $length . ' -c copy ' . $outputFile . ' -y';

        run_shell_command($concatCmd);

        return $outputFile;
    }

    protected function adjustVolume($reqUserId, $filePath, $level)
    {
        Log::info('Adjust Volume: ' . $filePath . ', level: ' . $level);

        $outputFile = storage_path('app/public/audio-normalize-tmp-' . $reqUserId . '/bg_audio_level.mp3');

        if (file_exists($outputFile)) {
            Storage::disk('public')->delete($outputFile);
        }
        //$level 0.0 ~ 1.0
        $concatCmd = 'ffmpeg -i ' . $filePath . ' -filter:a "volume=' . $level . '" ' . $outputFile . ' -y';

        run_shell_command($concatCmd);

        return $outputFile;
    }

    protected function getAudioLengthInSec($audio)
    {
        return getAudioLengthInSec($audio);
    }
}
