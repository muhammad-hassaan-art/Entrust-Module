<?php
namespace Drupal\entrust_salesforce\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;

class AuthorizationController extends ControllerBase {

  public function authorize(Request $request) {
    // Extract the token from the request headers.
    $token = $request->headers->get('Authorization');

    $savedAuthKey = \Drupal::database()
      ->select('entrust_salesforce_save_password', 'esp')
      ->fields('esp', ['Password'])
      ->execute()
      ->fetchField();

    if ($savedAuthKey === base64_encode($token)) {
      return new JsonResponse([
        'message' => 'Authorization successful',
      ]);
    } else {
      return new JsonResponse([
        'error' => 'Authorization failed',
      ], 401);
    }
  }
}
