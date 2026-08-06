<?php

namespace Civi;

/**
 * Exposes CRM_Civixero_BankTransaction's protected mapToAccounts() for direct
 * testing. See InvoiceMappingTestable for why this lives outside a *Test.php
 * file.
 */
class BankTransactionMappingTestable extends \CRM_Civixero_BankTransaction {

  public function callMapToAccounts(array $invoiceData, ?string $xeroInvoiceUUID): array {
    return $this->mapToAccounts($invoiceData, $xeroInvoiceUUID);
  }

  public function callGetNotUpdateCandidateResponses(): array {
    return $this->getNotUpdateCandidateResponses();
  }

}
