<?php

namespace App\Jobs;

use App\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\SendNotifcation;
use App\Language;
use App\Member;
use App\NotificationSender;
use App\Traits\Translate;
use App\Translation;
use Illuminate\Support\Facades\Log;

class GenerateNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use Translate;

    protected $communityNotifications;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($communityNotifications)
    {
        $this->communityNotifications = $communityNotifications;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        foreach ($this->communityNotifications as $communityNotification) {
            $members = $this->getAllMembers($communityNotification);

            if ($members->count() > 0) {
                // get members whose text_opt_in is true
                $members_text_opt_in = $members->filter(function ($value) {
                    return $value['text_opt_in'] == 1 || $value['call_opt_in'] == 1;
                })->all();
                logger()->info('Creating notification sender for ' . count($members_text_opt_in) . ' member to send sms or call');
                $this->createNotificationSenderForTextSMSORCall($members_text_opt_in, $communityNotification);
            } else {
                logger()->info('Not member found to generate notification.');
            }

            $communityNotification->status = 'notification_sender_created';
            $communityNotification->save();
        }
    }

    public function getAllMembers($communityNotification)
    {
        $q = Member::select('*');
        if (!empty($communityNotification->community_member_ids) || $communityNotification->community_member_ids != null) {
            //get only these members
            $q->whereIn('id', array_filter(explode(',', $communityNotification->community_member_ids)));
        } else {
            //get all members in that community
            $q->where('user_id', $communityNotification->user_id);
        }
        // logger()->info(json_encode($q->toSql()));

        return $q->get();
    }

    public function createNotificationSenderForTextSMSORCall($members, Notification $communityNotification)
    {
        $translationQuery = Translation::where('notification_id', $communityNotification->id);
        
        // Create text notificaton only once for a member
        $notificationSendCounter = 0;
        foreach ($members as $member) {
            $insert = [
                'notification_type' => 'admin_notification',
                'notification_id' => $communityNotification->id,
                'member_id' => $member['id'],
            ];

            //get right tranlation
            $translationCheck = $translationQuery->where('language', $member['language'])->first();
            if ($translationCheck != null) {
                //translation available.
                $notificationText = $translationCheck->translation;
            } else {
                //translate
                $translation = $this->translateFromAWS($communityNotification->text, $member['language']);
                if ($translation) {
                    Translation::insert([
                        'notification_id' => $communityNotification->id,
                        'language' => $member['language'],
                        'translation' => $translation['TranslatedText']
                    ]);
                    $notificationText = $translation['TranslatedText'];
                } else {
                    Log::error("unable to translate");
                    $notificationText = $communityNotification->text;
                }
            }

            // remove everything except latters. . , numbers, special characters.
            // TODO should apply validation on notification save to filter out special characters. 
            // $notificationText = preg_replace("/[^a-zA-Z\s]/", "", $notificationText) . ".";
            if (ctype_upper(str_replace(' ', '', $notificationText))) {
                //if ALL CAPS convert to Title Caps
                Log::info("Found text is in ALL CAPS, Converting to Title Caps");
                $notificationText = ucwords(strtolower($notificationText));
            }

            $language = Language::where('short_name', $member['language'])->first();
            // check if stop message avaialble in languages by language code 
            $stopMessage = $language->stop_message;

            //if audio_id available add audio url 
            if (!empty($communityNotification->audio_id)) {
                $notificationText = $notificationText . " " . $language->click_to_listen . " " . url('audio'. '/' . $communityNotification->audio_id) . '.';
            }

            // check character count
            $textLength = strlen($notificationText);
            $longMessageTemplate = $language->long_message_template;
            if ($textLength >= 128) {
                //create sms with guid and send url to sms
                $notificationText = str_replace("{{NotificationFrom}}", $communityNotification->user->name, $longMessageTemplate) . url('msg/' . $communityNotification->code . '/' . simpleEncryptID($member['id'])) . '.';
            }

            $notificationText = $notificationText . " " . $stopMessage;
            //$member['twilio_lookup_line_type'] == 'mobile' checking if this no can receive text via twilio look up api
            // related code in MemberObserver and Twillio Traits lookup function and DB Twilio lookup two fields in member table

            $tester_no = in_array($member['mobile'], explode(',', env('TEST_NO_WHICH_IS_ALWAYS_VALID', '')));

            Log::info($member['mobile'] . ' is tester no: ' . $tester_no);

            if (($member['text_opt_in'] == 1 && $member['twilio_lookup_line_type'] == 'mobile') || $tester_no) {
                $insert['type'] = 'text';
                $insert['text'] = $notificationText;
                $notificationSenderText = NotificationSender::create($insert);

                if ($notificationSenderText !== null) {
                    // https://www.twilio.com/docs/api/errors/20429
                    // can handle 300 dispatch as amazon sns has 15 delay limit  15 * 60 = 900 / 3 = 300
                    dispatch(new SendNotifcation($notificationSenderText->id))->delay(now()->addSeconds($notificationSendCounter * 5));
                    $notificationSendCounter++;
                }
            }
            if ($member['twilio_lookup_line_type'] != 'mobile' && !$tester_no) {
                $msg = 'Not creating text notification and updating as text opt out for member_id: ' . $member['id'] . ', mobile: ' . $member['mobile'] . ' as line type not mobile (might not support text/sms), line type is: ' . $member['twilio_lookup_line_type'];
                Log::info($msg);
                $member->text_opt_in_reason = "Invalid number. Please make sure that this is a working cell phone number.";
                $member->text_opt_in = 0;
                $member->save();
                Log::info($msg);
            }

            if ($member['call_opt_in'] == 1) {
                $insert['type'] = 'call';
                $insert['text'] = $this->callNotificationFormat($communityNotification->text);
                $notificationSenderCall = NotificationSender::create($insert);

                if ($notificationSenderCall !== null) {
                    // https://www.twilio.com/docs/api/errors/20429
                    // can handle 300 dispatch as amazon sns has 15 delay limit  15 * 60 = 900 / 3 = 300
                    dispatch(new SendNotifcation($notificationSenderCall->id))->delay(now()->addSeconds($notificationSendCounter * 5));
                    $notificationSendCounter++;
                }
            }
        }
    }

    private function callNotificationFormat($notification)
    {
        //replace url
        $replaced_url = preg_replace('"\b(https?://\S+)"', 'URL Link', $notification);
        //take first 500 characters
        $truncate = substr($replaced_url, 0, 500);

        $final = 'Hello.  This is Community Hub and I have a message I want to share with you.  Here is the message.  Please listen carefully. ' . $truncate;

        return $final;
    }
}
