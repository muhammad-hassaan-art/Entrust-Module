<?php

namespace Drupal\entrust_salesforce\Utilis;

use DOMDocument;
use Drupal\Core\Datetime\DrupalDateTime;
use Symfony\Component\Filesystem\Filesystem;


/**
 * Class KnowledgeBase.
 *
 * Provides utility functions for handling knowledge base data.
 */
class KnowledgeBase
{
    /**
     * The mode value retrieved from the database.
     *
     * @var mixed
     */
    protected $mode;

    public $msgString = [];

    /**
     * KnowledgeBase constructor.
     *
     * Retrieves the mode value from the database.
     */
    public function __construct()
    {
        $query = \Drupal::database()->select('entrust_salesforce_save_password', 'esp')
            ->fields('esp', ['Mode'])
            ->condition('esp.ID', 1)
            ->execute();
        $this->mode = $query->fetchField();
    }

    /**
     * Generates an alias based on brand division and URL name.
     *
     * @param string $brandDivision
     *   The brand division value.
     * @param string $urlName
     *   The URL name value.
     * @param string $external_id
     *   The external ID.
     *
     * @return string
     *   The generated alias.
     */
    protected function generateAlias($brandDivision, $urlName, $external_id)
    {
        try {
            $urlName = strtolower($urlName);
            if ($brandDivision == 'Datacard') {
                return '/knowledgebase/hardware/' . $urlName;
            } elseif ($brandDivision == 'ECS' || empty($brandDivision)) {
                return '/knowledgebase/ssl/' . $urlName;
            }
            $this->createMessage('Path alias generated successfully.', __FUNCTION__, $external_id, 1);
        } catch (\Exception $e) {
            $this->createMessage('Error generating alias: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
        }
    }

    /**
     * Saves a path alias in the system.
     *
     * @param string $path
     *   The path to be aliased.
     * @param string $alias
     *   The desired alias.
     * @param string $external_id
     *   The external ID.
     */
    protected function savePathAlias($path, $alias, $external_id)
    {
        try {
            $path_alias = \Drupal\path_alias\Entity\PathAlias::create([
                'path' => $path,
                'alias' => $alias,
            ]);
            $path_alias->save();
            $this->createMessage('Path alias saved successfully.', __FUNCTION__, $external_id, 1);
        } catch (\Exception $e) {
            $this->createMessage('Error saving path alias: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
        }
    }

    /**
     * Retrieves the term ID for a given content type name.
     *
     * @param string $name
     *   The content type name.
     * @param string $external_id
     *   The external ID.
     *
     * @return null|int
     *   The term ID, or null if not found.
     */
    protected function getContentTypeTermId($name, $external_id)
    {
        try {
            $vid = 'content_type_kb';
            $term_name = $name;

            $termId = $this->getTermId($vid, $term_name);

            if ($termId !== null) {
                $this->createMessage('Content type term ID retrieved successfully.', __FUNCTION__, $external_id, 1);
            } else {
                $this->createMessage('Content type term ID not found.', __FUNCTION__, $external_id, 0);
            }

            return $termId;
        } catch (\Exception $e) {
            $this->createMessage('Error getting content type term ID: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
            return null;
        }
    }

    /**
     * Retrieves the term ID for a given vocabulary and term name.
     *
     * @param string $vid
     *   The vocabulary ID.
     * @param string $term_name
     *   The term name.
     *
     * @return null|int
     *   The term ID, or null if not found.
     */
    private function getTermId($vid, $term_name)
    {
        $term_storage = \Drupal::service('entity_type.manager')->getStorage('taxonomy_term');
        $terms = $term_storage->loadByProperties(['vid' => $vid, 'name' => $term_name]);

        if (!empty($terms)) {
            $term = reset($terms);
            return $term->id();
        }

        return null;
    }

    /**
     * Checks for existing nodes with a given external ID.
     *
     * @param mixed $external_id
     *   The external ID to check.
     *
     * @return array
     *   An array of existing node IDs.
     */
    protected function checkExistingNodes($external_id)
    {
        $query = \Drupal::entityQuery('node')
            ->condition('type', 'knowledge_base')
            ->condition('field_id', $external_id)
            ->accessCheck(false);

        return $query->execute();
    }

    /**
     * Formats and returns Last Modified and Created dates.
     *
     * @param array $data
     *   The data array containing LastModifiedDate and CreatedDate.
     *
     * @return array
     *   An array with formatted Last Modified and Created dates.
     */
    protected function formatDates($data, $external_id)
    {
        try {
            $formatted_last_modified_date = '';
            $formatted_created_date = '';

            if (!empty($data['LastModifiedDate']) && !empty($data['CreatedDate'])) {
                $last_modified_date = new DrupalDateTime($data['LastModifiedDate']);
                $created_date = new DrupalDateTime($data['CreatedDate']);
                $formatted_last_modified_date = $last_modified_date->format('Y-m-d\TH:i:s');
                $formatted_created_date = $created_date->format('Y-m-d\TH:i:s');

                $this->createMessage('Dates formatted successfully.', __FUNCTION__, $external_id, 1);
            } else {
                $this->createMessage('Error formatting dates: LastModifiedDate or CreatedDate is empty.', __FUNCTION__, $external_id, 0);
            }

            return [$formatted_last_modified_date, $formatted_created_date];
        } catch (\Exception $e) {
            $this->createMessage('Error formatting dates: ' . $e->getMessage(), __FUNCTION__, $external_id);
            return ['', ''];
        }
    }

    /**
     * Retrieves the term ID for a given brand division value.
     *
     * @param array $data
     *   The data array containing BrandDivision.
     * @param string $external_id
     *   The external ID.
     *
     * @return null|int
     *   The term ID, or null if not found.
     */
    protected function getBrandDivisionTermId($data, $external_id)
    {
        try {
            $brand_division_tid = null;

            $termMappings = [
                'Datacard' => 'Datacard',
                'ECS' => 'ECS',
            ];

            $brandDivisionValue = $data['BrandDivision'];

            if (isset($termMappings[$brandDivisionValue])) {
                $term_name = $termMappings[$brandDivisionValue];
                $vid = 'brand_division_kb';
                $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
                $terms = $term_storage->loadByProperties(['vid' => $vid, 'name' => $term_name]);

                if (!empty($terms)) {
                    $term = reset($terms);
                    $brand_division_tid = $term->id();

                    $this->createMessage('Brand division term ID retrieved successfully.', __FUNCTION__, $external_id, 1);
                } else {
                    $this->createMessage('Error retrieving brand division term ID: Term not found.', __FUNCTION__, $external_id, 0);
                }
            } else {
                $this->createMessage('Error retrieving brand division term ID: BrandDivision value not mapped.', __FUNCTION__, $external_id, 0);
            }

            return $brand_division_tid;
        } catch (\Exception $e) {
            $this->createMessage('Error retrieving brand division term ID: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
            return null;
        }
    }

    /**
     * Retrieves term IDs for given data category names.
     *
     * @param array $dataCategoryNames
     *   An array of data category names.
     * @param string $external_id
     *   The external ID.
     *
     * @return array
     *   An array of term IDs.
     */
    protected function getProductsTermIds($dataCategoryNames, $external_id, $node_id)
    {
        $productString = [];
        try {
            $products_term_ids = [];

            foreach ($dataCategoryNames as $term_name) {
                $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
                    'name' => $term_name,
                    'vid' => 'products_kb',
                ]);

                if (!empty($term)) {
                    $term = reset($term);
                    $products_term_ids[] = $term->id();
                } else {
                    $productString[] = "'Error retrieving product term ID: Term not found for name ' . $term_name .' For External Id '. $external_id . ' against Node Id ' . $node_id";
                    \Drupal::logger('Entrust')->info('Error retrieving product term ID: Term not found for name ' . $term_name . ' For External Id ' . $external_id . ' against Node Id ' . $node_id);
                }
            }
            $this->createMessage(json_encode($productString), __FUNCTION__, $external_id, 0);


            return $products_term_ids;
        } catch (\Exception $e) {
            $this->createMessage('Error retrieving product term IDs: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
            return [];
        }
    }

    /**
     * Decreases the level of heading tags in the given HTML content.
     *
     * Replaces 'h1' with 'h2' if it matches the node title,
     * and subsequent 'h1' tags with 'h2'.
     *
     * @param string $content
     *   The HTML content string.
     * @param string $nodeTitle
     *   The title of the node.
     * @param string $external_id
     *   The external ID.
     *
     * @return string
     *   The content with decreased heading tag levels.
     */
    protected function replaceTag($content, $nodeTitle, $external_id)
    {
        try {
            // Remove title if it is duplicated
            $titlePos = strpos($content, $nodeTitle);
            if ($titlePos !== false) {
                $content = preg_replace('/<[^>]+>\s*' . $nodeTitle . '\s*<\/[^>]+>/i', '', $content);
            }
            // If there is any h1 inside of the body change h levels
            if (substr_count($content, "<h1") > 0) {
                for ($i = 6; $i > 0; $i--) {
                    $content = preg_replace('/<h' . $i . '([^>]*)>/i', '<h' . ($i + 1) . '\1>', $content);
                    $content = preg_replace('/<\/h' . $i . '>/i', '</h' . ($i + 1) . '>', $content);
                }
            }
            return $content;
        } catch (\Exception $e) {
            $this->createMessage('Error replacing heading tags: ' . $e->getMessage(), __FUNCTION__, $external_id);
            return $content;
        }
    }


    /**
     * Replaces span tags with specific style attributes to code tags.
     *
     * @param string $content
     *   The HTML content string.
     * @param string $external_id
     *   The external ID.
     *
     * @return string
     *   The content with span tags replaced by code tags.
     */
    protected function replaceSpanWithCode($content, $external_id)
    {
        try {
            // Find span tags with style attribute containing font-family: Courier or Courier New.
            $pattern = '/<span[^>]*style="[^"]*\bfont-family\s*:\s*(Courier|Courier New)[^"]*"[^>]*>(.*?)<\/span>/i';

            // Replace with code tags.
            $replacement = '<code>$2</code>';

            $content = preg_replace($pattern, $replacement, $content);

            return $content;
        } catch (\Exception $e) {
            $this->createMessage('Error replacing span tags with code tags: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
            return $content;
        }
    }


    /**
     * Replaces ol tags with specific list-style-type styles to classes.
     *
     * @param string $content
     *   The HTML content string.
     * @param string $external_id
     *   The external ID.
     *
     * @return string
     *   The content with ol tags replaced by classes.
     */
    protected function replaceOlStyleWithClasses($content, $external_id)
    {
        // Find all ol elements with style and start attributes
        preg_match_all('/<ol[^>]*style=[\'"]([^\'"]+)[\'"][^>]*>/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {

            $styleAttribute = isset($match[1]) ? $match[1] : '';

            // Extract list-style-type value from style attribute
            preg_match('/list-style-type:\s*([^;]*)/', html_entity_decode($styleAttribute), $typeMatches);
            $listStyleType = isset($typeMatches[1]) ? trim($typeMatches[1]) : '';

            // Replace hyphens with underscores in list-style-type
            $listStyleTypeClass = str_replace('-', '_', $listStyleType);

            // Replace the original ol tag with the new class and start attribute
            $replacement = str_replace('style="' . $styleAttribute . '"', 'style="' . $styleAttribute . '" class="ol_' . $listStyleTypeClass . '"', $match[0]);
            $content = preg_replace('/' . preg_quote($match[0], '/') . '/', $replacement, $content, 1);
        }

        return $content;
    }


    /**
     * Removes tags and their contents if style contains display: none.
     *
     * @param string $content
     *   The HTML content string.
     * @param string $external_id
     *   The external ID.
     *
     * @return string
     *   The content with tags removed if style contains display: none.
     */
    protected function removeTagsWithDisplayNone($content, $external_id)
    {
        try {
            // Find tags with style attribute containing display: none.
            $pattern = '/<([^>]+) style="[^"]*display:\s*none[^"]*">(.+?)<\/\1>/s';

            // Remove the tags and their contents.
            $replacement = '';

            $content = preg_replace($pattern, $replacement, $content);
            return $content;
        } catch (\Exception $e) {
            $this->createMessage('Error removing tags with display: none: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
            return $content;
        }
    }


    /**
     * Removes style attributes from HTML content.
     *
     * @param string $content
     *   The HTML content string.
     * @param string $external_id
     *   The external ID.
     *
     * @return string
     *   The content with style attributes removed.
     */
    protected function removeStyleAttributes($content, $external_id)
    {
        try {
            $content = preg_replace('/ style="[^"]*"/', '', $content);
            return $content;
        } catch (\Exception $e) {
            $this->createMessage('Error removing style attributes: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
            return $content;
        }
    }

    /**
     * Replace <b> tags with <strong> tags, including closing tags.
     *
     * @param string $content The HTML content to be processed.
     * @param string $external_id The external ID.
     *
     * @return string The modified HTML content.
     */
    protected function replaceBWithStrong($content, $external_id)
    {
        try {
            $content = preg_replace('/<b>/', '<strong>', $content);
            $content = preg_replace('/<\/b>/', '</strong>', $content);
            return $content;
        } catch (\Exception $e) {
            $this->createMessage('Error replacing <b> with <strong>: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
            return $content;
        }
    }

    /**
     * Remove empty <span>, <blockquote>, and <ul> tags from the content.
     *
     * @param string $content The HTML content.
     * @param string $external_id The external ID.
     *
     * @return string The updated HTML content.
     */
    protected function removeEmptyTags($content, $external_id)
    {
        try {
            // Remove empty <span> tags
            $content = preg_replace('/<span[^>]*><\/span>/', '', $content);

            // Remove empty <blockquote> tags
            $content = preg_replace('/<blockquote[^>]*><\/blockquote>/', '', $content);

            // Remove empty <ul> tags
            $content = preg_replace('/<ul[^>]*><\/ul>/', '', $content);

            return $content;
        } catch (\Exception $e) {
            $this->createMessage('Error removing empty tags: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
            return $content;
        }
    }

    /**
     * Remove border and align attributes from <img> tags.
     *
     * @param string $content The HTML content.
     * @param string $external_id The external ID.
     *
     * @return string The updated HTML content.
     */
    protected function removeImgAttributes($content, $external_id)
    {
        try {
            // Remove border and align attributes from <img> tags
            $content = preg_replace('/<img([^>]*)\s(border|align)\s*=\s*"[^"]*"/i', '<img$1', $content);

            return $content;
        } catch (\Exception $e) {
            $this->createMessage('Error removing <img> attributes: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
            return $content;
        }
    }


    /**
     * Remove border, cellpadding, and cellspacing attributes from <table> tags.
     *
     * @param string $content The HTML content.
     * @param string $external_id The external ID.
     *
     * @return string The updated HTML content.
     */
    protected function removeTableAttributes($content, $external_id)
    {
        try {
            // Remove border, cellpadding, and cellspacing attributes from <table> tags
            $content = preg_replace('/<table[^>]*(\s*(border|cellpadding|cellspacing)\s*=\s*"[^"]*")*[^>]*>/i', '<table>', $content);

            return $content;
        } catch (\Exception $e) {
            $this->createMessage('Error removing <table> attributes: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
            return $content;
        }
    }


    /**
     * Replace font tags by their children.
     *
     * @param string $content The HTML content string.
     * @param string $external_id The external ID.
     *
     * @return string The content with font tags replaced by their children.
     */
    protected function replaceFontWithChildren($content, $external_id)
    {
        try {
            $pattern = '/<font[^>]*>(.*?)<\/font>/s';

            // Replace with the content inside the font tags.
            $replacement = '$1';

            $content = preg_replace($pattern, $replacement, $content);
            return $content;
        } catch (\Exception $e) {
            $this->createMessage('Error replacing <font> with children: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
            return $content;
        }
    }


    /**
     * Handles and processes images in the given content.
     *
     * @param string $content
     *   The content containing images.
     * @param string $external_id
     *   The external ID.
     *
     * @return string
     *   The content with processed images.
     */
    protected function handleImages($content, $external_id)
    {
        try {
            preg_match_all('/<img[^>]+src="([^"]+)"/', $content, $imageMatches);

            foreach ($imageMatches[1] as $imageUrl) {
                if (strpos($imageUrl, 'entrust.com') !== false) {
                    $imageParts = explode('/', $imageUrl);
                    $originalImageName = end($imageParts);
                    if (!$this->imageExists($originalImageName)) {
                        $originalImageName = $this->copyImage($imageUrl, $originalImageName);
                    } else {
                        $originalImageName = '/sites/default/files/images/' . $originalImageName;
                    }
                    if ($originalImageName) {
                        $content = str_replace($imageUrl, $originalImageName, $content);
                    }
                }
            }

            $this->createMessage('Images handled successfully.', __FUNCTION__, $external_id, 1);
            return $content;
        } catch (\Exception $e) {
            $this->createMessage('Error handling images: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
            return $content;
        }
    }


    /**
     * Checks if an image file exists.
     *
     * @param string $imageName
     *   The image file name.
     *
     * @return bool
     *   TRUE if the image file exists, FALSE otherwise.
     */
    protected function imageExists($imageName)
    {
        return file_exists('public://images/' . $imageName);
    }

    /**
     * Copies and saves an image from a URL.
     *
     * @param string $imageUrl
     *   The URL of the image to be copied.
     * @param string $imageName
     *   The desired name for the copied image.
     *
     * @return string|null
     *   The public URL of the copied image, or null if unsuccessful.
     */
    protected function copyImage($imageUrl, $imageName)
    {
        $imageContent = file_get_contents($imageUrl);

        $imageInfo = getimagesize($imageUrl);

        if ($imageInfo !== false && isset($imageInfo['mime'])) {

            $extension = image_type_to_extension($imageInfo[2]);

            $originalExtension = pathinfo($imageName, PATHINFO_EXTENSION);

            // Check if the original name already has an extension
            if (empty($originalExtension)) {
                $fullFilename = $imageName . $extension;
            } else {
                $fullFilename = $imageName;
            }

            $directory = 'public://images/';
            $filepath = $directory . $fullFilename;

            $filesystem = new Filesystem();
            $filesystem->mkdir($directory, 0777, true);

            file_put_contents($filepath, $imageContent);

            $file_storage = \Drupal::service('entity_type.manager')->getStorage('file');
            $file = $file_storage->create([
                'filename' => $fullFilename,
                'uri' => $filepath,
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

    /**
     * Update anchor tags, replacing 'name' attributes with 'id' attributes.
     *
     * @param string $content
     *   The HTML content to update.
     *
     * @return string
     *   The content with updated anchor tags.
     */
    protected function updateAnchorIds($content, $external_id)
    {
        // Use regular expressions to replace name attributes with id attributes
        $content = preg_replace_callback(
            '/<a\s+([^>]*)name=[\'"]([^\'"]*)[\'"]([^>]*)>/i',
            function ($matches) {
                $attributes = $matches[1];
                $name = $matches[2];
                $rest = $matches[3];

                if (strpos($attributes, 'id=') === false) {
                    // Generate an ID based on the name
                    $generatedId = $this->generateUniqueId($name);
                    return "<a $attributes id=\"$generatedId\"$rest>";
                }
                return $matches[0];
            },
            $content
        );

        return $content;
    }

    /**
     * Generate a unique ID based on the provided base ID.
     *
     * @param string $baseId
     *   The base ID to generate from.
     *
     * @return string
     *   The unique ID.
     */

    protected function generateUniqueId($baseId)
    {
        $uniqueId = $baseId;

        // Append a number until a unique ID is found
        $i = 1;
        while (preg_match('/\b' . preg_quote($uniqueId, '/') . $i . '\b/i', $uniqueId)) {
            $i++;
        }

        $uniqueId .= $i;

        return $uniqueId;
    }

    /**
     * Creates a message for a specific action.
     *
     * @param string $message
     *   The message string to be displayed.
     * @param string $functionName
     *   The name of the function where the message is created.
     * @param mixed $externalId
     *   The external ID associated with the action, if applicable.
     */
    protected function createMessage($message, $functionName, $externalId = null, $status = 1)
    {
        // Store the message information for later retrieval.
        if ($status == 0) {
            $this->msgString[$externalId][$functionName] = $message;
        } else {
            unset($this->msgString[$externalId][$functionName]);
        }
    }

    /**
     * Retrieves the stored messages.
     *
     * @return array
     *   An array containing stored messages. The array structure is keyed by
     *   external ID and function name, providing a log of messages generated
     *   during specific actions.
     */
    public function getMessages()
    {
        return $this->msgString;
    }

    /**
     * Gets the data category names from the provided data category selections.
     *
     * @param \SimpleXMLElement $dataCategorySelections
     *   The data category selections to process.
     * @param string $external_id
     *   The external ID.
     *
     * @return array
     *   An array of data category names.
     */
    protected function getDataCategoryNames($dataCategorySelections, $external_id)
    {
        try {
            $dataCategoryNames = [];

            foreach ($dataCategorySelections as $categorySelection) {
                $dataCategoryGroupName = (string)$categorySelection->DataCategoryGroupName;

                if ($dataCategoryGroupName === 'Product_Family') {
                    $dataCategoryName = (string)$categorySelection->DataCategoryName;

                    // Split the data category name using underscores
                    $parts = explode('_', $dataCategoryName);

                    // Join the parts with spaces
                    $formattedCategoryName = implode(' ', $parts);
                    $dataCategoryNames[] = $formattedCategoryName;
                }
            }

            $this->createMessage('Data category names retrieved successfully.', __FUNCTION__, $external_id, 1);
            return $dataCategoryNames;
        } catch (\Exception $e) {
            $this->createMessage('Error getting data category names: ' . $e->getMessage(), __FUNCTION__, $external_id, 0);
            return [];
        }
    }
}
