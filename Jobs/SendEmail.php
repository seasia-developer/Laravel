<?php

namespace App\Jobs;

use App\Mail\WelcomeUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Mail;

class SendEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $to;
    protected $mailer;
    protected $subject;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($to, $subject, $mailer)
    {
        $this->to = $to;
        $this->mailer = $mailer;
        $this->subject = $subject;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('Sending email to : ' . $this->to);
        Mail::to($this->to)
            ->send($this->mailer);
    }
}
