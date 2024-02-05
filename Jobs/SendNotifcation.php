<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Mail\NotificationSendFailed;
use App\Member;
use App\NotificationSender;
use App\Traits\Twillio;
use Carbon\Carbon;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ixudra\Curl\Facades\Curl;
use Cache;
use DateTime;
use DateInterval;
use Mail;
use Twilio\Rest\Client;

class SendNotifcation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use Twillio;

    protected $notificationSenderId;
    protected $twilioFromNo;

    protected $deviceInfo;
    protected $notificationSender;

    protected $twilio_error_codes;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($notificationSenderId, $twilioFromNo=null)
    {
        $this->notificationSenderId = $notificationSenderId;
        $this->twilioFromNo = $twilioFromNo;

        //error codes from twilio which we don't need to retry. https://www.twilio.com/docs/api/errors#2-anchor
        $this->twilio_error_codes = [21211, 21610, 20003];
    }

    public function handle()
    {
        Log::info('Sending notification');
        //check if we have anything to send
        $notificationSender = NotificationSender::where(['id' => $this->notificationSenderId, 'process_status' => 'not_sent'])->first();
        if ($notificationSender != null) {
            if ($notificationSender['type'] == 'text') {
                $this->processSMS($notificationSender);
            } elseif ($notificationSender['type'] == 'call') {
                // Log::info('Processing call notification');
                $this->processCall($notificationSender);
            } else {
                Log::error("can't handle " . $notificationSender['type'] . " type notification");
            }
        } else {
            Log::error('Notification details not found, ' . $this->notificationSenderId);
        }
    }

    private function processSMS($row)
    {
        Log::info('Processing SMS for ' . json_encode($row));

        $communityMember = Member::find($row['member_id']); // we've checked if `community_member_id` exists during save the `notifications_sender`

        if ($communityMember!= null && isset($communityMember->mobile) && !empty($communityMember->mobile)) {
            $phoneNo = '+1' . $communityMember->mobile;

            $text = $row['text'];

            $send_sms = $this->send_sms($phoneNo, $text, $row['id']);
            if ($send_sms['status']) {
                $response = [
                    'status' => 'success',
                    'code' => 200,
                    'type' => 'smsSend',
                    'message' => 'SMS Sent',
                ];

                Log::info('SMS sent successfully.');
                $this->sentSuccessfully($row);

                return response()->json($response);
            } else {
                $response = [
                    'status' => 'error',
                    'code' => 400,
                    'type' => 'smsSend',
                    'twilio_error_code' => $send_sms['error_code'],
                    'message' => $send_sms['message'],
                ];
                Log::error('Unable to send SMS.');
                $retry = !in_array($send_sms['error_code'], $this->twilio_error_codes);
                if (!$retry) {
                    //it failed due to invalid no or blacklisted from twilio see twilio_error_codes details
                    Log::info("updating text_opt_in as false as error code is " . $send_sms['error_code'] . ', Which is not retryable');
                    $communityMember->text_opt_in = 0;
                    $communityMember->twilio_error_code = $send_sms['error_code'];
                    $communityMember->save();
                }
                $this->unableToSendNotification($row, $response, $retry);

                return response()->json($response);
            }
        } else {
            $response = [
                'status' => 'error',
                'code' => 400,
                'type' => 'smsSend',
                'phone_no' => ($communityMember!= null && isset($communityMember->mobile)) ? $communityMember->mobile : null,
                'message' => 'Invalid phone no',
            ];
            Log::error('Invalid No, Unable to send SMS.');
            // Log::info("updating text_opt_in as false");
            // $communityMember->text_opt_in = 0;
            // $communityMember->save();
            $this->unableToSendNotification($row, $response, false);

            return response()->json($response);
        }
    }

    private function processCall($row)
    {
        Log::info('Processing Call for ' . json_encode($row));

        $communityMember = Member::find($row['member_id']);

        if ($communityMember!= null && isset($communityMember->mobile) && !empty($communityMember->mobile)) {
            $initiate_call = $this->initiate_call($communityMember->mobile, $row['id'], $this->twilioFromNo);
            if ($initiate_call['status']) {
                $response = [
                    'status' => 'success',
                    'code' => 200,
                    'type' => 'call',
                    'message' => 'Call Initiated',
                ];

                Log::info('Call Initiated successfully.');
                $this->sentSuccessfully($row);

                return response()->json($response);
            } else {
                $response = [
                    'status' => 'error',
                    'code' => 400,
                    'type' => 'call',
                    'twilio_error_code' => $initiate_call['error_code'],
                    'message' => $initiate_call ['message'],
                ];
                Log::info('Unable to initiate Call.');
                $retry = !in_array($initiate_call['error_code'], $this->twilio_error_codes);
                if (!$retry) {
                    //it failed due to invalid no or blacklisted from twilio see twilio_error_codes details
                    Log::info("updating call_opt_in as false");
                    $communityMember->call_opt_in = 0;
                    $communityMember->twilio_error_code = $initiate_call['error_code'];
                    $communityMember->save();
                }
                $this->unableToSendNotification($row, $response, $retry);

                return response()->json($response);
            }
        } else {
            $response = [
                'status' => 'error',
                'code' => 400,
                'type' => 'call',
                'phone_no' => ($communityMember!= null && isset($communityMember->mobile)) ? $communityMember->mobile : null,
                'message' => 'Invalid phone no',
            ];
            Log::info('Invalid phone no, Unable to initiate Call.');
            // Log::info("updating call_opt_in as false");
            // $communityMember->call_opt_in = 0;
            $communityMember->save();
            $this->unableToSendNotification($row, $response, false);

            return response()->json($response);
        }
    }

    private function unableToSendNotification($notificationSender, $response, $retry=true)
    {
        $response = (array)$response;
        Log::debug('UnableToSendNotification curl response: ', $response);

        //update response to db and increment tried count if tried is 5 then update process status as failed and sent a mail
        $incrementedTriedCount = $notificationSender->tried + 1;
        $notificationSender->error_code = $response['twilio_error_code'] ?? null;
        $notificationSender->process_response = ($notificationSender->process_response != null) ? $notificationSender->process_response . ', ' . json_encode($response) : json_encode($response);
        $notificationSender->process_date = date('Y-m-d H:i:s');
        $notificationSender->tried = $incrementedTriedCount;

        if (!$retry || $incrementedTriedCount >= env('NOTIFICATION_MAX_RETRY', 3)) {
            //if updated as failed it will not be tried again
            $notificationSender->process_status = 'failed';
            Log::info("unableToSendNotification after NOTIFICATION_MAX_RETRY notification type: " . $notificationSender->type);
        } else {
            //re dispatch with one mnt delay.
            Log::debug('Re dispatching the job with 5 minutes delay, notification sender id: ' . $this->notificationSenderId);
            dispatch(new SendNotifcation($this->notificationSenderId))->delay(now()->addSeconds(500));
        }
        $notificationSender->save();
    }

    private function sentSuccessfully($notificationSender)
    {
        //update status in db
        if ($notificationSender->tried != null) {
            $incrementedTriedCount = $notificationSender->tried + 1;
        } else {
            $incrementedTriedCount = 1;
        }
        $notificationSender->process_status = 'sent';
        $notificationSender->process_response = ($notificationSender->process_response != null) ? $notificationSender->process_response . ', ' . 'Sent successfully' : 'Sent successfully';
        $notificationSender->tried = $incrementedTriedCount;
        $notificationSender->process_date = date('Y-m-d H:i:s');
        $notificationSender->save();
    }
}
