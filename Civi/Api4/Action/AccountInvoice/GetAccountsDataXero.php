<?php

namespace Civi\Api4\Action\AccountInvoice;

use Civi\Api4\Generic\DAOGetAction;
use Civi\Api4\Generic\Result;
use XeroAPI\XeroPHP\StringUtil;

class GetAccountsDataXero extends DAOGetAction {

  /**
   * Get the Xero accounts data in a standard format to be used by AccountSync AccountsInvoice code
   * Current supported keys are:
   *   - 'account_invoice_id' => The account_invoice ID from the database table
   *   - 'contribution_id' => CiviCRM Contribution ID
   *   - 'total_amount' => Total amount paid on invoice
   *   - 'invoice_contribution_status_name' => CiviCRM Contribution status name mapped from Xero invoice status
   *   - 'invoice_id' => Invoice ID from Xero accounts system
   *   - paid_date: Date that the invoice was fully paid (optional)
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    $wheres = $this->getWhere();
    foreach ($wheres as $whereIndex => $where) {
      if (isset($where[0]) && $where[0] === 'plugin') {
        unset($wheres[$whereIndex]);
      }
    }
    $this->setWhere($wheres);
    $this->addWhere('plugin', '=', 'xero');
    parent::_run($result);
    foreach ($result as $row) {
      $accountsData = json_decode($row['accounts_data'] ?? '', TRUE);
      $resultData = [
        'account_invoice_id' => $row['id'],
        'contribution_id' => $row['contribution_id'],
        'total_amount' => $accountsData['total'],
        'invoice_contribution_status_name' => $this->mapStatusToContributionStatus($accountsData['status']),
        'invoice_id' => $accountsData['invoice_id'],
      ];
      if (!empty($accountsData['fully_paid_on_date'])) {
        $resultData['paid_date'] = StringUtil::convertStringToDateTime($accountsData['fully_paid_on_date'])->format('Y-m-d H:i:s');
      }
      $results[] = $resultData;
    }
    $result->exchangeArray($results ?? []);
  }

  /**
   * Map a Xero status name eg. "PAID" to a CiviCRM Contribution status name eg. "Completed".
   * See also CRM_Civixero_Invoice::mapStatus()
   *
   * @param string $xeroStatusName
   *
   * @return string
   */
  private function mapStatusToContributionStatus(string $xeroStatusName): string {
    $statuses = [
      'PAID' => 'Completed',
      'DELETED' => 'Cancelled',
      'VOIDED' => 'Cancelled',
      'DRAFT' => 'Pending',
      'AUTHORISED' => 'Pending',
      'SUBMITTED' => 'Pending',
    ];
    return $statuses[$xeroStatusName] ?? '';
  }

}
