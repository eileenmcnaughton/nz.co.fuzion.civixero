<?php

namespace Civi;

/**
 * Exposes CRM_Civixero_Invoice's protected mapping methods for direct testing.
 *
 * Lives outside any *Test.php file (like MockConnector) so PHPUnit's eager
 * file-based test discovery doesn't include_once it - and thus resolve its
 * `extends` clause - before CiviCRM's headless install has registered the
 * civixero extension's autoloading.
 */
class InvoiceMappingTestable extends \CRM_Civixero_Invoice {

  public function callMapToAccounts(array $invoiceData, ?string $xeroInvoiceUUID): array {
    return $this->mapToAccounts($invoiceData, $xeroInvoiceUUID);
  }

  public function callMapCancelled(int $contributionID, ?string $xeroInvoiceUUID): array {
    return $this->mapCancelled($contributionID, $xeroInvoiceUUID);
  }

}
