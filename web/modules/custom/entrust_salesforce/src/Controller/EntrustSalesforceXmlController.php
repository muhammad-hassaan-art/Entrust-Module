<?php

namespace Drupal\entrust_salesforce\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;
use Drupal\entrust_salesforce\Utilis\EntrustTechnote;
use Drupal\entrust_salesforce\Utilis\EntrustErrorCode;
use Drupal\entrust_salesforce\Utilis\EntrustValidation;
use Drupal\entrust_salesforce\Utilis\KnowledgeBase;

class EntrustSalesforceXmlController extends ControllerBase
{
  public function receiveXmlData(Request $request)
  {
    $authorizationController = new AuthorizationController();
    $authorizationResponse = $authorizationController->authorize($request);

    if ($authorizationResponse->getStatusCode() === 200) {
      $xmlData = $request->getContent();

      $validation = new EntrustValidation();
      libxml_use_internal_errors(true);
      $xml = simplexml_load_string($xmlData);

      if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        foreach ($errors as $error) {}
        return new JsonResponse([
          'message' => 'XML parsing error',
          'data' => $errors,
        ], 400);
      } else {

        $rootElement = $xml->getName();
        $contentTypeId = 'knowledge_base';

        if (!$validation->isContentTypeExists($contentTypeId)) {
          return new JsonResponse([
            'message' => 'Content type does not exist',
          ], 404);
        }

        if ($rootElement === 'Technote__kavList') {
          $processor = new EntrustTechnote();
          $processedData = $processor->receiveXml($request);
        } elseif ($rootElement === 'Error_Code__kavList') {
          $processor = new EntrustErrorCode();
          $processedData = $processor->receiveXml($request);
        } else {
          $processedData = ['Only ErrorCode and Technote are acceptable'];
        }
        if(!empty($processedData)){

          $message = 'Success';
        }
        else{
          $message = 'Failed';
        }
        return new JsonResponse([
          'Message' => $message,
          'Data Response' => $processor->getMessages()
        ]);
      }
    } else {
      return $authorizationResponse;
    }
  }
}
