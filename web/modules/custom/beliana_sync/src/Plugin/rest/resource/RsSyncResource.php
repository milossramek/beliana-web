<?php

namespace Drupal\beliana_sync\Plugin\rest\resource;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\media_entity\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "rs_sync_resource",
 *   label = @Translation("RS Sync"),
 *   uri_paths = {
 *     "canonical" = "/rs/api/{nid}",
 *     "https://www.drupal.org/link-relations/create" = "/rs/api"
 *   }
 * )
 */
class RsSyncResource extends ResourceBase {

  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a Drupal\rest\Plugin\ResourceBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A current user instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);

    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('beliana_sync'),
      $container->get('current_user')
    );
  }

  /**
   * Create new article.
   *
   * @param array $data
   *   Array of incoming data.
   *
   * @return \Drupal\rest\ResourceResponse
   *   Return 201 on success with NID.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function post($data) {
    if (!$this->currentUser->hasPermission('create article content')) {
      throw new AccessDeniedHttpException();
    }
    $node = Node::create([
      'type' => 'word',
      'title' => $data['title'],
      'field_date' => $data['dates'],
      'field_sort' => $data['sort'],
    ]);
    $local_fids = $this->downloadMedia($data['images']);
    if (!empty($local_fids)) {
      $node->field_images = $local_fids;
    }
    $modified_body = $this->downloadBodyImages($data['body']);
    $node->body = ['value' => $modified_body, 'format' => 'basic_html'];
    $node->field_alphabet = $this->assignAlphabetGroup($data['title']);
    $node->save();
    return new ResourceResponse($node->id(), 201);
  }

  /**
   * Extract alphabet group from title.
   *
   * @param string $title
   *   Title of the node.
   *
   * @return int
   *   ID of alphabet group taxonomy term.
   */
  public function assignAlphabetGroup($title) {
    // We need to compare only first 3 characters of the lowercase string.
    $string_to_compare = mb_substr($title, 0,3);
    $string_to_compare = mb_strtolower($string_to_compare);

    $term = \Drupal::entityQuery('taxonomy_term')
      ->condition('field_last',$string_to_compare,'<')
      ->sort('tid', 'DESC')
      ->range(0,1)
      ->execute();

    return reset($term);
  }

  /**
   * Download attached files and create media entities from them.
   *
   * @param $images
   *   Array with image data.
   *
   * @return array
   *   Array with media IDs.
   */
  public function downloadMedia($images) {
    $taxonomy_terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $local_fids = [];
    foreach ($images as $image) {
      $file_data = file_get_contents($image['uri']);
      /** @var FileInterface $file */
      $file = file_save_data($file_data);
      if ($file !== FALSE) {
        $license = $taxonomy_terms->loadByProperties(['name' => $image['license']]);
        if (empty($license)) {
          $license = $taxonomy_terms->create([
            'name' => $image['license'],
            'vid' => 'licenses'
          ]);
          $license->save();
        }
        $media = Media::create([
          'bundle' => 'image',
          'title' => $image['title'],
          'field_image' => $file->id(),
          'field_licence' => $license->id(),
          'field_license_info' => [
            'value' => $image['license_info'],
            'format' => 'basic_html'
          ],
          'field_description' => $image['description'],
        ]);
        $media->save();
        $local_fids[] = $media->id();
      }
    }
    return $local_fids;
  }

  /**
   * Finds images in text, download them and replace paths.
   *
   * @param string $body
   *   Unprocessed text with images.
   *
   * @return string
   *   Text with replaced image links.
   */
  public function downloadBodyImages($body) {
    // Extract links from field_text_hesla.
    $dom = new \DOMDocument();
    $dom->loadHTML($body);
    /** @var \DOMElement[] $images */
    $images = $dom->getElementsByTagName('img');
    if (!empty($images)) {
      for ($i = $images->length - 1; $i >= 0; $i--) {
        $item = $images->item($i);
        $remote_path = $item->getAttribute('src');
        $remote_site_url = \Drupal::configFactory()
          ->get('beliana.config')
          ->get('remote_url');
        $file_data = file_get_contents($remote_site_url . $remote_path);
        $uri = file_unmanaged_save_data($file_data);
        if ($uri !== FALSE) {
          $item->setAttribute('src', $uri);
        }
      }
    }

    $fragment = '';
    foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $node) {
      $fragment .= $dom->saveHtml($node);
    }
    return $fragment;
  }

  /**
   * Update existing article.
   *
   * @param int $nid
   *   ID of article.
   * @param array $data
   *   Array of incoming data.
   *
   * @return \Drupal\rest\ResourceResponse
   *   Return 200 on success.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Throws exception if used doesnt have enough permissions.
   */
  public function patch($nid, $data) {
    if (!$this->currentUser->hasPermission('edit any article content')) {
      throw new AccessDeniedHttpException();
    }
    $node = Node::load($nid);
    $node->title = $data['title'];
    $modified_body = $this->downloadBodyImages($data['body']);
    $node->body = ['value' => $modified_body, 'format' => 'basic_html'];
    $node->field_date = $data['dates'];
    $node->field_sort = $data['sort'];
    foreach ($node->field_images as $field_image) {
      $media = Media::load($field_image->target_id);
      $media->delete();
    }
    $local_fids = $this->downloadMedia($data['images']);
    if (!empty($local_fids)) {
      $node->field_images = $local_fids;
    }
    $node->field_alphabet = $this->assignAlphabetGroup($data['title']);
    $node->save();
    return new ResourceResponse();
  }

}