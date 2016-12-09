<?php
/**
 * DelayedEventsConsumer
 */

namespace DoSomething\DelayedEvents;

use \SimpleXMLElement;
use \Exception;

use DoSomething\MB_Toolbox\MB_Toolbox_BaseConsumer;
use DoSomething\StatHat\Client as StatHat;

class DelayedEventsConsumer extends MB_Toolbox_BaseConsumer
{

  const TEXT_QUEUE_NAME = 'dispatchDelayedTextsQueue';
  const SIGNUP_MESSAGE_TYPE = 'scheduled_relative_to_signup_date';
  const REPORTBACK_MESSAGE_TYPE = 'scheduled_relative_to_reportback_date';

  /**
   * Gambit campaigns cache.
   *
   * @var array
   */
  private $gambitCampaignsCache = [];

  /**
   * Gambit campaign.
   *
   * @var boolean|string
   */
  private $gambitCampaign = false;

  /**
   * Preprocessed data.
   *
   * @var array
   */
  private $preprocessedData = [];


  /**
   * Constructor compatible with MBC_BaseConsumer.
   */
  public function __construct($targetMBconfig = 'messageBroker') {
    parent::__construct($targetMBconfig);

    // Cache gambit campaigns,
    $gambit = $this->mbConfig->getProperty('gambit');
    $gambitCampaigns = $gambit->getAllCampaigns();

    foreach ($gambitCampaigns as $campaign) {
      if ($campaign->campaignbot === true) {
        $this->gambitCampaignsCache[$campaign->id] = $campaign;
      }
    }

    if (count($this->gambitCampaignsCache) < 1) {
      // Basically, die.
      throw new Exception('No gambit connetion.');
    }
  }

  /**
   * Initial method triggered by blocked call in mbc-registration-mobile.php.
   *
   * @param array $messages
   *   The contents of the queue entry message being processed.
   */
  public function consumeDelayedEvents($messages) {
    echo '------ delayed-events-consumer - DelayedEventsConsumer->consumeDelayedEvents() - ' . date('j D M Y G:i:s T') . ' START ------', PHP_EOL . PHP_EOL;
    $this->preprocessedData = [];
    $this->gambitCampaign = false;

    try {

      $processData = [];

      foreach ($messages as $key => $message) {
        $body = $message->getBody();
        if ($this->isSerialized($body)) {
          $payload = unserialize($body);
        } else {
          $payload = json_decode($body, true);
        }

        // Check that message is decoded correctly.
        if (!$payload) {
          echo 'Corrupted message: ' . $body . PHP_EOL;
          $this->statHat->ezCount('MB_Toolbox: MB_Toolbox_BaseConsumer: consumeQueue Exception', 1);

          unset($messages[$key]);
          $this->messageBroker->sendNack($message, false, false);
          continue;
        }

        // Check that message is qualified for this consumer.
        if (!$this->canProcess($payload)) {
          echo '- canProcess() is not passed, removing from queue:' . $body . PHP_EOL;
          $this->statHat->ezCount('delayed-events-consumer: DelayedEventsConsumer: skipping', 1);

          unset($messages[$key]);
          $this->messageBroker->sendNack($message, false, false);
          continue;
        }

        $this->statHat->ezCount('MB_Toolbox: MB_Toolbox_BaseConsumer: consumeQueue', 1);

        // Preprocess data.
        $this->setter([$message, $payload]);

      }

      if (!$this->preprocessedData) {
        echo '- consumeDelayedEvents() no data to process.' . PHP_EOL;
        return;
      }

      // Process data.
      $this->process($this->preprocessedData);
      $this->statHat->ezCount('delayed-events-consumer: DelayedEventsConsumer: process', count($this->preprocessedData));
    }
    catch(Exception $e) {
      /**
       * The following code block is just awful.
       * It's legacy and we'll get rid of it soon.
       * | | |
       * V V V
       */

      if (!(strpos($e->getMessage(), 'Connection timed out') === false)) {
        echo '** Connection timed out... waiting before retrying: ' . date('j D M Y G:i:s T') . ' - getMessage(): ' . $e->getMessage(), PHP_EOL;
        $this->statHat->ezCount('delayed-events-consumer: DelayedEventsConsumer: Exception: Connection timed out', 1);
        sleep(self::RETRY_SECONDS);
        $this->messageBroker->sendNack($this->message['payload']);
        echo '- Nack sent to requeue message: ' . date('j D M Y G:i:s T'), PHP_EOL . PHP_EOL;
      }
      elseif (!(strpos($e->getMessage(), 'Operation timed out') === false)) {
        echo '** Operation timed out... waiting before retrying: ' . date('j D M Y G:i:s T') . ' - getMessage(): ' . $e->getMessage(), PHP_EOL;
        $this->statHat->ezCount('delayed-events-consumer: DelayedEventsConsumer: Exception: Operation timed out', 1);
        sleep(self::RETRY_SECONDS);
        $this->messageBroker->sendNack($this->message['payload']);
        echo '- Nack sent to requeue message: ' . date('j D M Y G:i:s T'), PHP_EOL . PHP_EOL;
      }
      elseif (!(strpos($e->getMessage(), 'Failed to connect') === false)) {
        echo '** Failed to connect... waiting before retrying: ' . date('j D M Y G:i:s T') . ' - getMessage(): ' . $e->getMessage(), PHP_EOL;
        sleep(self::RETRY_SECONDS);
        $this->messageBroker->sendNack($this->message['payload']);
        echo '- Nack sent to requeue message: ' . date('j D M Y G:i:s T'), PHP_EOL . PHP_EOL;
        $this->statHat->ezCount('delayed-events-consumer: DelayedEventsConsumer: Exception: Failed to connect', 1);
      }
      elseif (!(strpos($e->getMessage(), 'Bad response - HTTP Code:500') === false)) {
        echo '** Connection error, http code 500... waiting before retrying: ' . date('j D M Y G:i:s T') . ' - getMessage(): ' . $e->getMessage(), PHP_EOL;
        sleep(self::RETRY_SECONDS);
        $this->messageBroker->sendNack($this->message['payload']);
        echo '- Nack sent to requeue message: ' . date('j D M Y G:i:s T'), PHP_EOL . PHP_EOL;
        $this->statHat->ezCount('delayed-events-consumer: DelayedEventsConsumer: Exception: Bad response - HTTP Code:500', 1);
      }
      else {
        echo '- Not timeout or connection error, message to deadLetterQueue: ' . date('j D M Y G:i:s T'), PHP_EOL;
        echo '- Error message: ' . $e->getMessage(), PHP_EOL;

        // Uknown exception, save the message to deadLetter queue.
        $this->statHat->ezCount('delayed-events-consumer: DelayedEventsConsumer: Exception: deadLetter', 1);
        parent::deadLetter($this->message, 'DelayedEventsConsumer->consumeDelayedEvents() Error', $e);

        // Send Negative Acknowledgment, don't requeue the message.
        $this->messageBroker->sendNack($this->message['payload'], false, false);
      }
    }

    // @todo: Throttle the number of consumers running. Based on the number of messages
    // waiting to be processed start / stop consumers. Make "reactive"!
    $queueStatus = parent::queueStatus(self::TEXT_QUEUE_NAME);

    echo  PHP_EOL . '------ delayed-events-consumer - DelayedEventsConsumer->consumeDelayedEvents() - ' . date('j D M Y G:i:s T') . ' END ------', PHP_EOL . PHP_EOL;
  }

  /**
   * Method to determine if message can / should be processed. Conditions based on business
   * logic for submitted mobile numbers and related message values.
   *
   * @param array $message Values to determine if message can be processed.
   *
   * @retun boolean
   */
  protected function canProcess($payload) {
    // Check mobile number presence.
    if (empty($payload['mobile'])) {
      echo '** canProcess(): mobile number was not defined, skipping.' . PHP_EOL;

      return false;
    }

    // Check application id.
    if (empty($payload['application_id'])) {
      echo '** canProcess(): application_id not set.' . PHP_EOL;

      return false;
    }

    // Check that application id is allowed.
    $supportedApps = ['US', 'MUI'];
    if (!in_array($payload['application_id'], $supportedApps)) {
      echo '** canProcess(): Unsupported application: '
        . $payload['application_id'] . '.' . PHP_EOL;

      return false;
    }


    // Check activity presence.
    if (empty($payload['activity'])) {
      echo '** canProcess(): activity not set.' . PHP_EOL;
      parent::reportErrorPayload();

      return false;
    }

    // Check that the activity is allowed.
    $allowedActivities = [
      'campaign_signup',
      'campaign_reportback',
    ];
    if (!in_array($payload['activity'], $allowedActivities)) {
      echo '** canProcess(): activity is not supported: '
        . $payload['activity'] . '.' . PHP_EOL;

      return false;
    }

    // Check campaign id presence.
    if (empty($payload['event_id'])) {
      echo '** canProcess(): campaign id is nor provided.' . PHP_EOL;
      parent::reportErrorPayload();

      return false;
    }

    // Check that campaign is enabled on Gambit.
    $campaignId = (int) $payload['event_id'];

    // Only if enabled on Gambit.
    if (!array_key_exists($campaignId, $this->gambitCampaignsCache)) {
      echo '** canProcess(): Campaign is not available on Gambit: '
        . $campaignId . ', skipping.' . PHP_EOL;

      return false;
    }

    $this->gambitCampaign = $this->gambitCampaignsCache[$campaignId];
    if (empty($this->gambitCampaign)) {
      echo '** canProcess(): Campaign is not enabled on Campaignbot: '
        . $campaignId . ', ignoring.' . PHP_EOL;
      parent::reportErrorPayload();

      return false;
    }

    // Check user on MoCo.
    $mobileCommons = $this->mbConfig->getProperty('mobileCommons');

    // Check existing wrapper.
    $mobileCommonsWrapper = $this->mbConfig->getProperty('mobileCommonsWrapper');
    $mobileCommonsAccountExists = $mobileCommonsWrapper->checkExisting(
      $mobileCommons,
      $payload['mobile']
    );

    if (!$mobileCommonsAccountExists) {
      $payload = '** canProcess(): account is not MobileCommons subscriber: '
        . $payload['mobile'] . '.' . PHP_EOL;
      echo $payload;
      parent::reportErrorPayload();

      throw new Exception($payload);
    }

    echo '** canProcess(): passed.' . PHP_EOL;
    return true;
  }

  /**
   * Data processing logic.
   */
  protected function setter($arguments) {
    // Damn you, bad OOP design.
    list($message, $payload) = $arguments;

    // 1. Index by user mobile.
    $phone = $payload['mobile'];
    $dataItem = &$this->preprocessedData[$phone];

    // 2. Index by message type.
    $messageType = false;
    switch ($payload['activity']) {

      case 'campaign_signup':
        $messageType = self::SIGNUP_MESSAGE_TYPE;
        break;

      case 'campaign_reportback':
        $messageType = self::REPORTBACK_MESSAGE_TYPE;
        break;

      default:
        throw new Exception('This should never be called.');
        break;

    }
    $dataItem[$messageType] = [];

    // 3. Index by campaign id and prepare Gambit request arguments.
    $campaignId = $payload['event_id'];
    $dataItem[$messageType][$campaignId] = [
      'request' => [
        'phone' => $phone,
        'type' => $messageType,
      ],
      'message' => $message,
    ];

    // Done. The priority will be determined after all data is processed.
    return true;
  }

  /**
   * Forwards results to gambit.
   */
  protected function process($preprocessedData) {

  }

}
