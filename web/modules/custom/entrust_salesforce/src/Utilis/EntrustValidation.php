<?php

namespace Drupal\entrust_salesforce\Utilis;

use Drupal\node\Entity\NodeType;

class EntrustValidation {
    public static function isContentTypeExists($contentTypeId) {
        $contentType = NodeType::load($contentTypeId);
        return !empty($contentType);
    }

    public static function stripTagsAndPreserveAttributes($content) {
      // Define a list of allowed tags and the attributes to preserve.
      $allowedTags = '<a><b><i><u><ul><ol><li><h1><br>';
      $allowedAttributes = 'href,title';

      // Use a regular expression to find and replace tags not in the allowed list.
      $pattern = '/<([^' . $allowedTags . ']+)[^>]*>(.*?)<\/\1>/i';
      $content = preg_replace($pattern, '$2', $content);

      // Create an array of allowed attributes and their values.
      $allowedAttributesArray = explode(',', $allowedAttributes);

      // Iterate through the allowed attributes and preserve them.
      foreach ($allowedAttributesArray as $attribute) {
          // Create a regular expression pattern to match the attribute.
          $pattern = '/(' . $attribute . '=["\']([^"\']+)["\'])/';

          // Replace the matched attribute with itself (preserving it).
          $content = preg_replace($pattern, '$1', $content);
      }

      return $content;
    }
}
