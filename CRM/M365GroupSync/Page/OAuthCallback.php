<?php

class CRM_M365GroupSync_Page_OAuthCallback extends CRM_Core_Page {
  public function run(): void {
    try {
      $error = CRM_Utils_Request::retrieve('error', 'String');
      if ($error) {
        $description = CRM_Utils_Request::retrieve('error_description', 'String') ?: $error;
        throw new CRM_Core_Exception($description);
      }
      $code = CRM_Utils_Request::retrieve('code', 'String');
      $state = CRM_Utils_Request::retrieve('state', 'String');
      if (!$code || !$state) {
        throw new CRM_Core_Exception(ts('Microsoft did not return a valid authorization response.'));
      }
      (new CRM_M365GroupSync_Service_Auth())->complete($code, $state);
      CRM_Core_Session::setStatus(ts('Microsoft 365 authorization completed successfully.'), ts('Connected'), 'success');
    }
    catch (Throwable $e) {
      Civi::log()->error('Microsoft OAuth callback failed: {message}', ['message' => $e->getMessage()]);
      CRM_Core_Session::setStatus($e->getMessage(), ts('Microsoft sign-in failed'), 'error');
    }
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/m365-group-sync', 'reset=1'));
  }
}
