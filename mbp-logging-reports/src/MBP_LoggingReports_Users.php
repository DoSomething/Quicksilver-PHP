<?php
/**
 * MBP_LoggingReports_Users - class to manage importing user data via CSV files to the
 * Message Broker system.
 */

namespace DoSomething\MBP_LoggingReports;

use DoSomething\MB_Toolbox\MB_Configuration;
use DoSomething\StatHat\Client as StatHat;
use \Exception;

/**
 * MBP_LoggingReports_users class - Generate reports of user imports based on log
 * entries in the mb-logging database.
 */
class MBP_LoggingReports_Users
{

  const MB_LOGGING_API = '/api/v1';
  const NICHE_USER_BUDGET = 33333;
  const AFTERSCHOOL_USER_BUDGET = 'Unlimited';

  /**
   * Message Broker connection to send messages to send email request for report message.
   *
   * @var object $messageBroker_Subscribes
   */
  protected $messageBroker;

  /**
   * Message Broker Toolbox utilities.
   *
   * @var object $mbToolbox
   */
  private $mbToolbox;

  /**
   * Message Broker Toolbox cURL utilities.
   *
   * @var object $mbToolboxCURL
   */
  private $mbToolboxCURL;

  /**
   * mb-logging-api base URL.
   *
   * @var array $mbLoggingAPIUrl
   */
  private $mbLoggingAPIUrl;

  /**
   * Setting from external services - Mailchimp.
   *
   * @var array
   */
  private $statHat;

  /**
   * List of allowed sources currently supported.
   *
   * @var array
   */
  private $allowedSources;

  /**
   * Constructor for MBC_LoggingReports
   */
  public function __construct() {

    $this->mbConfig = MB_Configuration::getInstance();
    $this->messageBroker = $this->mbConfig->getProperty('messageBroker');
    $this->mbToolbox = $this->mbConfig->getProperty('mbToolbox');
    $this->mbToolboxCURL = $this->mbConfig->getProperty('mbToolboxcURL');
    $mbLoggingAPIConfig = $this->mbConfig->getProperty('mb_logging_api_config');
    $this->mbLoggingAPIUrl = $mbLoggingAPIConfig['host'] . ':' . $mbLoggingAPIConfig['port'];
    $this->statHat = $this->mbConfig->getProperty('statHat');
    $this->allowedSources = unserialize(ALLOWED_SOURCES);
  }

  /**
   * report() - Request a report be sent.
   *
   * @param string $type
   *   The type or collection of types of report to generate
   * @param string $source
   *   The import source: Niche or After School
   * @param array $recipients
   *   List of addresses (email and/or SMS phone numbers)
   */
  public function report($type, $source, $recipients = null) {

    switch($type) {

      case 'runningMonth':

        $reportData[$source]['userImportCSV'] = $this->collectData('userImportCSV', $source);
        $reportData[$source]['existingUsers'] = $this->collectData('existingUsers', $source);
        $reportData[$source]['newUsers'] = $reportData[$source]['userImportCSV']['usersProcessed'] - $reportData[$source]['existingUsers']['total'];
        $percentNewUsers = ($reportData[$source]['userImportCSV']['usersProcessed'] - $reportData[$source]['existingUsers']['total']) / $reportData[$source]['userImportCSV']['usersProcessed'] * 100;
        $reportData[$source]['percentNewUsers'] = round($percentNewUsers, 1);

        if ($source == 'niche') {
          $userBudget = self::NICHE_USER_BUDGET;
        }
        elseif ($source == 'afterschool') {
          $userBudget = self::AFTERSCHOOL_USER_BUDGET;
        }
        $budgetPercentage = 100 - (self::NICHE_USER_BUDGET - $reportData[$source]['newUsers']) / self::NICHE_USER_BUDGET * 100;
        $reportData[$source]['budgetPercentage'] = round($budgetPercentage, 1);
        $reportData[$source]['budgetBackgroundColor'] = $this->setBudgetColor($reportData[$source]['budgetPercentage']);

        $composedReport = $this->composedReportMarkup($reportData);
        break;

      default:

        throw new Exception('Unsupported report type: ' . $type);
        break;
    }

    if (empty($recipients)) {
      $recipients = $this->getRecipients();
    }

    $this->dispatchReport($composedReport, $recipients);
  }

  /**
   * Controller for report data collection.
   *
   * @param string $type
   *   The type of report generate: userImportCSV, existingUsers, (additional format to follow)
   * @param string $source
   *   The name of the user import source: niche, afterschool, all
   * @param string $startDate
   *   The date to start reports from. Defaults to start of month
   * @param string $endDate
   *   The date to end reports from. Defaults to start of today.
   */
  private function collectData($type, $source, $startDate = null, $endDate = null) {

    if (!in_array($source, $this->allowedSources)) {
      throw new Exception('Unsupported source: ' . $source);
    }

    if ($startDate != null) {
      $startDateStamp = date('Y-m', strtotime($targetStartDate)) . '-01';;
    }
    else {
      $startDateStamp = strtotime('first day of this month');
    }
    if ($endDate != null) {
      $endDateStamp = date('Y-m', strtotime($targetStartDate . ' + 1 month')) . '-01';
    }
    else {
      $endDateStamp = strtotime('today midnight');
    }

    // Existing user imports from $source
    if ($type == 'userImportCSV') {
      $reportData = $this->collectUserImportCSVEntries($source, $startDateStamp, $endDateStamp);
    }
    if ($type == 'existingUsers') {
      $reportData = $this->collectExistingUserImportLogEntries($source, $startDateStamp, $endDateStamp);
    }

    if (!empty($reportData)) {
      return $reportData;
    }
    else {
      throw new Exception('composedReportData not defined.');
    }
  }

  /**
   * Collect log entries on processed CSV files.
   *
   * @param string $source
   *   The target source - one of niche, afterschool
   * @param integer startDateStamp
   *   The datestamp to start reports from
   * @param integer endDateStamp
   *   The datestamp to end reports from
   *
   * @return array
   *   Collected log entries
   */
  private function collectUserImportCSVEntries($source, $startDateStamp, $endDateStamp) {

    $stats['filesProcessed'] = 0;
    $stats['usersProcessed'] = 0;

    $targetStartDate = date('Y-m-d', $startDateStamp);
    $targetEndDate = date('Y-m-d', $endDateStamp);
    $curlUrl = $this->mbLoggingAPIUrl . '/api/v1/imports/summaries?type=user_import&source=' . strtolower($source) . '&origin_start=' . $targetStartDate . '&origin_end=' . $targetEndDate;

    $results = $this->mbToolboxCURL->curlGET($curlUrl);
    if ($results[1] != 200) {
      throw new Exception('Call to ' . $curlUrl . ' returned: ' . $results[1]);
    }
    $numberOfFiles = count($results[0]) - 1;

    $stats['startDate'] = $targetStartDate;
    $stats['endDate'] = $targetEndDate;
    $stats['numberOfFiles'] = $numberOfFiles;
    $stats['firstFile'] = $results[0][0]->target_CSV_file;
    $stats['lastFile'] = $results[0][$numberOfFiles]->target_CSV_file;

    foreach ($results[0] as $resultCount => $result) {
      $stats['filesProcessed']++;
      $stats['usersProcessed'] += $result->signup_count;
    }

    return $stats;
  }

  /**
   * Collect duplicate user import log entries.
   *
   * @param string $source
   *   The target source - one of niche, afterschool
   * @param integer startDateStamp
   *   The datestamp to start reports from
   * @param integer endDateStamp
   *   The datestamp to end reports from
   *
   * @return array
   *   Collected log entries
   */
  private function collectExistingUserImportLogEntries($source, $startDateStamp, $endDateStamp) {

    $targetStartDate = date('Y-m-d', $startDateStamp);
    $targetEndDate = date('Y-m-d', $endDateStamp);
    $curlUrl = $this->mbLoggingAPIUrl . '/api/v1/imports?type=user_import&source=' . strtolower($source) . '&origin_start=' . $targetStartDate . '&origin_end=' . $targetEndDate;

    $results = $this->mbToolboxCURL->curlGET($curlUrl);
    if ($results[1] != 200) {
      throw new Exception('Call to ' . $curlUrl . ' returned: ' . $results[1]);
    }

    $stats['existingMailchimpUser'] = 0;
    $stats['mobileCommonsUserError_existing'] = 0;
    $stats['mobileCommonsUserError_undeliverable'] = 0;
    $stats['mobileCommonsUserError_noSubscriptions'] = 0;
    $stats['mobileCommonsUserError_other'] = 0;
    $stats['existingDrupalUser'] = 0;

    $stats['startDate'] = $targetStartDate;
    $stats['endDate'] = $targetEndDate;
    $stats['total'] = count($results[0]);

    foreach ($results[0] as $resultCount => $result) {

      if (isset($result->email)) {
        $stats['existingMailchimpUser']++;
      }
      if (isset($result->phone)) {
        if ($result->phone->status == 'Active Subscriber') {
          $stats['mobileCommonsUserError_existing']++;
        }
        elseif ($result->phone->status == 'Undeliverable') {
          $stats['mobileCommonsUserError_undeliverable']++;
        }
        elseif ($result->phone->status == 'No Subscriptions') {
          $stats['mobileCommonsUserError_noSubscriptions']++;
        }
        else {
          $stats['mobileCommonsUserError_other']++;
        }
      }
      if (isset($result->drupal)) {
        $stats['existingDrupalUser']++;
      }

    }

    return $stats;
  }

  /**
   * Compose the contents of the existing users import report content.
   *
   * @param stats array
   *   Details of the user accounts that existed in Mailchimp, Mobile Common
   *   and/or Drupal at the time of import.
   *
   * @return string
   *   The text to be displayed in the report.
   */
  private function composedReportMarkup($reportData) {

    $reportContents  = '<table style ="border-collapse:collapse; width:100%; white-space:nowrap; border:1px solid black; padding:8px; text-align: center;">' . PHP_EOL;
    $reportContents .= '  <tr style ="border:1px solid white; padding:3px; background-color: black; color: white; font-weight: heavy;"><td></td><td>Users Processed</td><td>Existing Users</td><td>New Users</td><td>Budget</td></tr>' . PHP_EOL;

    foreach ($reportData as $source => $data) {

      $reportTitle .= '<strong>' . $data['userImportCSV']['startDate'] . ' - ' . $data['userImportCSV']['endDate'] . '</strong>' . PHP_EOL;
      $reportContents .= '
        <tr style ="border:1px solid black; padding:5px; background-color: grey; color: black;">
          <td style="text-align: right; font-size: 1.3em; font-weight: heavy; background-color: black; color: white;">' . $source . ':&nbsp;</td>
          <td style="background-color: white;">' . $data['userImportCSV']['usersProcessed'] . '</td>
          <td style="background-color: white;">' . $data['existingUsers']['total'] . '</td>
          <td>' . $data['newUsers'] . ' (' . $data['percentNewUsers'] . '% new)</td>
          <td style="background-color: ' . $data['budgetBackgroundColor'] . '; color: white;">' . $data['budgetPercentage'] . '%</td>
        </tr>' . PHP_EOL;

    }
    $reportContents .= '</table>' . PHP_EOL;

    $report = $reportTitle . $reportContents;
    return $report;
  }

  /**
   * Compose the contents of the existing users import report content.
   *
   * @param stats array
   *   Details of the user accounts that existed in Mailchimp, Mobile Common
   *   and/or Drupal at the time of import.
   *
   * @return string
   *   The text to be displayed in the report.
   */
  private function getRecipients() {

    $to = [
      [
        'email' => 'dlee@dosomething.org',
        'name' => 'Dee'
      ],
    ];

    return $to;
  }

  /**
   * Send report to appropriate managers.
   *
   * @param string $existingUsersReport string
   *   Details of the summary log entries for each import batch.
   * @param array $recipients
   *   A list of users to send the report to.
   */
  private function dispatchReport($composedReport, $recipients) {
    
    $memberCount = $this->mbToolbox->getDSMemberCount();

    foreach ($recipients as $to) {
      $message = array(
        'from_email' => 'machines@dosomething.org',
        'email' => $to['email'],
        'activity' => 'mb-reports',
        'email_template' => 'mb-user-import-report',
        'user_country' => 'US',
        'merge_vars' => array(
          'FNAME' => $to['name'],
          'SUBJECT' => 'Daily User Import Report - ' . date('Y-m-d'),
          'TITLE' => 'Daily User Imports',
          'BODY' => $composedReport,
          'MEMBER_COUNT' => $memberCount,
        ),
        'email_tags' => array(
          0 => 'mb-user-import-report',
        )
      );
      $payload = json_encode($message);
      $this->messageBroker->publish($payload, 'report.userimport.transactional', 1);
    }

  }

  /**
   * setBugetColor() - Based on the number of new users processed, set a color value - green, yellow, red
   * to highlight the current number of imported users.
   *
   * @param real $percentage
   *   The percentage amount of imported users for the month.
   *
   * @return string $color
   *   The CSS background-color property, used in report generation.
   */
  private function setBudgetColor($percentage) {

    if ($percentage <= 80) {
      $color = 'green';
    }
    if ($percentage > 80) {
      $color = 'yellow';
    }
    if ($percentage > 90) {
      $color = 'red';
    }
    return $color;
  }

}
