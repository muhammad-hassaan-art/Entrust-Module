<?php

namespace Drupal\entrust_salesforce\Utilis;

use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;
use Symfony\Component\Filesystem\Filesystem;
use Drupal\entrust_salesforce\Utilis\KnowledgeBase;

/**
 * Class EntrustTechnote.
 *
 * Handles the processing and storage of XML data for Entrust technical notes.
 */
class EntrustTechnote extends knowledgeBase
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
      ->condition('esp.identifier', 1)
      ->execute();
    $mode = $query->fetchField();

    $processedXmlData = $this->processXmlData($xmlData, $mode);

    $processedData = $processedXmlData['data'];



    $dataCategorySelections = $processedXmlData['dataCategorySelections'];


    foreach ($processedData as $data) {
      $external_id = $data['Id'];
    }

    $processedData = $this->updateTaxonomyTerms($processedData, $mode, $external_id);
    $this->createOrUpdateNodes($processedData, $mode, $dataCategorySelections);
    return $processedData;
  }
  /**
   * Creates or updates nodes based on processed data.
   *
   * @param array $processedData
   *   The processed data array.
   * @param int|null $serverTypeTermId
   *   The term ID for server type.
   * @param int|null $productTypeTermId
   *   The term ID for product type.
   * @param string $mode
   *   The mode for processing.
   */
  private function createOrUpdateNodes($processedData, $mode, $dataCategorySelections)
  {
    foreach ($processedData as $data) {
      $external_id = $data['Id'];
      $node_id = $this->checkExistingNodes($external_id);
      $node_id = reset($node_id);
      list($formatted_last_modified_date, $formatted_created_date) = $this->formatDates($data, $external_id);
      $brand_division_tid = $this->getBrandDivisionTermId($data, $external_id);
      $product_termIds = $this->getProductsTermIds($data['DataCategoryNames'], $external_id, $node_id);
      $htmlContent = $data['Details'];
      $titleExists = strpos($htmlContent, '<h1>') === false;

      $attachmentsHTML = $this->processAttachments($data);
      $htmlContent .= $attachmentsHTML;
      if (!empty($node_id)) {
        $this->updateNodes(
          $node_id,
          $data,
          $brand_division_tid,
          $htmlContent,
          $mode,
          $formatted_last_modified_date,
          $formatted_created_date,
          $product_termIds,
          $external_id,
          $dataCategorySelections
        );
      } else {
        $this->createNode(
          $data,
          $external_id,
          $brand_division_tid,
          $htmlContent,
          $mode,
          $formatted_last_modified_date,
          $formatted_created_date,
          $product_termIds,
          $external_id,
          $dataCategorySelections
        );
      }
    }
  }
  /**
   * Updates an existing node with new data.
   *
   * @param int $node_id
   *   The ID of the node to be updated.
   * @param array $data
   *   The data to update the node.
   * @param int $brand_division_tid
   *   The term ID for brand division.
   * @param string $htmlContent
   *   The HTML content for the node body.
   * @param string $mode
   *   The mode for processing.
   * @param string $formatted_last_modified_date
   *   The formatted last modified date.
   * @param string $formatted_created_date
   *   The formatted created date.
   * @param array $products_term_ids
   *   An array of term IDs for products.
   */
  private function updateNodes(
    $node_id,
    $data,
    $brand_division_tid,
    $htmlContent,
    $mode,
    $formatted_last_modified_date,
    $formatted_created_date,
    $product_termIds,
    $external_id,
    $dataCategorySelections
  ) {
    $node = Node::load($node_id);

    if ($node) {

      $node->title->value = $data['Title'];
      $node->field_sf_last_modified_date->value = $formatted_last_modified_date;
      $node->field_sf_created_date->value = $formatted_created_date;
      $node->field_brand_division->target_id = $brand_division_tid;
      $node->field_server_types = $data['serverTypeTermId'];
      $node->field_product_types =  $data['productTypeTermId'];
      $node->field_products = $product_termIds;
      $node->body->value = $htmlContent;
      $node->body->format = 'full_html';



      $alias = $this->generateAlias($data['BrandDivision'], $data['UrlName'], $external_id);
      $path = '/node/' . $node->id();
      $this->savePathAlias($path, $alias, $external_id);
      $node->save();
      $this->handleNodePublication($node, $data, $mode, $external_id);
      $node->save();
      $this->createMessage('Node Updated successfully.', __FUNCTION__, $external_id, 1);
    }
  }
  /**
   * Creates a new node with the provided data.
   *
   * @param array $data
   *   The data for creating the node.
   * @param string $external_id
   *   The external ID of the node.
   * @param int $brand_division_tid
   *   The term ID for brand division.
   * @param string $htmlContent
   *   The HTML content for the node body.
   *   The mode for processing.
   * @param string $formatted_last_modified_date
   *   The formatted last modified date.
   * @param string $formatted_created_date
   *   The formatted created date.
   * @param array $products_term_ids
   *   An array of term IDs for products.
   */
  private function createNode(
    $data,
    $external_id,
    $brand_division_tid,
    $htmlContent,
    $mode,
    $formatted_last_modified_date,
    $formatted_created_date,
    $product_termIds,
    $dataCategorySelections
  ) {

    $node = Node::create([
      'type' => 'knowledge_base',
      'title' => $data['Title'],
      'field_id' => $external_id,
      'field_content_type' => $this->getContentTypeTermId('Tech Note', $external_id),
      'field_brand_division' => $brand_division_tid,
      'field_sf_last_modified_date' => $formatted_last_modified_date,
      'field_sf_created_date' => $formatted_created_date,
      'field_details' => $data['Details'],
      'field_server_types' => $data['serverTypeTermId'],
      'field_product_types' =>  $data['productTypeTermId'],
      'field_products' =>  $product_termIds,
      'uid' => 1,
    ]);


    $node->body->value = $htmlContent;
    $node->body->format = 'full_html';

    $node->save();

    $this->handleNodePublication($node, $data, $mode, $external_id);
    $alias = $this->generateAlias($data['BrandDivision'], $data['UrlName'], $external_id);
    $node_id = $node->id();
    $path = '/node/' . $node_id;
    $this->savePathAlias($path, $alias, $external_id);
    $node->save();
    $this->createMessage('Node Created successfully.', __FUNCTION__, $external_id, 1);
  }
  /**
   * Handles the publication status of a node based on provided data and mode.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node entity to be handled.
   * @param array $data
   *   An array containing data relevant to the node.
   * @param string $mode
   *   The mode indicating whether it's in production or another environment.
   * @param mixed $external_id
   *   The external ID associated with the action, if applicable.
   */
  private function handleNodePublication($node, $data, $mode, $external_id)
  {
    try {
      if ($mode === 'production' && ($data['serverTypeTermId'] == null || $data['productTypeTermId'] == null)) {
        \Drupal::logger('Entrust')->error('Error: Value not present in the taxonomy for Node ID @node_id. External ID: @external_id', ['@node_id' => $node->id(), '@external_id' => $external_id, 'data' => $data]);
        $node->setUnPublished();
        $this->createMessage('Error: Value not present in the taxonomy and Node Unpublished Succesfully for Node ID ' . $node->id(), __FUNCTION__, $external_id, 0);
      } elseif (empty($data['Details'])) {
        \Drupal::logger('Entrust')->error('Error: Detail_For_External_KB__c is empty for Node ID @node_id. External ID: @external_id', ['@node_id' => $node->id(), '@external_id' => $external_id, 'data' => $data]);
        $node->setUnPublished();
        $this->createMessage('Error: Detail_For_External_KB__c is empty for Node ID ' . $node->id(), __FUNCTION__, $external_id, 0);
      } elseif ($data['PublishStatus'] === 'Online' || ($mode === 'production' && ($data['serverTypeTermId'] != null || $data['productTypeTermId'] != null))) {
        $node->setPublished();
        $this->createMessage('Node published successfully.', __FUNCTION__, $external_id, 1);
      }
      if ($data['PublishStatus'] === 'Archived') {
        $node->setUnPublished();
        $this->createMessage('Node unpublished successfully.', __FUNCTION__, $external_id, 0);
      }
    } catch (\Exception $e) {
      \Drupal::logger('Entrust')->error('Error handling node publication: ' . $e->getMessage(), ['@node_id' => $node->id(), '@external_id' => $external_id, 'data' => $data]);
      $this->createMessage('Error handling node publication: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
    }
  }

  /**
   * Processes XML data and returns parsed data for technical notes.
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
    foreach ($xml->Technote__kav as $technote) {

      $external_id =  (string) $technote->Id;

      $content = (string) $technote->Detail_For_External_KB__c;

      $title = (string) $technote->Title;

      if (!empty($content)) {
        $updatedContent = $this->updateAnchorIds($content, $external_id);
        $details = $this->replaceTag($updatedContent, $title, $external_id);
        $details = $this->replaceSpanWithCode($details, $external_id);
        $details = $this->replaceOlStyleWithClasses($details, $external_id);
        $details = $this->removeTagsWithDisplayNone($details, $external_id);
        $details = $this->removeStyleAttributes($details, $external_id);
        $details = $this->replaceBWithStrong($details, $external_id);
        $details = $this->removeEmptyTags($details, $external_id);
        $details = $this->removeImgAttributes($details, $external_id);
        $details = $this->removeTableAttributes($details, $external_id);
        $details = $this->replaceFontWithChildren($details, $external_id);
      }

      if ($mode === 'development') {
        $details = $this->handleImages($details, $external_id);
      }

      $attachments = $this->processAttachmentsData($technote);

      $dataCategorySelections = $technote->DataCategorySelections->Technote__DataCategorySelection;
      $dataCategoryNames = [];

      $dataCategoryNames = $this->getDataCategoryNames($dataCategorySelections, $external_id);


      $parsedData[] = [
        'Id' => $external_id,
        'Language' => (string) $technote->Language,
        'Title' => (string) $title,
        'UrlName' => (string) $technote->UrlName,
        'LastModifiedDate' => (string) $technote->LastModifiedDate,
        'CreatedDate' => (string) $technote->CreatedDate,
        'ReferenceNumber' => (string) $technote->Reference_Number__c,
        'Summary' => (string) $technote->Summary,
        'BrandDivision' => (string) $technote->Brand_Division__c,
        'Module' => (string) $technote->Modules__c,
        'ErrorCode' => (string) $technote->Error_Code__c,
        'Problem' => (string) $technote->Problem_for_External_KB__c,
        'ServiceCode' => (string) $technote->Service_Code_s__c,
        'ServerTypes' => (string) $technote->ECS_Server_Type__c,
        'TechnoteTypes' => (string) $technote->ECS_Technote_Type__c,
        'ProductTypes' => (string) $technote->ECS_Product_Type__c,
        'PublishStatus' => (string) $technote->PublishStatus,
        'Details' => $details,
        'Attachments' => $attachments,
        'DataCategoryNames' => $dataCategoryNames,
      ];
    }
    return [
      'data' => $parsedData,
      'dataCategorySelections' => $dataCategorySelections
    ];
  }
  /**
   * Updates taxonomy terms based on processed data.
   *
   * @param array $processedData
   *   The processed data array.
   *
   * @return array
   *   An array containing term IDs for server type and product type.
   */
  private function updateTaxonomyTerms($processedData, $mode, $external_id)
  {

    foreach ($processedData as $key => $data) {
      $serverTypes[] = $data['ServerTypes'];
      $productTypes[] = $data['ProductTypes'];
      // Initialize term IDs.
      $processedData[$key]['serverTypeTermId'] = $this->updateSingleNodeTerms($data['ServerTypes'], $mode, 'server_types_kb');
      $processedData[$key]['productTypeTermId'] = $this->updateSingleNodeTerms($data['ProductTypes'], $mode, 'product_types_kb');
    }
    return $processedData;
  }

  /**
   * Creates or Updates a single term
   *
   * @param string $value
   *   The processed data array.
   *
   * @return int
   *   termId
   */
  private function updateSingleNodeTerms($value, $mode, $vid)
  {
    if (!empty($value)) {
      // Check if the term already exists.
      $termId = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadByProperties([
          'name' => $value,
          'vid' => $vid,
        ]);
      //check if there's an ID
      if (!empty($termId)) {
        $termId = (reset($termId))->id();
      }
      //if id not found , check mode and create taxonomy
      elseif (empty($termId) && $mode === 'development') {
        $term = \Drupal\taxonomy\Entity\Term::create([
          'vid' => $vid,
          'name' => $value,
        ]);
        $term->save();
        // Set term ID.
        $termId = $term->id();
      }
      return $termId;
    }
  }
  /**
   * Processes attachments data and returns HTML representation.
   *
   * @param array $data
   *   The data containing attachments.
   *
   * @return string
   *   The HTML representation of attachments.
   */
  private function processAttachments($data)
  {
    $attachmentsHTML = '<ul>';

    if (isset($data['Attachments']) && is_array($data['Attachments'])) {
      foreach ($data['Attachments'] as $attachment) {
        $attachmentName = $attachment['Name'];
        $attachmentContentType = $attachment['ContentType'];
        // Assuming 'Body' contains the base64-encoded attachment data
        $base64Data = $attachment['Body'];

        if (!empty($attachmentName) && !empty($base64Data)) {
          // You can use $attachmentContentType to set the appropriate content type for the link
          $attachmentURL = $this->decodeBase64AndSaveFile($base64Data, $data['Id'], $attachmentName);

          if ($attachmentURL) {
            $attachmentsHTML .= '<li><a href="' . $attachmentURL . '" target="_blank">' . $attachmentName . '</a></li>';
          }
        }
      }
    }

    $attachmentsHTML .= '</ul>';

    return $attachmentsHTML;
  }

  /**
   * Processes attachment data from technical note XML.
   *
   * @param \SimpleXMLElement $technote
   *   The SimpleXMLElement representing technical note.
   *
   * @return array
   *   An array containing attachment data.
   */
  private function processAttachmentsData($technote)
  {
    $attachments = [];

    // Iterate through attachment nodes
    for ($i = 1; $i <= 3; $i++) {
      $attachmentName = (string) $technote->{"Attachment_{$i}__Name__s"};
      $contentType = (string) $technote->{"Attachment_{$i}__ContentType__s"};
      $attachmentBody = (string) $technote->{"Attachment_{$i}__Body__s"};

      // Check if all attachment fields are not empty
      if (!empty($attachmentName) && !empty($contentType) && !empty($attachmentBody)) {
        // Add attachment data to the attachments array
        $attachments[] = [
          'Name' => $attachmentName,
          'ContentType' => $contentType,
          'Body' => $attachmentBody,
        ];
      }
    }

    return $attachments;
  }


  /**
   * Decodes base64 data and saves it as a file.
   *
   * @param string $base64Data
   *   The base64-encoded data.
   * @param string $articleId
   *   The article ID for creating a directory.
   * @param string $attachmentName
   *   The name of the attachment file.
   *
   * @return string|null
   *   The URL of the saved file or NULL on failure.
   */
  private function decodeBase64AndSaveFile($base64Data, $articleId, $attachmentName)
  {

    $base64Data = str_replace('\/', '/', $base64Data);
    $decodedData = base64_decode($base64Data);

    if ($decodedData) {
      $directory = 'public://' . $articleId;
      $filesystem = new Filesystem();
      $filesystem->mkdir($directory, 0777, True);
      $filePath = $directory . '/' . $attachmentName;

      // Save the decoded data to the file
      file_put_contents($filePath, $decodedData);

      // Create a file entity for the saved attachment
      $file_storage = \Drupal::entityTypeManager()->getStorage('file');
      $file = $file_storage->create([
        'filename' => $attachmentName,
        'uri' => $filePath,
      ]);
      $file->setPermanent();
      $file->save();

      if ($file) {
        $file_path = $file->getFileUri();
        $fileUrlGenerator = \Drupal::service('file_url_generator');
        return $fileUrlGenerator->generateString($file_path);
      }
    }
    return null;
  }
}
