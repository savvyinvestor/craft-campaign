<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\services;

use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use craft\queue\Queue;
use craft\records\Element_SiteSettings;
use craft\web\View;
use DateTime;
use DOMDocument;
use DOMElement;
use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\elements\ContactElement;
use putyourlightson\campaign\elements\SendoutElement;
use putyourlightson\campaign\events\SendoutEmailEvent;
use putyourlightson\campaign\jobs\SendoutJob;
use putyourlightson\campaign\models\AutomatedScheduleModel;
use putyourlightson\campaign\records\ContactCampaignRecord;
use putyourlightson\campaign\records\ContactMailingListRecord;
use putyourlightson\campaign\records\ContactRecord;
use putyourlightson\campaign\records\LinkRecord;

use Craft;
use craft\base\Component;
use craft\errors\ElementNotFoundException;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\mail\Mailer;
use DateTimeZone;
use putyourlightson\campaign\elements\MailingListElement;
use putyourlightson\campaign\records\SendoutRecord;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\db\ActiveQuery;


/**
 * SendoutsService
 *
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.0.0
 *
 * @property Mailer $mailer
 */
class SendoutsService extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event SendoutEvent
     */
    const EVENT_BEFORE_SEND = 'beforeSend';

    /**
     * @event SendoutEvent
     */
    const EVENT_AFTER_SEND = 'afterSend';

    /**
     * @event SendoutEmailEvent
     */
    const EVENT_BEFORE_SEND_EMAIL = 'beforeSendEmail';

    /**
     * @event SendoutEmailEvent
     */
    const EVENT_AFTER_SEND_EMAIL = 'afterSendEmail';

    /**
     * 
     */
    const GB_TIMEZONE = 'Europe/London';

    // Properties
    // =========================================================================

    /**
     * @var array
     */
    private $_links = [];

    // Public Methods
    // =========================================================================

    /**
     * Returns sendout by ID
     *
     * @param int $sendoutId
     *
     * @return SendoutElement|null
     */
    public function getSendoutById(int $sendoutId)
    {
        // Get site ID from element site settings
        $siteId = Element_SiteSettings::find()
            ->select('siteId')
            ->where(['elementId' => $sendoutId])
            ->scalar();

        if ($siteId === null) {
            return null;
        }

        $sendout = SendoutElement::find()
            ->id($sendoutId)
            ->siteId($siteId)
            ->status(null)
            ->one();

        return $sendout;
    }

    /**
     * Returns sendout by SID
     *
     * @param string $sid
     *
     * @return SendoutElement|null
     */
    public function getSendoutBySid(string $sid)
    {
        if (!$sid) {
            return null;
        }

        $sendoutId = SendoutRecord::find()
            ->select('id')
            ->where(['sid' => $sid])
            ->scalar();

        if ($sendoutId === null) {
            return null;
        }

        return $this->getSendoutById($sendoutId);
    }

    /**
     * Returns sendout send status by ID
     *
     * @param int $sendoutId
     *
     * @return string|null
     */
    public function getSendoutSendStatusById(int $sendoutId)
    {
        $sendStatus = SendoutRecord::find()
            ->select('sendStatus')
            ->where(['id' => $sendoutId])
            ->scalar();

        return $sendStatus;
    }

    /**
     * Returns the sendout's pending contact and mailing list IDs based on its mailing lists, segments and schedule
     *
     * @param SendoutElement $sendout
     *
     * @return array
     */
    public function getPendingRecipients(SendoutElement $sendout): array
    {
        // Call for max power
        Campaign::$plugin->maxPowerLieutenant();

        $baseCondition = [
            'mailingListId' => $sendout->getMailingListIds(),
            'subscriptionStatus' => 'subscribed',
        ];

        // Get contacts subscribed to sendout's mailing lists
        $query = ContactMailingListRecord::find()
            ->select(['contactId', 'min([[mailingListId]]) as mailingListId', 'min([[subscribed]]) as subscribed'])
            ->groupBy('contactId')
            ->where($baseCondition);

        // Ensure contacts have not complained or bounced (in contact record)
        $query->innerJoin(ContactRecord::tableName().' contact', '[[contact.id]] = [[contactId]]')
            ->andWhere([
                'contact.complained' => null,
                'contact.bounced' => null,
            ]);

        // Exclude contacts subscribed to sendout's excluded mailing lists
        $query->andWhere(['not', ['contactId' => $this->_getExcludedMailingListRecipientsQuery($sendout)]]);

        // Check whether we should exclude recipients that were sent to today only
        $excludeSentTodayOnly = $sendout->sendoutType == 'recurring' && $sendout->schedule->canSendToContactsMultipleTimes;

        // Exclude sent recipients
        $query->andWhere(['not', ['contactId' => $this->_getSentRecipientsQuery($sendout, $excludeSentTodayOnly)]]);

        // Get contact IDs
        $contactIds = $query->column();

        // Filter recipients by segments
        if ($sendout->segmentIds) {
            foreach ($sendout->getSegments() as $segment) {
                $contactIds = Campaign::$plugin->segments->getFilteredContactIds($segment, $contactIds);
            }
        }

        // Get recipients as array
        $recipients = ContactMailingListRecord::find()
            ->select(['contactId', 'min([[mailingListId]]) as mailingListId', 'min([[subscribed]]) as subscribed'])
            ->groupBy('contactId')
            ->where($baseCondition)
            ->andWhere(['contactId' => $contactIds])
            ->asArray()
            ->all();

        if ($sendout->sendoutType == 'automated') {
            /** @var AutomatedScheduleModel $automatedSchedule */
            $automatedSchedule = $sendout->schedule;

            // Remove any contacts that do not meet the conditions
            foreach ($recipients as $key => $recipient) {
                $subscribedDateTime = DateTimeHelper::toDateTime($recipient['subscribed']);
                $subscribedDateTimePlusDelay = $subscribedDateTime->modify('+'.$automatedSchedule->timeDelay.' '.$automatedSchedule->timeDelayInterval);

                // If subscribed date was before sendout was created or time plus delay has not yet passed
                if ($subscribedDateTime < $sendout->dateCreated || !DateTimeHelper::isInThePast($subscribedDateTimePlusDelay)) {
                    unset($recipients[$key]);
                }
            }
        }

        return $recipients;
    }

    /**
     * 
     */
    public function convertSecondsToHours(int $seconds): int
    {
        $hours = 0;

        if (is_numeric($seconds))
        {
            $hours = $seconds / 3600;
        }

        return $hours;

    }

    /**
     * 
     */
    public function createMailingListForTimezone(string $title, array $recipientTimezones, string $timezone): MailingListElement
    {
        $mailingList = new MailingListElement();

        $mailingList->mailingListTypeId = 1;
        $mailingList->title = $title . ': ' . $timezone . ' sent: ' . date("Y-m-d H:i:s");
        $mailingList->slug = $title . ': ' . $timezone . ' sent: ' . date("Y-m-d H:i:s");
        $mailingList->enabled = true;
        //$mailingList->fieldLayoutId = '';

        if (!Craft::$app->getElements()->saveElement($mailingList)) {
            echo 'Could not save mailing list.';
        }

        // Add contacts to mailing list
        $contactsForTimezone = $this->getContactsForTimezone($recipientTimezones, $timezone);

        foreach($contactsForTimezone as $contact)
        {
            $this->subscribeContactToMailingList($contact, $mailingList);
        }

        if (!Craft::$app->getElements()->saveElement($mailingList)) {
            echo 'Could not save mailing list.';
        }
 
        return $mailingList ;
    }

    private function subscribeContactToMailingList(ContactElement $contact, MailingListElement $mailingList)
    {
        $subscriptionStatus = 'subscribed';
        /** @var User|null $currentUser */
        $currentUser = Craft::$app->getUser()->getIdentity();
        $currentUserId = $currentUser ? $currentUser->id : '';
        Campaign::$plugin->mailingLists->addContactInteraction($contact, $mailingList, $subscriptionStatus, 'user', $currentUserId);
    }

    public function getContactsForTimezone(array $recipientTimezones, string $timezone): array
    {
        $contacts = array();

        if (!empty($recipientTimezones) && sizeof($recipientTimezones) > 0){
            foreach($recipientTimezones as $recipientTimezone)
            {
                if($recipientTimezone['timezone'] == $timezone){
                    if(!in_array($recipientTimezone['email'], $contacts)){
                        $contact = Campaign::$plugin->contacts->getContactByEmail($recipientTimezone['email']);
                        array_push($contacts, $contact);
                    }
                }
            }
        }

        return $contacts;
    }

    private function uniqueTimezonesForSendout(array $recipientTimezones): array
    {

        $uniqueTimezones = array();

        if (!empty($recipientTimezones) && sizeof($recipientTimezones) > 0){

            foreach($recipientTimezones as $recipientTimezone)
            {
                $tz = $recipientTimezone['timezone'];

                if(!in_array($tz, $uniqueTimezones)){
                    array_push($uniqueTimezones, $tz);
                }
                
            }
        }
        
        return $uniqueTimezones;

    }

    public function calculateDateTimeForTimezone(string $timezone, DateTime $sendDate): DateTime
    {

        $gmtTimezone = new \DateTimeZone('GMT');
        $userTimezone = new \DateTimeZone($timezone);
        $sendDateTime = new DateTime($sendDate->format('Y-m-d H:i:s'), $gmtTimezone);
        $offset = $userTimezone->getOffset($sendDateTime);
        $dst = date('I');

        // Factor for day light savings
        if($dst == 1){
            $offset -= 3600;
        }
    
        $myInterval = \DateInterval::createFromDateString((string) abs($offset) . 'seconds');
        $sendDateTime->add($myInterval);

        return $sendDateTime;
       
    }

    /**
     * Takes a sendout and splits it into multiple sendouts based on each timezone
     * 
     * @param SendoutElement $sendout
     * 
     * @return array
     */
    public function createSendoutsByTimezone(SendoutElement $sendout): array
    {
        $sendoutsByTimezone = array();

        // All recipients and their timezones
        $recipientTimezones = $this->getRecipientTimezones($sendout);

        // timezones we need to send to
        $timezonesUnique = $this->uniqueTimezonesForSendout($recipientTimezones);

        // For each timezone, create a separate sendout, work out the time difference for each and set that as the send date for the sendout
        $count = 0;

        foreach($timezonesUnique as $timezone)
        {

            // Adjust the sendDate based on $timezone
            $sendDateForTimeZone = $this->calculateDateTimeForTimezone($timezone, $sendout->sendDate);

            echo $timezone . ': ' . $sendDateForTimeZone->format('Y-m-d H:i:s') . "\n";

            if($this->canSendScheduleForTimezoneNow($sendDateForTimeZone)){

                $sendout->sendDate = $sendDateForTimeZone;

                // Create a new mailing list for the timezone / contacts
                $mailingListForTimezone = $this->createMailingListForTimezone($sendout->campaign->title, $recipientTimezones, $timezone);
    
                // Remove existing mail lists
                $sendout->mailingListIds = null;
    
                // Add mail list created for timezone
                $sendout->mailingListIds = $mailingListForTimezone->id;
    
                // Clone the sendout
                ${'sendout' . $count} = clone $sendout;

                array_push($sendoutsByTimezone, ${'sendout' . $count});
            }
           
            $count++;
        }

    //  print_r(sizeof($sendoutsByTimezone));
    //  exit;
        return $sendoutsByTimezone;
    }

        /**
     * @inheritdoc
     */
    public function canSendScheduleForTimezoneNow(DateTime $sendTime): bool
    {
        // Ensure send date is in the past
        // if (!DateTimeHelper::isInThePast($sendTime)) {
        //     return false;
        // }
        $now = new DateTime();
        $format = 'Y-m-d H:i:s';
        $nowTime = strtotime($now->format($format));
        $sendTime = strtotime($sendTime->format($format));

        if($sendTime > $nowTime )
        {
            return false;
        }

        // Ensure time of day has past
        // if ($sendTime !== null) {
        //     $now = new DateTime();
        //     $format = 'H:i';

        //     if ($sendTime->format($format) > $now->format($format)) {
        //         return false;
        //     }
        // }
        return true;
    }

    /**
     * Takes a sendout and returns an associative array of timezone : user email
     * 
     * @param SendoutElement $sendout
     * 
     * @return array
     */
    public function getRecipientTimezones(SendoutElement $sendout): array
    {
        $recipientTimezones = array();

        $mailingLists = $sendout->getMailingLists();
       
        foreach($mailingLists as $mailingList)
        {
            $subscribedContacts = $mailingList->subscribedContacts;

            foreach($subscribedContacts as $contact)
            {
           
                if(!empty($contact->userTimeZone->value))
                {
                    $timezone = $contact->userTimeZone->value;
                    
                    $recipientTimezones[] = ['timezone' => $timezone, 'email' => $contact->email];
                   
                }else{

                //    $recipientTimezones += [self::GB_TIMEZONE => $contact->email];
                    $recipientTimezones[] = ['timezone' => self::GB_TIMEZONE , 'email' => $contact->email];
                }
                  
            }

        }
 
        return $recipientTimezones;

    } 

    private function processSendout(SendoutElement $sendout, int $count): int 
    {

            // Queue regular and scheduled sendouts, automated and recurring sendouts if pro version and the sendout can send now
            if ($sendout->sendoutType == 'regular' 
            || (($sendout->sendoutType == 'automated' || $sendout->sendoutType == 'recurring')
                && Campaign::$plugin->getIsPro() && $sendout->getCanSendNow()
                )
            ) 
            {
                /** @var Queue $queue */
                $queue = Craft::$app->getQueue();

                // Add sendout job to queue
                $queue->push(new SendoutJob([
                    'sendoutId' => $sendout->id,
                    'title' => $sendout->title,
                ]));

                $sendout->sendStatus = SendoutElement::STATUS_QUEUED;

                $this->_updateSendoutRecord($sendout, ['sendStatus']);

                $count++;
            }

            return $count;

    }

    private function processScheduledSendouts(SendoutElement $sendout, int $count): int
    {

        $mailingListIds = array();

        // Split the sendout into separate sendouts by timezone
        $sendoutsByTimezone = $this->createSendoutsByTimezone($sendout);

           foreach($sendoutsByTimezone as $sendoutByTimezone){

                // Build a list of mailing list ids to be cleaned up
                array_push($mailingListIds, $sendoutByTimezone->mailingListIds); 

               // Queue scheduled sendouts if pro version and the sendOutDate has passed
               if (Campaign::$plugin->getIsPro())
                {

                    /** @var Queue $queue */
                    $queue = Craft::$app->getQueue();

                    // Add sendout job to queue
                       $queue->push(new SendoutJob([
                           'sendoutId' => $sendoutByTimezone->id,
                           'title' => $sendoutByTimezone->title,
                       ]));

                    $sendoutByTimezone->sendStatus = SendoutElement::STATUS_QUEUED;
   
                    $this->_updateSendoutRecord($sendoutByTimezone, ['sendStatus']);
                    
                    $count++;
               }

           }

           // Remove temporary mailing lists
            $this->cleanupTemporaryMailingLists($mailingListIds);

           return $count;
    }

    private function cleanupTemporaryMailingLists(array $mailingListIds)
    {

        if(isset($mailingListIds) && sizeof($mailingListIds) > 0){
            foreach ($mailingListIds as $mailingListId){
                $mailingList = Campaign::$plugin->mailingLists->getMailingListById($mailingListId);
                
                if($mailingList != null){
                    if (!Craft::$app->getElements()->deleteElement($mailingList)) {
                        echo 'Attempt to delete mailing list: ' . $mailingList->id . ' failed.'; 
                    }
                }
              
            }
        }
      

    }

    /**
     * Queues pending sendouts
     *
     * @return int
     * @throws Throwable
     */
    public function queuePendingSendouts(): int
    {
        $count = 0;
        $now = new DateTime();

        // Get sites to loop through so we can ensure that we get all sendouts
        $sites = Craft::$app->getSites()->getAllSites();

        foreach ($sites as $site) {
            
            // Find pending sendouts whose send date is in the past
            $sendouts = SendoutElement::find()
                ->site($site)
                ->status(SendoutElement::STATUS_PENDING)
                ->where(Db::parseDateParam('sendDate', $now, '<='))
                ->all();
//echo sizeof($sendouts); die();
            /** @var SendoutElement $sendout */
            foreach ($sendouts as $sendout) {

                switch($sendout->sendoutType)
                {
                    case 'regular':
                        $this->processSendout($sendout, $count);
                        break;
                    case 'automated':
                        $this->processSendout($sendout, $count);
                        break;
                    case 'recurring':
                        $this->processSendout($sendout, $count);
                        break;
                    case 'scheduled':
                        $this->processScheduledSendouts($sendout, $count);
                        break;
                    default:
                        echo 'Sendout type did not match. Campaign did not run.';         
                
                }
             }
            }

        return $count;
    }

    /**
     * Sends a test
     *
     * @param SendoutElement $sendout
     * @param ContactElement $contact
     *
     * @return bool Whether the test was sent successfully
     * @throws Exception
     * @throws InvalidConfigException
     */
    public function sendTest(SendoutElement $sendout, ContactElement $contact): bool
    {
        // Get campaign
        $campaign = $sendout->getCampaign();

        if ($campaign === null) {
            return false;
        }

        // Set the current site from the sendout's site ID
        Craft::$app->sites->setCurrentSite($sendout->siteId);

        // Get subject
        $subject = Craft::$app->getView()->renderString($sendout->subject, ['contact' => $contact]);

        // Get body
        $htmlBody = $campaign->getHtmlBody($contact, $sendout);
        $plaintextBody = $campaign->getPlaintextBody($contact, $sendout);

        // Convert links in HTML body
        $htmlBody = $this->_convertLinks($htmlBody, $contact, $sendout);

        // Compose message
        $message = Campaign::$plugin->mailer->compose()
            ->setFrom([$sendout->fromEmail => $sendout->fromName])
            ->setTo($contact->email)
            ->setSubject('[Test] '.$subject)
            ->setHtmlBody($htmlBody)
            ->setTextBody($plaintextBody);

        if ($sendout->replyToEmail) {
            $message->setReplyTo($sendout->replyToEmail);
        }

        return $message->send();
    }

    /**
     * Sends an email
     *
     * @param SendoutElement $sendout
     * @param ContactElement $contact
     * @param int $mailingListId
     *
     * @throws Throwable
     * @throws Exception
     */
    public function sendEmail(SendoutElement $sendout, ContactElement $contact, int $mailingListId)
    {
        if ($sendout->getIsSendable() === false) {
            return;
        }

        // Return if contact has complained or bounced
        if ($contact->complained !== null || $contact->bounced !== null) {
            return;
        }

        // Get campaign
        $campaign = $sendout->getCampaign();

        if ($campaign === null) {
            return;
        }

        /** @var ContactCampaignRecord|null $contactCampaignRecord */
        $contactCampaignRecord = ContactCampaignRecord::find()
            ->where([
                'contactId' => $contact->id,
                'sendoutId' => $sendout->id,
            ])
            ->one();

        if ($contactCampaignRecord === null) {
            $contactCampaignRecord = new ContactCampaignRecord();
            $contactCampaignRecord->contactId = $contact->id;
            $contactCampaignRecord->sendoutId = $sendout->id;
        }
        else if ($contactCampaignRecord->sent !== null) {
            // Ensure this is a recurring sendout that can be sent to contacts multiple times
            if (!($sendout->sendoutType == 'recurring' && $sendout->schedule->canSendToContactsMultipleTimes)) {
                return;
            }

            $now = new DateTime();

            // Ensure not already sent today
            if ($contactCampaignRecord->sent !== null && $contactCampaignRecord->sent > $now->format('Y-m-d')) {
                return;
            }
        }

        $contactCampaignRecord->campaignId = $campaign->id;
        $contactCampaignRecord->mailingListId = $mailingListId;

        // Get subject
        $subject = Craft::$app->getView()->renderString($sendout->subject, ['contact' => $contact]);

        // Get body
        $htmlBody = $campaign->getHtmlBody($contact, $sendout);
        $plaintextBody = $campaign->getPlaintextBody($contact, $sendout);

        // Convert links in HTML body
        $htmlBody = $this->_convertLinks($htmlBody, $contact, $sendout);

        // Add tracking image to HTML body
        $path = Craft::$app->getConfig()->getGeneral()->actionTrigger.'/campaign/t/open';
        $trackingImageUrl = UrlHelper::siteUrl($path, ['cid' => $contact->cid, 'sid' => $sendout->sid]);
        $htmlBody .= '<img src="'.$trackingImageUrl.'" width="1" height="1" />';

        // If test mode is enabled then use file transport instead of sending emails
        if (Campaign::$plugin->getSettings()->testMode) {
            Campaign::$plugin->mailer->useFileTransport = true;
        }

        // Create message
        $message = Campaign::$plugin->mailer->compose()
            ->setFrom([$sendout->fromEmail => $sendout->fromName])
            ->setTo($contact->email)
            ->setSubject($subject)
            ->setHtmlBody($htmlBody)
            ->setTextBody($plaintextBody);

        if ($sendout->replyToEmail) {
            $message->setReplyTo($sendout->replyToEmail);
        }

        // Fire a before event
        $event = new SendoutEmailEvent([
            'sendout' => $sendout,
            'contact' => $contact,
            'message' => $message,
        ]);
        $this->trigger(self::EVENT_BEFORE_SEND_EMAIL, $event);

        if (!$event->isValid) {
            return;
        }

        $success = false;

        // Attempt to send message
        for ($i = 0; $i < Campaign::$plugin->getSettings()->maxSendAttempts; $i++) {
            $success = Campaign::$plugin->mailer->send($message);

            if ($success) {
                break;
            }

            // Just wait a second, in case we're being throttled
            sleep(1);
        }

        if ($success) {
            // Update sent date and save
            $contactCampaignRecord->sent = new DateTime();
            $contactCampaignRecord->save();

            // Update recipients and last sent
            $sendout->recipients++;
            $sendout->lastSent = new DateTime();

            $this->_updateSendoutRecord($sendout, ['recipients', 'lastSent']);
        }
        else {
            // Update fails and send status
            $sendout->fails++;

            if ($sendout->fails >= Campaign::$plugin->getSettings()->maxSendFailsAllowed) {
                $sendout->sendStatus = SendoutElement::STATUS_FAILED;
            }

            $this->_updateSendoutRecord($sendout, ['fails', 'sendStatus']);

            Campaign::$plugin->log('Sending of the sendout "{title}" to {email} failed after {sendAttempts} send attempt(s). Please check that your Campaign email settings are correctly configured and check the error in the Craft log.', [
                'title' => $sendout->title,
                'email' => $contact->email,
                'sendAttempts' => Campaign::$plugin->getSettings()->maxSendAttempts,
            ]);
        }

        // Fire an after event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SEND_EMAIL)) {
            $this->trigger(self::EVENT_AFTER_SEND_EMAIL, new SendoutEmailEvent([
                'sendout' => $sendout,
                'contact' => $contact,
                'message' => $message,
                'success' => $success,
            ]));
        }
    }

    /**
     * Sends a notification
     *
     * @param SendoutElement $sendout
     */
    public function sendNotification(SendoutElement $sendout)
    {
        if (!$sendout->notificationEmailAddress) {
            return;
        }

        if ($sendout->sendStatus != SendoutElement::STATUS_SENT &&
            $sendout->sendStatus != SendoutElement::STATUS_FAILED
        ) {
            return;
        }

        $variables = [
            'title' => $sendout->title,
            'emailSettingsUrl' => UrlHelper::cpUrl('campaign/settings/email'),
            'sendoutUrl' => $sendout->cpEditUrl,
            'sendAttempts' => Campaign::$plugin->getSettings()->maxSendAttempts,
        ];

        if ($sendout->sendStatus == SendoutElement::STATUS_SENT) {
            $subject = Craft::t('campaign', 'Sending completed: {title}', $variables);
            $htmlBody = Craft::t('campaign', 'Sending of the sendout "<a href="{sendoutUrl}">{title}</a>" has been successfully completed!!', $variables);
            $plaintextBody = Craft::t('campaign', 'Sending of the sendout "{title}" [{sendoutUrl}] has been successfully completed!!', $variables);
        }
        else {
            $subject = Craft::t('campaign', 'Sending failed: {title}', $variables);
            $htmlBody = Craft::t('campaign', 'Sending of the sendout "<a href="{sendoutUrl}">{title}</a>" failed after {sendAttempts} send attempt(s). Please check that your <a href="{emailSettingsUrl}">Campaign email settings</a> are correctly configured and check the error in the Craft log.', $variables);
            $plaintextBody = Craft::t('campaign', 'Sending of the sendout "{title}" [{sendoutUrl}] failed after {sendAttempts} send attempt(s). Please check that your Campaign email settings [{emailSettingsUrl}] are correctly configured and check the error in the Craft log.', $variables);
        }

        // Compose message
        $message = Campaign::$plugin->mailer->compose()
            ->setFrom([$sendout->fromEmail => $sendout->fromName])
            ->setTo($sendout->notificationEmailAddress)
            ->setSubject($subject)
            ->setHtmlBody($htmlBody)
            ->setTextBody($plaintextBody);

        $message->send();
    }

    /**
     * Prepare sending
     *
     * @param SendoutElement $sendout
     *
     * @throws Throwable
     */
    public function prepareSending(SendoutElement $sendout)
    {
        if ($sendout->sendStatus !== SendoutElement::STATUS_SENDING) {
            $sendout->sendStatus = SendoutElement::STATUS_SENDING;

            $this->_updateSendoutRecord($sendout, ['sendStatus']);
        }

        // Set the current site from the sendout's site ID
        Craft::$app->getSites()->setCurrentSite($sendout->siteId);

        // Set template mode to site
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_SITE);
    }

    /**
     * Finalise sending
     *
     * @param SendoutElement $sendout
     *
     * @throws ElementNotFoundException
     * @throws Exception
     * @throws Throwable
     */
    public function finaliseSending(SendoutElement $sendout)
    {
        // Change sending status to sent
        if ($sendout->sendStatus == SendoutElement::STATUS_SENDING) {
            $sendout->sendStatus = SendoutElement::STATUS_SENT;
        }

        // Update send status to pending if automated or recurring or not fully complete
        if ($sendout->sendoutType == 'automated' ||
            $sendout->sendoutType == 'recurring' ||
            count($this->getPendingRecipients($sendout)) > 0
        ) {
            $sendout->sendStatus = SendoutElement::STATUS_PENDING;
        }

        // Get campaign
        $campaign = $sendout->getCampaign();

        if ($campaign !== null) {
            // Update HTML and plaintext body
            $contact = new ContactElement();
            $sendout->htmlBody = $campaign->getHtmlBody($contact, $sendout);
            $sendout->plaintextBody = $campaign->getPlaintextBody($contact, $sendout);

            if (Craft::$app->getDb()->getIsMysql()) {
                // Encode any 4-byte UTF-8 characters
                $sendout->htmlBody = StringHelper::encodeMb4($sendout->htmlBody);
                $sendout->plaintextBody = StringHelper::encodeMb4($sendout->plaintextBody);
            }
        }

        $this->_updateSendoutRecord($sendout, ['sendStatus', 'htmlBody', 'plaintextBody']);

        // Update campaign recipients
        $recipients = ContactCampaignRecord::find()
            ->where(['campaignId' => $campaign->id])
            ->count();

        $campaign->recipients = $recipients;

        Craft::$app->getElements()->saveElement($campaign);

        // Send notification email
        $this->sendNotification($sendout);
    }

    /**
     * Pauses a sendout
     *
     * @param SendoutElement $sendout
     *
     * @return bool Whether the action was successful
     * @throws Throwable
     */
    public function pauseSendout(SendoutElement $sendout): bool
    {
        if (!$sendout->getIsPausable()) {
            return false;
        }

        $sendout->sendStatus = SendoutElement::STATUS_PAUSED;

        return $this->_updateSendoutRecord($sendout, ['sendStatus']);
    }

    /**
     * Cancels a sendout
     *
     * @param SendoutElement $sendout
     *
     * @return bool Whether the action was successful
     * @throws Throwable
     */
    public function cancelSendout(SendoutElement $sendout): bool
    {
        if (!$sendout->getIsCancellable()) {
            return false;
        }

        $sendout->sendStatus = SendoutElement::STATUS_CANCELLED;

        return $this->_updateSendoutRecord($sendout, ['sendStatus']);
    }

    /**
     * Deletes a sendout
     *
     * @param SendoutElement $sendout
     *
     * @return bool Whether the action was successful
     * @throws Throwable
     */
    public function deleteSendout(SendoutElement $sendout): bool
    {
        if (!$sendout->getIsDeletable()) {
            return false;
        }

        return Craft::$app->getElements()->deleteElement($sendout);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns excluded mailing list recipients query
     *
     * @param SendoutElement $sendout
     *
     * @return ActiveQuery
     */
    private function _getExcludedMailingListRecipientsQuery(SendoutElement $sendout): ActiveQuery
    {
        $query =  ContactMailingListRecord::find()
            ->select('contactId')
            ->where([
                'mailingListId' => $sendout->getExcludedMailingListIds(),
                'subscriptionStatus' => 'subscribed',
            ]);

        return $query;
    }

    /**
     * Returns excluded recipients query
     *
     * @param SendoutElement $sendout
     * @param bool|null $todayOnly
     *
     * @return ActiveQuery
     */
    private function _getSentRecipientsQuery(SendoutElement $sendout, bool $todayOnly = null): ActiveQuery
    {
        $todayOnly = $todayOnly ?? false;

        $query = ContactCampaignRecord::find()
            ->select('contactId')
            ->where(['sendoutId' => $sendout->id])
            ->andWhere(['not', ['sent' => null]]);

        if ($todayOnly) {
            $now = new DateTime();

            // Add condition that sent is today
            $query->andWhere(Db::parseDateParam('sent', $now->format('Y-m-d'), '>'));
        }

        return $query;
    }

    /**
     * Updates a sendout's record with the provided fields
     *
     * @param SendoutElement $sendout
     * @param array $fields
     *
     * @return bool
     */
    private function _updateSendoutRecord(SendoutElement $sendout, array $fields): bool
    {
        /** @var SendoutRecord|null $sendoutRecord */
        $sendoutRecord = SendoutRecord::find()->where(['id' => $sendout->id])->one();

        if ($sendoutRecord === null) {
            return false;
        }
//print_r($fields); die();
        $sendoutRecord->setAttributes($sendout->toArray($fields), false);
  
        
        return $sendoutRecord->save();
    }

    /**
     * Converts links
     *
     * @param string $body
     * @param ContactElement $contact
     * @param SendoutElement $sendout
     *
     * @return string
     * @throws Exception
     */
    private function _convertLinks(string $body, ContactElement $contact, SendoutElement $sendout): string
    {
        // Get base URL
        $path = Craft::$app->getConfig()->getGeneral()->actionTrigger.'/campaign/t/click';
        $baseUrl = UrlHelper::siteUrl($path, ['cid' => $contact->cid, 'sid' => $sendout->sid, 'lid' => '']);

        // Use DOMDocument to parse links
        $dom = new DOMDocument();

        // Suppress markup errors and prepend XML tag to force utf-8 encoding (https://gist.github.com/Xeoncross/9401853)
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$body);

        /** @var DOMElement[] $elements*/
        $elements = $dom->getElementsByTagName('a');

        foreach ($elements as $element) {
            $url = $element->getAttribute('href');
            $title = $element->getAttribute('title');

            // If URL begins with http
            if (strpos($url, 'http') === 0) {
                // Ignore if unsubscribe link
                if (preg_match('/\/campaign\/(t|tracker)\/unsubscribe/i', $url)) {
                    continue;
                }

                $key = $url.':'.$title;

                // If link has not yet been converted
                if (!isset($this->_links[$key])) {
                    // Check for link record in database
                    $linkRecord = LinkRecord::findOne([
                        'campaignId' => $sendout->campaignId,
                        'url' => $url,
                        'title' => $title,
                    ]);

                    // Create new record if not found
                    if ($linkRecord === null) {
                        $linkRecord = new LinkRecord();
                        $linkRecord->campaignId = $sendout->campaignId;
                        $linkRecord->url = $url;
                        $linkRecord->title = $title;

                        $linkRecord->save();
                    }

                    // Add link to converted links
                    $this->_links[$key] = $linkRecord->lid;
                }

                $lid = $this->_links[$key];

                // Replace href attribute
                $element->setAttribute('href', $baseUrl.$lid);
            }
        }

        // Save document element to maintain utf-8 encoding (https://gist.github.com/Xeoncross/9401853)
        return $dom->saveHTML($dom->documentElement);
    }
}
