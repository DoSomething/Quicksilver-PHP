<?php
/**
 * DeadLetterFilter
 */

namespace DoSomething\DeadLetter;

use \Exception;

use DoSomething\MB_Toolbox\MB_Toolbox_BaseConsumer;

class DeadLetterFilter extends MB_Toolbox_BaseConsumer
{

  const TEXT_QUEUE_NAME = 'deadLetterQueue';

  /**
   * Constructor compatible with MBC_BaseConsumer.
   */
  public function __construct($targetMBconfig = 'messageBroker', $args) {
    parent::__construct($targetMBconfig);
    
  }

  /**
   * Initial method triggered by blocked call in dead-letter-filter.
   *
   * @param array $messages
   *   The contents of the queue entry message being processed.
   */
  public function filterDeadLetterQueue($letters) {
    echo '------ dead-letter-filter - DeadLetterFilter->filterDeadLetterQueue() - ' . date('j D M Y G:i:s T') . ' START ------', PHP_EOL . PHP_EOL;

    $this->letters = $letters;

    foreach ($this->letters as $key => $letter) {
      $body = $letter->getBody();
      if ($this->isSerialized($body)) {
        $payload = unserialize($body);
      } else {
        $payload = json_decode($body, true);
      }

      $original = &$payload['message'];

      // Check that message is decoded correctly.
      if (!$payload) {
        $this->log('Corrupted message: %s', $body);
        $this->reject($key);
        continue;
      }

      // Check that message is qualified for this consumer.
      if (!$this->canProcess($payload)) {
        $this->log('Rejected: %s', json_encode($original));
        $this->reject($key);
        continue;
      }

      // Process
      $this->process($payload);
    }

    echo  PHP_EOL . '------ dead-letter-filter - DeadLetterFilter->filterDeadLetterQueue() - ' . date('j D M Y G:i:s T') . ' END ------', PHP_EOL . PHP_EOL;
  }

  protected function canProcess($payload) {
    // deadLetter v2:
    if (!empty($payload['message']) && !empty($payload['metadata'])) {
      $original = &$payload['message'];

      // Skip countries.
      $disabledAppIds = ['CA'];
      if (!empty($original['application_id'])
          && in_array($original['application_id'], $disabledAppIds)) {
        $this->log(
          'Skipping disabled application id %s',
          $original['application_id']
        );
        return false;
      }

    }

    return true;
  }

  protected function process($payload) {
    // Resolve kind of the issue.
    // 1. Handle Niche alleged duplicates
    $isNicheDuplicatesError = !empty($payload['message']['tags'])
      && in_array('current-user-welcome-niche', $payload['message']['tags'])
      && !empty($payload['metadata'])
      && !empty($payload['metadata']['error'])
      && !empty($payload['metadata']['error']['locationText'])
      && $payload['metadata']['error']['locationText'] === 'processOnGambit';


    if ($isNicheDuplicatesError) {
      return $this->handleNicheAlledgedDuplicates($payload['message']);
    }
  }

  private function handleNicheAlledgedDuplicates($original) {
    var_dump($original); die();
  }

  private function reject($key) {
    if (!DRY_RUN) {
      $this->messageBroker->sendNack($this->letters[$key], false, false);
    }
    unset($this->letters[$key]);
  }

  /**
   * Log
   */
  static function log()
  {
    $args = func_get_args();
    $message = array_shift($args);
    echo '** ';
    echo vsprintf($message, $args);
    echo PHP_EOL;
  }

  /**
   * Bad OOP IS BAD.
   */
  protected function setter($arguments) {}

}
