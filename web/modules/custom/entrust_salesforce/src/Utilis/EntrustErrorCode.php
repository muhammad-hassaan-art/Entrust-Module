<?php

namespace Drupal\entrust_salesforce\Utilis;

use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;

use Drupal\entrust_salesforce\Utilis\KnowledgeBase;

/**
 * Class EntrustErrorCode.
 *
 * Handles the processing and storage of XML data for Entrust error codes.
 */
class EntrustErrorCode extends knowledgeBase
{
  /**
   * Receives XML data and processes it.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object containing XML data.
   *
   * @return array
   *   The processed data.
   */
  public function receiveXml(Request $request)
  {
    $xmlData = $request->getContent();
    $query = \Drupal::database()->select('entrust_salesforce_save_password', 'esp')
      ->fields('esp', ['Mode'])
      ->condition('esp.identifier', 1) // Replace with the appropriate condition to fetch the mode.
      ->execute();
    $mode = $query->fetchField();
    $processedXmlData = $this->processXmlData($xmlData, $mode);

    $processedData = $processedXmlData['data'];

    $dataCategorySelections = $processedXmlData['dataCategorySelections'];
    $this->createOrUpdateNodes($processedData, $dataCategorySelections);

    return $processedData;
  }
  /**
   * Creates or updates nodes based on processed data.
   *
   * @param array $processedData
   *   The processed data array.
   *
   * @return array
   *   An array of created or updated nodes.
   */
  private function createOrUpdateNodes($processedData, $dataCategorySelections)
  {
    $nodes = [];

    foreach ($processedData as $data) {
      $external_id = $data['Id'];
      $node_id = $this->checkExistingNodes($external_id);
      $node_id = reset($node_id);

      $htmlBodyData = [
        'error' => [
          'string' => '<tr><td>Error Message no.</td><td>',
          'value' => $data['ErrorNumber'],
        ],
        'msg' => [
          'string' => '<tr><td>Message text</td><td>',
          'value' => $data['Message'],
        ],
        'severity' => [
          'string' => '<tr><td>Severity</td><td>',
          'value' => $data['Severity'],
        ],
        'recovery' => [
          'string' => '<tr><td>Recovery Text</td><td>',
          'value' => $data['CausesSolutions'],
        ],
        'video' => [
          'string' => '<tr><td>How to Video</td><td>',
          'value' =>  $data['HowtoVideo'],
        ],
      ];

      $entity_ids = $this->checkExistingNodes($data['Id']);

      list($formatted_last_modified_date, $formatted_created_date) = $this->formatDates($data,$external_id);

      $brand_division_tid = $this->getBrandDivisionTermId($data,$external_id);

      $products_term_ids = $this->getProductsTermIds($data['DataCategoryNames'], $external_id, $node_id);

      if (!empty($entity_ids)) {
        foreach ($entity_ids as $node_id) {
          $node = Node::load($node_id);

          if ($node) {
            $this->updateNode(
              $node,
              $data,
              $formatted_last_modified_date,
              $formatted_created_date,
              $brand_division_tid,
              $products_term_ids,
              $htmlBodyData,
              $external_id,
              $dataCategorySelections
            );
          }
        }
      } else {
        $node = $this->createNode(
          $data,
          $formatted_last_modified_date,
          $formatted_created_date,
          $brand_division_tid,
          $products_term_ids,
          $htmlBodyData,
          $external_id,
          $dataCategorySelections
        );
      }

      $nodes[] = $node;
    }

    return $nodes;
  }
  /**
   * Updates an existing node with new data.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node to be updated.
   * @param array $data
   *   The data to update the node.
   * @param string $formatted_last_modified_date
   *   The formatted last modified date.
   * @param string $formatted_created_date
   *   The formatted created date.
   * @param int $brand_division_tid
   *   The term ID for brand division.
   * @param array $products_term_ids
   *   An array of term IDs for products.
   */

  private function updateNode(
    $node,
    $data,
    $formatted_last_modified_date,
    $formatted_created_date,
    $brand_division_tid,
    $products_term_ids,
    $htmlBodyData,
    $external_id,
    $dataCategorySelections
  ) {

    $this->handleNodePublication($node, $data, $external_id);

    $node->title->value = $data['Title'];
    $node->field_brand_division->target_id = $brand_division_tid;
    $table = $this->generateTable($htmlBodyData, $external_id);

    $node->body->value = $table;
    $node->body->format = 'full_html';
    $node->field_sf_created_date->value = $formatted_created_date;
    $node->field_sf_last_modified_date->value = $formatted_last_modified_date;
    $node->field_products = $products_term_ids;

    $this->saveNode($node);

    $alias = $this->generateAlias($data['BrandDivision'], $data['UrlName'], $external_id);
    $path = '/node/' . $node->id();

    $this->savePathAlias($path, $alias, $external_id);
    $this->createMessage('Node Updated successfully.', __FUNCTION__, $external_id,1);
  }
  /**
   * Creates a new node with the provided data.
   *
   * @param array $data
   *   The data for creating the node.
   * @param string $formatted_last_modified_date
   *   The formatted last modified date.
   * @param string $formatted_created_date
   *   The formatted created date.
   * @param int $brand_division_tid
   *   The term ID for brand division.
   * @param array $products_term_ids
   *   An array of term IDs for products.
   *
   * @return \Drupal\node\Entity\Node
   *   The created node.
   */
  private function createNode(
    $data,
    $formatted_last_modified_date,
    $formatted_created_date,
    $brand_division_tid,
    $products_term_ids,
    $htmlBodyData,
    $external_id,
    $dataCategorySelections
  ) {

    $node = Node::create([
      'type' => 'knowledge_base',
      'title' => $data['Title'],
      'field_id' => $data['Id'],
      'field_content_type' => $this->getContentTypeTermId('Error Code', $external_id),
      'field_brand_division' => $brand_division_tid,
      'field_sf_created_date' => $formatted_created_date,
      'field_sf_last_modified_date' => $formatted_last_modified_date,
      'field_products' => $products_term_ids,
      'uid' => 1,
    ]);

    $table = $this->generateTable($htmlBodyData, $external_id);

    $node->body->value = $table;
    $node->body->format = 'full_html';


    $this->handleNodePublication($node, $data, $external_id);

    $this->saveNode($node);

    $alias = $this->generateAlias($data['BrandDivision'], $data['UrlName'], $external_id);
    $path = '/node/' . $node->id();

    $this->savePathAlias($path, $alias, $external_id);
    $this->createMessage('Node Created successfully.', __FUNCTION__, $external_id,1);

    return $node;
  }
  /**
   * Handles the publication status of a node based on provided data and mode.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node entity to be handled.
   * @param array $data
   *   An array containing data relevant to the node.
   * @param mixed $external_id
   *   The external ID associated with the action, if applicable.
   */
  private function handleNodePublication($node, $data, $external_id)
  {
    try {
      if ($data['PublishStatus'] === 'Online' && (!empty($data['CausesSolutions']))) {
        $node->setPublished();
        $this->createMessage('Node published successfully.', __FUNCTION__, $external_id,1);
      } elseif (empty($data['CausesSolutions'])) {
        \Drupal::logger('Entrust')->error('Error: Causes_Solutions_for_External_KB__c is empty for Node ID @node_id', ['@node_id' => $node->id(), 'data' => $data]);
        $node->setUnpublished();
        $this->createMessage('Error: Causes_Solutions_for_External_KB__c is empty.', __FUNCTION__, $external_id,0);
      } if ($data['PublishStatus'] === 'Archived') {
        $node->setUnpublished();
        $this->createMessage('Node unpublished successfully.', __FUNCTION__, $external_id,0);
      }
    } catch (\Exception $e) {
      \Drupal::logger('Entrust')->error('Error handling node publication: ' . $e->getMessage(), ['@external_id' => $external_id, 'data' => $data]);
      $this->createMessage('Error handling node publication: ' . $e->getMessage(), __FUNCTION__, $external_id,0);
    }
  }

  /**
   * Generates an HTML table based on the provided data.
   *
   * @param array $htmlBody
   *   The data for generating the table.
   * @param mixed $external_id
   *   The external ID associated with the action, if applicable.
   *
   * @return string
   *   The generated HTML table.
   */
  private function generateTable($htmlBody, $external_id)
  {
    try {
      $rowString = '</td></tr>';
      $table = '<table>';
      foreach ($htmlBody as $row => $data) {
        if (!empty($data['value'])) {
          $table .= $data['string'] . $data['value'] . $rowString;
        }
      }
      $table .= '</table>';
      $this->createMessage('HTML table generated successfully.', __FUNCTION__, $external_id,1);
      return $table;
    } catch (\Exception $e) {
      \Drupal::logger('Entrust')->error('Error generating HTML table: ' . $e->getMessage(), ['@external_id' => $external_id, 'data' => $htmlBody]);
      $this->createMessage('Error generating HTML table: ' . $e->getMessage(), __FUNCTION__, $external_id,0);
      return '';
    }
  }


  /**
   * Saves a node.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node to be saved.
   */

  private function saveNode($node)
  {
    $node->save();
  }
  /**
   * Processes XML data and returns parsed data.
   *
   * @param string $xmlData
   *   The XML data to be processed.
   * @param string $mode
   *   The mode for processing.
   *
   * @return array
   *   The parsed data.
   */
  private function processXmlData($xmlData, $mode)
  {
    $xml = simplexml_load_string($xmlData);
    $parsedData = [];

    foreach ($xml->Error_Code__kav as $errorCode) {

      $external_id = (string) $errorCode->Id;
      $content = (string) $errorCode->How_to_Videos__c;
      $title = (string) $errorCode->Title;
      $CausesSolutionsContent = (string) $errorCode->Causes_Solutions_for_External_KB__c;
      $HowtoVedio = $this->removeStyleAttributes($content, $external_id);

      if (!empty($CausesSolutionsContent)) {

        $updatedContent = $this->updateAnchorIds($CausesSolutionsContent, $external_id);
        $CausesSolutions = $this->replaceTag($updatedContent, $title, $external_id);
        $CausesSolutions = $this->replaceSpanWithCode($CausesSolutions, $external_id);
        $CausesSolutions = $this->replaceOlStyleWithClasses($CausesSolutions, $external_id);
        $CausesSolutions = $this->removeTagsWithDisplayNone($CausesSolutions, $external_id);
        $CausesSolutions = $this->removeStyleAttributes($CausesSolutions, $external_id);
        $CausesSolutions = $this->replaceBWithStrong($CausesSolutions, $external_id);
        $CausesSolutions = $this->removeEmptyTags($CausesSolutions, $external_id);
        $CausesSolutions = $this->removeImgAttributes($CausesSolutions, $external_id);
        $CausesSolutions = $this->removeTableAttributes($CausesSolutions, $external_id);
        $CausesSolutions = $this->replaceFontWithChildren($CausesSolutions, $external_id);
      }
      if ($mode === 'development') {
        $CausesSolutions = $this->handleImages($CausesSolutions, $external_id);
      }

      $dataCategorySelections = $errorCode->DataCategorySelections->Error_Code__DataCategorySelection;
      $dataCategoryNames = [];

      $dataCategoryNames = $this->getDataCategoryNames($dataCategorySelections, $external_id);


      $parsedData[] = [
        'Id' => $external_id,
        'Title' => (string) $title,
        'Language' => (string) $errorCode->Language,
        'ErrorNumber' => (int) $errorCode->Error_Number__c,
        'Message' => (string) $errorCode->Error_Message__c,
        'Severity' => (string) $errorCode->Severity__c,
        'HowtoVideo' => $HowtoVedio,
        'CausesSolutions' => $CausesSolutions,
        'BrandDivision' => (string) $errorCode->Brand_Division__c,
        'CreatedDate' => (string) $errorCode->CreatedDate,
        'LastModifiedDate' => (string) $errorCode->LastModifiedDate,
        'UrlName' => (string) $errorCode->UrlName,
        'PublishStatus' => (string) $errorCode->PublishStatus,
        'DataCategoryNames' => $dataCategoryNames,
      ];
    }

    return [
      'data' => $parsedData,
      'dataCategorySelections' => $dataCategorySelections
    ];
  }
}
