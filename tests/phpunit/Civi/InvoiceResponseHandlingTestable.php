<?php

namespace Civi;

/**
 * Exposes CRM_Civixero_Invoice's protected response-handling methods for
 * direct testing. See InvoiceMappingTestable for why this lives outside a
 * *Test.php file.
 */
class InvoiceResponseHandlingTestable extends \CRM_Civixero_Invoice {

  public function callSavePushResponse($result, $record) {
    return $this->savePushResponse($result, $record);
  }

  public function callValidateResponse($response) {
    return $this->validateResponse($response);
  }

  public function callIsNotUpdateCandidate($responseErrors): bool {
    return $this->isNotUpdateCandidate($responseErrors);
  }

}
