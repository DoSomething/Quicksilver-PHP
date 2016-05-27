<?PHP
/**
 * MBC_TransactionalDigest: Class to gather user campaign signup transactional message
 * requests into a single digest message for a given time period.
 */

namespace DoSomething\MBC_TransactionalDigest;

use DoSomething\MB_Toolbox\MB_Configuration;
use DoSomething\StatHat\Client as StatHat;
use DoSomething\MB_Toolbox\MB_Toolbox;
use DoSomething\MB_Toolbox\MB_Toolbox_BaseConsumer;
use \Exception;

/**
 * MBC_TransactionalDigest_Consumer class - functionality related to the Message Broker
 * consumer mbc-transactional-digest.
 */
class MBC_TransactionalDigest_Consumer extends MB_Toolbox_BaseConsumer
{

  const TRANSACTIONAL_DIGEST_WINDOW = 5;
  const TRANSACTIONAL_DIGEST_CYCLE = 2;

  /**
   * mb-logging-api configuration settings.
   *
   * @var array $mbLoggingAPIConfig
   */
  private $mbMessageServices;

  /**
   *
   * @var object $mbCampaignToolbox
   */
  private $mbCampaignToolbox;
  
  /**
   * A list of user objects.
   * @var array $users
   */
  private $users = [];

  /**
   * mb-logging-api configuration settings.
   *
   * @var array $mbLoggingAPIConfig
   */
  private $mbLoggingAPIConfig;

  /**
   * The timestamp of the last time the list of user transactions was processed.
   *
   * @var init $lastProcessed
   */
  private $lastProcessed;

  /**
   * Constructor for MBC_LoggingGateway
   *
   * @param string $targetMBconfig
   *   The Message Broker object used to interface the RabbitMQ server exchanges and
   *   related queues.
   */
  public function __construct($targetMBconfig = 'messageBroker') {

    parent::__construct($targetMBconfig);

    $this->mbMessageServices['email'] = new MB_Toolbox_MandrillService();
    $this->mbMessageServices['sms'] = new MB_Toolbox_MobileCommonsService();
    $this->mbMessageServices['ott'] = new MB_Toolbox_FacebookMessengerService();

    $this->mbLoggingAPIConfig = $this->mbConfig->getProperty('mb_logging_api_config');
    $this->lastProcessed = time();
  }

  /**
   * Triggered when loggingGatewayQueue contains a message.
   *
   * @param array $payload
   *   The contents of the queue entry
   */
  public function consumeQueue($payload) {

    echo '------- MBC_TransactionalDigest_Consumer - consumeQueue START - ' . date('j D M Y G:i:s T') . ' -------', PHP_EOL;

    parent::consumeQueue($payload);

    try {
      if ($this->canProcess()) {
        parent::logConsumption(['email', 'event_id']);
        $this->setter($this->message);
        $this->messageBroker->sendAck($this->message['payload']);
      }
      elseif (isset($this->message['log-type']) && $this->message['log-type'] == 'shim') {
        echo '* Shim message encounter... time to sleep.', PHP_EOL;
        sleep(self::SHIM_SLEEP);
        $this->processShim();
      }
      else {
        echo '- Message can\'t be processed, sending to deadLetterQueue.', PHP_EOL;
        $this->statHat->ezCount('mbc-transactional-digest: MBC_LoggingGateway_Consumer: Exception: deadLetter', 1);
        // parent::deadLetter($this->message, 'MBC_LoggingGateway_Consumer->consumeLoggingGatewayQueue() Generation Error');

      }
    }
    catch(Exception $e) {
      echo 'Error sending transactional request to transactionalQueue, retrying... Error: ' . $e->getMessage();
      $this->statHat->ezCount('mbc-transactional-digest: MBC_TransactionalDigest_Consumer: Exception: ??', 1);
    }

    // Batch time reached, generate digest and dispatch messages to transactional queues
    try {
      if ($this->timeToProcess()) {
        $this->process();
        $this->lastProcessed = time();
      }
    }
    catch(Exception $e) {
      echo 'Error attempting to process transactional digest request. Error: ' . $e->getMessage();
      $this->statHat->ezCount('mbc-transactional-digest: MBC_TransactionalDigest_Consumer: Exception', 1);
      parent::deadLetter($this->message, 'MBC_LoggingGateway_Consumer->consumeLoggingGatewayQueue() process() Error', $e->getMessage());
      $this->messageBroker->sendAck($this->message['payload']);
    }

    echo '------- MBC_TransactionalDigest_Consumer - consumeQueue() END - ' . date('j D M Y G:i:s T') . ' -------', PHP_EOL;
  }

  /**
   * Conditions to test before processing the message.
   *
   * @return boolean
   */
  protected function canProcess() {

    if (empty($this->message['email']) && empty($this->message['mobile']) && empty($this->message['ott'])) {
      return false;
    }

    if (empty($this->message['activity'])) {
      return false;
    }
    if (isset($this->message['activity']) && $this->message['activity'] != 'campaign_signup') {
      return false;
    }
    if (empty($this->message['user_language'])) {
      return false;
    }

    if (isset($this->users[$this->message['email']][$this->message['event_id']])) {
      $message = 'MBC_TransactionalDigest_Consumer->canProcess(): Duplicate campaign signup for '.$this->message['email'].' to campaign ID: '.$this->message['event_id'];
      echo $message, PHP_EOL;
      throw new Exception($message);
    }

    // TEST MODE
    if (strpos($this->message['email'], '@dosomething.org') === false) {
      return false;
    }

    return true;
  }

  /**
   * Construct values for submission to transactional message queues.
   *
   * @param array $message
   *   The message to process based on what was collected from the queue being processed.
   */
  protected function setter($message) {

    // Collection of campaign details and generation of markup by medium
    if (empty($this->campaigns[$message['event_id']])) {
      $this->campaigns[$message['event_id']] = new MB_Toolbox_Campaign($message['event_id']);
      $this->campaigns[$message['event_id']]->markup = [
        'email' => $this->mbMessageServices['email']->generateCampaignMarkup($this->campaigns[$message['event_id']]),
        'sms'   => $this->mbMessageServices['sms']->generateCampaignMarkup($this->campaigns[$message['event_id']]),
        'ott'   => $this->mbMessageServices['ott']->generateCampaignMarkup($this->campaigns[$message['event_id']]),
      ];
    }

    // Basic user settings by medium
    if (isset($message['email']) && empty($this->users[$message['email']])) {
      $this->users[$message['email']] = $this->gatherUserDetailsEmail($message);
      $this->users[$message['email']]['merge_vars'] = $message['merge_vars'];
    }
    if (isset($message['mobile']) && empty($this->users[$message['mobile']])) {
      $this->users[$message['mobile']] = $this->gatherUserDetailsSMS($message);
    }
    if (isset($message['ott']) && empty($this->users[$message['ott']])) {
      $this->users[$message['ott']] = $this->gatherUserDetailsOTT($message);
    }

    // Assign markup by medium for campaigns the user is signed up for
    if (isset($message['email']) && empty($this->users[$message['email']]->campaigns[$message['event_id']])) {
      $this->users[$message['email']]['campaigns'][$message['event_id']] = $this->campaigns[$message['event_id']]->markup['email'];
      $this->users[$message['email']]['last_transaction_stamp'] = time();
    }
    if (isset($message['mobile']) && empty($this->users[$message['mobile']]->campaigns[$message['event_id']])) {
      $this->users[$message['mobile']]['campaigns'][$message['event_id']] = $this->campaigns[$message['event_id']]->markup['sms'];
      $this->users[$message['mobile']]['last_transaction_stamp'] = time();
    }
    if (isset($message['ott']) && empty($this->users[$message['ott']]->campaigns[$message['event_id']])) {
      $this->users[$message['ott']]['campaigns'][$message['event_id']] = $this->campaigns[$message['event_id']]->markup['ott'];
      $this->users[$message['ott']]['last_transaction_stamp'] = time();
    }

  }

  /**
   * process(): Gather message settings into submission to mb-logging-api
   */
  protected function process() {

    // Build transactional requests for each of the users
    foreach ($this->users as $address => $messageDetails) {

      if (isset($messageDetails['last_transaction_stamp']) && $messageDetails['last_transaction_stamp'] < (time() - self::TRANSACTIONAL_DIGEST_WINDOW)) {

        // Digest messages are composed of at least two signups in the DIGEST_WINDOW. If only one campaign signup, \
        // send message to transactionalQueue to send standard campaign signup message.
        $medium = $this->whatMedium($address);
        if (count($messageDetails['campaigns']) > 1) {
          // Toggle between message services depending on communication medium - eMail vs SMS vs OTT
          $messageDetails['campaignsMarkup'] = $this->mbMessageServices[$medium]->generateCampaignsMarkup($messageDetails['campaigns']);
          $message = $this->mbMessageServices[$medium]->generateDigestMessage($address, $messageDetails);
          $this->mbMessageServices[$medium]->dispatchDigestMessage($message);
        }
        else {
          $message = $this->mbMessageServices[$medium]->generateSingleMessage($address, $messageDetails);
          $this->mbMessageServices[$medium]->dispatchSingleMessage($message);
        }
        unset($this->users[$address]);

      }

    }
  }

  /**
   * gatherUserDetailsEmail: .
   *
   * @param array $message
   *   ...
   *
   * @return array $
   *   ...
   */
  public function gatherUserDetailsEmail($message) {

    $userDetails = [
      'campaigns' => [],
      'first_name' => $message['merge_vars']['FNAME']
    ];

    return $userDetails;
  }

  /**
   * gatherUserDetailsSMS: .
   *
   * @param array $message
   *   ...
   *
   * @return array $
   *   ...
   */
  public function gatherUserDetailsSMS($message) {

    $userDetails = [
      'campaigns' => [],
      'first_name' => $message['merge_vars']['FNAME'],
    ];

    return $userDetails;
  }

  /**
   * gatherUserDetailsOTT: .
   *
   * @param array $message
   *   ...
   *
   * @return array $
   *   ...
   */
  public function gatherUserDetailsOTT($message) {

    $userDetails = [
      'campaigns' => [],
      'first_name' => $message['merge_vars']['FNAME']
    ];

    return $userDetails;
  }

  /**
   * timeToProcess: .
   *
   * @param array $payloadDetails
   *   ...
   *
   * @return array $
   *   ...
   */
  public function timeToProcess() {

    if (($this->lastProcessed + self::TRANSACTIONAL_DIGEST_CYCLE) < time()) {
      return true;
    }
    return false;
  }

  /**
   * whatMedium(): .
   *
   * @param string $address
   *   The address to analyze to determine what medium it is from.
   *
   * @return string $medium
   *   The determined medium for the $address.
   */
  public function whatMedium($address) {

    if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
      return 'email';
    }

    // Validate phone number based on the North American Numbering Plan
    // https://en.wikipedia.org/wiki/North_American_Numbering_Plan
    $regex = "/^(\d[\s-]?)?[\(\[\s-]{0,2}?\d{3}[\)\]\s-]{0,2}?\d{3}[\s-]?\d{4}$/i";
    if (preg_match( $regex, $address)) {
      return 'sms';
    }

    // To be better defined based on target OTT conditions
    /*
    if (isset($address)) {
      return 'ott';
    }
    */

    return false;
  }

  /**
   * logTransactionalDigestMessage: Log transactional digest message contents by email address.
   *
   * @param array $payloadDetails
   *   ...
   *
   * @return array $
   *   ...
   */
  public function logTransactionalDigestMessage($payloadDetails) {


  }

}
