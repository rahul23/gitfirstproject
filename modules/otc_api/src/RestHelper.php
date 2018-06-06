<?php

namespace Drupal\otc_api;

use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Entity;
use Drupal\Core\Entity\EntityInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;

class RestHelper implements RestHelperInterface {

    /**
     * For creating entity queries.
     * @var Drupal\Core\Entity\Query\QueryFactory
     */
    protected $queryFactory;

    /**
     * To query entities by uuid
     * @var Drupal\Core\Entity\EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    /**
     * @param QueryFactory $queryFactory entity query factory
     */
    public function __construct() {
        $this->queryFactory = \Drupal::service('entity.query');
        $this->entityTypeManager = \Drupal::service('entity_type.manager');
    }

    /**
     * Get CacheMetaData for content list or specific result.
     * @param  mixed $result processed content array
     * @param  string $entity_type (optional) defaults to node
     *   can be node or taxonomy_term
     * @return CacheableMetadata cache metadata object
     */
    public function cacheMetaData($result, $entity_type = 'node') {
        $cacheMetaData = new CacheableMetadata;
        $cacheMetaData->setCacheContexts(['url']);

        if (empty($result) || !is_array($result)) {
            $result = [];
        }

        if ($entity_type === 'node') {
            return $this->cacheNodeMetaData($cacheMetaData, $result);
        } else if ($entity_type === 'taxonomy_term') {
            return $this->cacheTermMetaData($cacheMetaData, $result);
        }
    }

    /**
     * Get CacheMetaData for term list or specific term result.
     * @return CacheableMetadata cache metadata object
     */
    protected function cacheTermMetaData(CacheableMetadata $cacheMetaData, $result = []) {
        if (!empty($result['tid'])) {
            $cacheMetaData->setCacheTags(['taxonomy_term:' . $result['tid']]);
            return $cacheMetaData;
        }

        $cacheMetaData->setCacheTags(['taxonomy_term']);

        return $cacheMetaData;
    }

    /**
     * Get CacheMetaData for node list or specific result.
     * @return CacheableMetadata cache metadata object
     */
    protected function cacheNodeMetaData(CacheableMetadata $cacheMetaData, $result = []) {
        if (!empty($result['nid'])) {
            $cacheMetaData->setCacheTags(['node:' . $result['nid']]);
            return $cacheMetaData;
        }

        $cacheMetaData->setCacheTags(['node_list']);

        return $cacheMetaData;
    }

    /**
     * validate a content type exists
     * @param  [type]  $contentType [description]
     * @return boolean              [description]
     */
    public static function isContentType($contentType = NULL) {
        return in_array($contentType, array_keys(NodeType::loadMultiple()));
    }

    /**
     * Validate content type string.
     * @param  string $contentType the content type
     * @return boolean
     */
    public static function contentTypePermitted($contentType = NULL) {
        $allowedContentTypes = [
            'landing',
            'article',
            'contributor',
            'download',
            'featured_content',
            'look',
            'product',
            'project',
            'recipe',
            'step',
            'bricky',
        ];

        return in_array($contentType, $allowedContentTypes);
    }

    /**
     * Check to see if a given vocabulary is permitted in the api call.
     * @param  string $vocabulary the vocabulary name/id
     * @return boolean
     */
    protected static function vocabularyPermitted($vocabulary) {
        return in_array($vocabulary, [
            'category',
            'tag',
            'contributor_group',
        ]);
    }

    public function fetchAllIdeas($options = []) {
        $defaults = [
            'page' => 0,
            'published' => true,
            'limit' => 10, // result limit
            'recurse' => true, // toggle off recursion
            'maxDepth' => 2, // deepest level of recursion
            'currentDepth' => 0, // current depth of recursion
            'multiValueGroups' => [],
            'sort' => [
                'field_sort_by_date' => 'DESC',
                'changed' => 'DESC',
            ],
        ];
        $options = array_merge($defaults, $options);

        $category_uuids = [];
        if ($options['category'] && is_array($options['category'])) {
            $category_uuids = $this->lookupTermUuids($options['category']);
            if ($category_uuids) {
                $options['multiValueGroups']['field_category.entity.uuid'] = $category_uuids;
            }
        }

        $tag_uuids = [];
        if ($options['tag'] && is_array($options['tag'])) {
            $tag_uuids = $this->lookupTermUuids($options['tag']);
            if ($tag_uuids) {
                $options['multiValueGroups']['field_tag.entity.uuid'] = $tag_uuids;
            }
        }

        $ideaTypes = array('look', 'project', 'article', 'recipe', 'download', 'bricky');
        $options['multiValueGroups']['type'] = $ideaTypes;
        if ($options['type'] && is_array($options['type'])) {
            $types = array_intersect($options['type'], $ideaTypes);
            if ($types) {
                $options['multiValueGroups']['type'] = $types;
            }
        }

        $limit = $options['limit'];
        $response = [
            'limit' => $limit,
            'page' => $options['page'],
            'published' => $options['published']
        ];

        $response['count'] = intval($this->newNodeQuery($options)->count()->execute());

        $entity_ids = $this->newNodeQuery($options)
                ->range($options['page'] * $limit, $limit)
                ->execute();

        if (!$entity_ids) {
            $response['results'] = [];
            return $response;
        }

        $response['results'] = $this->processNodes(
                \Drupal::entityTypeManager()
                        ->getStorage('node')
                        ->loadMultiple($entity_ids), $options
        );

        return $response;
    }

    /**
     * Fetch a list of nodes from a content type, in clean format for REST.
     * @param  string $contentType the content type
     * @param array $options
     * - integer $page page number (default 0)
     * - boolean $published true for published, false for all. (default true)
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     * @return array of nodes.
     */
    public function fetchAll($actions, $contentType, $options = []) {



        if (!self::isContentType($contentType)) {
            throw new Rest404Exception;
        }

        if (!self::contentTypePermitted($contentType)) {
            throw new Rest403Exception;
        }

        $defaults = [
            'page' => 0,
            'published' => true,
            'limit' => 10, // result limit
            'recurse' => true, // toggle off recursion
            'maxDepth' => 2, // deepest level of recursion
            'currentDepth' => 0, // current depth of recursion
            'conditions' => [
                'type' => $contentType,
            ],
        ];
        if ($contentType === 'contributor') {
            $defaults['sort'] = [
                'field_full_name' => 'ASC',
                'changed' => 'DESC',
            ];
        }
        $options = array_merge($defaults, $options);

        $limit = $options['limit'];
        $response = [
            'limit' => $limit,
            'page' => $options['page'],
            'published' => $options['published']
        ];

        $response['count'] = intval($this->newNodeQuery($options, $actions)->count()->execute());

        $entity_ids = $this->newNodeQuery($options, $actions)
                ->range($options['page'] * $limit, $limit)
                ->execute();

        if (!$entity_ids) {
            $response['results'] = [];
            return $response;
        }


        if ($contentType == "bricky1") {
            foreach ($entity_ids as $entity_ids_value) {
                $response['results'] = $this->fetchAllBricky($options, $entity_ids_value, $actions);
            }
            return $response;
        }


        $response['results'] = $this->processNodes(
                \Drupal::entityTypeManager()
                        ->getStorage('node')
                        ->loadMultiple($entity_ids), $options
        );

        return $response;
    }

    /**
     * Get all terms from a vocabulary.
     * @param  string $vocabulary the vocabulary
     * @param array $options
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     * @return array of terms.
     */
    public function fetchAllTerms($vocabulary, $options = []) {
        if (!in_array($vocabulary, taxonomy_vocabulary_get_names())) {
            throw new Rest404Exception;
        }

        if (!self::vocabularyPermitted($vocabulary)) {
            throw new Rest403Exception;
        }

        $defaults = [
            'page' => 0,
            'limit' => 10, // result limit per page
            'recurse' => true, // toggle off recursion
            'maxDepth' => 2, // deepest level of recursion
            'currentDepth' => 0, // current depth of recursion
        ];
        $options = array_merge($defaults, $options);

        $limit = $options['limit'];
        $response = [
            'limit' => $limit,
            'page' => $options['page'],
        ];

        $response['count'] = intval($this->newTermQuery($vocabulary)->count()->execute());

        $entity_ids = $this->newTermQuery($vocabulary, $options)
                ->range($options['page'] * $limit, $limit)
                ->execute();

        if (!$entity_ids) {
            $response['results'] = [];
            return $response;
        }

        $response['results'] = $this->processTerms(
                \Drupal::entityTypeManager()
                        ->getStorage('taxonomy_term')
                        ->loadMultiple($entity_ids), $options
        );

        return $response;
    }

    /**
     * Get one node by uuid/alias.
     * @param  string $contentType content type for validation
     * @param  string $id uuid/alias of the content
     * @param array $options
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     * @return array processed node, simplified for rest
     */
    public function fetchOne($actions, $contentType, $id = '', $options = []) {

        if (!self::contentTypePermitted($contentType)) {
            throw new Rest403Exception;
        }

        $defaults = [
            'recurse' => true, // toggle off recursion
            'maxDepth' => 2, // deepest level of recursion
            'currentDepth' => 0, // current depth of recursion
        ];
        $options = array_merge($defaults, $options);

        if (self::isUuid($id)) {
            $result = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $id]);
            if (!$result)
                throw new Rest404Exception;

            $node = current($result);
        } else {
            $node = $this->lookupNodeByAlias($id);
        }

        if (!$node || !self::contentTypePermitted($node->getType()) || $node->getType() !== $contentType)
            throw new Rest404Exception;

        return $this->processNode($node, $options, $actions);
    }

    /**
     * Get one term by uuid.
     * @param  string $vocabular type for validation
     * @param  string $id uuid of the term or path alias
     * @param array $options
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     *
     * @return array processed term, simplified for rest
     */
    public function fetchOneTerm($vocabulary, $id = '', $options = []) {
        if (!self::vocabularyPermitted($vocabulary)) {
            throw new Rest403Exception;
        }

        $defaults = [
            'recurse' => true, // toggle off recursion
            'maxDepth' => 2, // deepest level of recursion
            'currentDepth' => 0, // current depth of recursion
        ];
        $options = array_merge($defaults, $options);

        if (self::isUuid($id)) {
            $result = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['uuid' => $id]);
            if (!$result) {
                throw new Rest404Exception;
            }
            $term = current($result);
        } else {
            $term = $this->lookupTermByAlias($id);
        }

        if (!$term) {
            throw new Rest404Exception;
        }

        if (!self::vocabularyPermitted($term->getVocabularyId())) {
            throw new Rest403Exception;
        }

        return $this->processTerm($term, $options);
    }

    /**
     * Fetch all paginated content associated with a particular reference.
     * @param  string $uuid the uuid of the referenced id
     * @param array $options
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     * - integer $page the current page
     *
     * @param  string $field_name the field name referencing a content
     * @return object page of content results for a given reference
     */
    protected function fetchReferencedContent($uuid = '', $options = [], $field_name = 'field_category') {
        $defaults = [
            'page' => 0,
            'limit' => 10, // result limit per page
            'published' => true,
            'conditions' => [
                $field_name . '.entity.uuid' => $uuid,
            ]
        ];

        if ($field_name === 'field_contributor_category') {
            $options['sort'] = [
                'field_full_name' => 'ASC',
                'changed' => 'DESC',
            ];
        }

        if ($options['isReferencedContentBySKU'] == 'yes') {
            $options['sort'] = [
                'created' => 'DESC',
            ];
        }

        $options = array_merge($defaults, $options);

        $limit = $options['limit'];
        $response = [
            'limit' => $limit,
            'page' => $options['page'],
            'published' => $options['published']
        ];

        $response['count'] = intval($this->newNodeQuery($options)->count()->execute());

        $entity_ids = $this->newNodeQuery($options)
                ->range($options['page'] * $limit, $limit)
                ->execute();

        // Return result count content by sku
        if ($options['countSKUContent'] == 'yes') {
            return array('count' => $response['count']);
        }
        if (!$entity_ids) {
            $response['results'] = [];
            return $response;
        }

        $nodes = \Drupal::entityTypeManager()
                ->getStorage('node')
                ->loadMultiple($entity_ids);

        foreach ($nodes as $node) {
            if ($options['recurse']) {
                $response['results'][] = $this->processNode($node, $options);
            } else {
                $response['results'][] = $this->shallowEntity($node);
            }
        }

        return $response;
    }

    /* protected function fetchReferencedProductContent($uuid = '', $options = [], $field_name = 'field_products') {

      $defaults = [
      'page' => 0,
      'limit' => 10, // result limit per page
      'published' => true,
      'conditions' => [
      $field_name . '.entity.uuid' => $uuid,
      ]
      ];

      //$options['recurse'] = true;
      $options = array_merge($defaults, $options);

      $limit = $options['limit'];
      $response = [
      'limit' => $limit,
      'page' => $options['page'],
      'published' => $options['published']
      ];

      $response['count'] = intval($this->newNodeQuery($options)->count()->execute());

      $entity_ids = $this->newNodeQuery($options)
      ->range($options['page'] * $limit, $limit)
      ->execute();

      if ( ! $entity_ids ) {
      $response['results'] = [];
      return $response;
      }

      $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($entity_ids);

      foreach ($nodes as $node) {
      if ($options['recurse']) {
      $response['results'][] = $this->processNode($node, $options);
      } else {
      $response['results'][] = $this->productReferencedEntity($node, $options);
      }
      }

      return $response;
      } */

    /**
     * Fetch all paginated content associated with a particular contributor group.
     * @param  string $id the uuid or path alias of the contributor group
     * @param array $options
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     * - integer $page the current page
     *
     * @return object page of content results for a given contributor group
     */
    public function fetchContributorGroupContent($id = '', $options = []) {
        $uuid = $id;

        if (!self::isUuid($id)) {
            $term = $this->lookupTermByAlias($id);
            if (!$term)
                throw new Rest404Exception;

            $uuid = $term->uuid->value;
        }

        return $this->fetchReferencedContent($uuid, $options, 'field_contributor_category');
    }

    /**
     * Fetch all paginated content associated with a particular category.
     * @param  string $id the uuid or path alias of the category
     * @param array $options
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     * - integer $page the current page
     *
     * @return object page of content results for a given category
     */
    public function fetchCategoryContent($id = '', $options = []) {
        $defaults = [
            'sort' => [
                'field_sort_by_date' => 'DESC',
                'changed' => 'DESC',
            ],
        ];
        $options = array_merge($defaults, $options);

        $uuid = $id;

        if (!self::isUuid($id)) {
            $term = $this->lookupTermByAlias($id);
            if (!$term) {
                throw new Rest404Exception;
            }

            $uuid = $term->uuid->value;
        }

        return $this->fetchReferencedContent($uuid, $options, 'field_category');
    }

    /**
     * Fetch all paginated content associated with a particular contributor.
     * @param  string $id the uuid or path alias of the contributor
     * @param array $options
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     * - integer $page the current page
     *
     * @return object page of content results for a given contributor
     */
    public function fetchContributorContent($id = '', $options = []) {
        if (self::isUuid($id)) {
            $result = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $id]);
            if (!$result) {
                throw new Rest404Exception;
            }
            $node = current($result);
        } else {
            $node = $this->lookupNodeByAlias($id);
        }

        if (!$node) {
            throw new Rest404Exception;
        }

        $defaults = [
            'multiValueGroups' => [
                'type' => [
                    'article',
                    'look',
                    'project',
                    'recipe',
                    'download',
                    'bricky'
                ]
            ]
        ];
        $options = array_merge($defaults, $options);

        $uuid = $node->uuid->value;
        return $this->fetchReferencedContent($uuid, $options, 'field_contributor');
    }

    /**
     * Fetch all paginated content associated with a particular product sku.
     * @param  string $id the sku of the product.
     * @param array $options
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     * - integer $page the current page
     *
     * @return object page of content results for a given contributor
     */
    public function fetchProductSKUContent($id = '', $options = []) {

        $result = $this->entityTypeManager->getStorage('node')->loadByProperties(['field_sku' => $id]);

        if (!$result) {
            throw new Rest404Exception;
        }
        $node = current($result);


        if (!$node) {
            throw new Rest404Exception;
        }

        $defaults = [
            'multiValueGroups' => [
                'type' => [
                    'article',
                    'step',
                    'project',
                    'look',
                    'recipe',
                    'bricky'
                ]
            ]
        ];

        $options = array_merge($defaults, $options);

        $options['isReferencedContentBySKU'] = 'yes'; // Set flag for product referenced content.
        $options['full_image_style'] = 'yes'; // Show only full style of image.
        // Show only listed field on product referenced content.
        $options['referencedContentBySKUField'] = array(
            "type" => "type",
            "created" => "created",
            "path" => "path",
            "field_896x896_img" => "field_896x896_img",
            "field_display_title" => "field_display_title"
        );

        $uuid = $node->uuid->value;

        return $this->fetchReferencedContent($uuid, $options, 'field_products');
    }

    /**
     * Fetch all paginated content associated with a particular tag.
     * @param  string $id the uuid or path alias of the tag
     * @param array $options
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     * - integer $page the current page
     *
     * @return object page of content results for a given tag
     */
    public function fetchTagContent($id = '', $options = []) {
        $uuid = $id;

        if (!self::isUuid($id)) {
            $term = $this->lookupTermByAlias($id);
            if (!$term)
                throw new Rest404Exception;

            $uuid = $term->uuid->value;
        }

        return $this->fetchReferencedContent($uuid, $options, 'field_tag');
    }

    /**
     * Lookup term uuids from list of aliases or uuids.
     * @param  mixed $ids uuids or path aliases
     * @return [type]      [description]
     */
    protected function lookupTermUuids($ids = []) {
        $uuids = [];
        foreach ($ids as $id) {
            if (self::isUuid($id)) {
                $uuids[] = $id;
            } else {
                $term = $this->lookupTermByAlias($id);
                if (!$term)
                    continue;
                $uuids[] = $term->uuid->value;
            }
        }

        return $uuids;
    }

    /**
     * Lookup a term by path alias.
     * @param  string $alias the path alias
     * @return Term or false on failure
     */
    protected function lookupTermByAlias($alias = '') {
        if (!$alias) {
            return false;
        }

        $source = $this->lookupPathSource($alias);
        preg_match('/taxonomy\/term\/(\d+)/', $source, $matches);

        if (!isset($matches[1])) {
            return false;
        }
        $tid = $matches[1];
        $term = Term::load($tid);

        return ($term ? $term : false);
    }

    /**
     * Lookup a node by path alias.
     * @param  string $alias the path alias
     * @return Node or false on failure
     */
    protected function lookupNodeByAlias($alias = '') {
        if (!$alias) {
            return false;
        }

        $source = $this->lookupPathSource($alias);
        preg_match('/node\/(\d+)/', $source, $matches);
        if (!isset($matches[1])) {
            return false;
        }
        $nid = $matches[1];
        $node = Node::load($nid);

        return ($node ? $node : false);
    }

    /**
     * Lookup source path from path alias.
     * @param  string $alias the content alias
     * @return string the source path or FALSE
     */
    protected function lookupPathSource($alias = '') {
        if (!$alias) {
            return FALSE;
        }

        return \Drupal::service('path.alias_storage')->lookupPathSource('/' . $alias, 'en');
    }

    /**
     * Get new entity query for a content type.
     * @param  array $options
     * - string $type (optional) content type to query on
     * - boolean $published  true for published only, false for everything
     * - array $conditions entity query conditions
     * @return Drupal\Core\Entity\Query\QueryInterface EntityQuery, with some conditions
     *  preset for the content type.
     */
    protected function getTidByName($name = NULL, $vid = NULL) {
        $properties = [];
        if (!empty($name)) {
            $properties['name'] = $name;
        }
        if (!empty($vid)) {
            $properties['vid'] = $vid;
        }
        $terms = \Drupal::entityManager()->getStorage('taxonomy_term')->loadByProperties($properties);
        $term = reset($terms);

        return !empty($term) ? $term->id() : 0;
    }

    protected function newNodeQuery($options = [], $actions) {


        if ($actions != "") {
            $termid = $this->getTidByName($actions);
        }

        $query = \Drupal::entityQuery('node');
        if (!$options['published']) {
            $options['multiValueGroups']['status'] = [1, 0];
        } else {
            $query->condition('status', 1);
        }

        if ($termid != "" && $termid != 0) {
            $query->condition('field_brand', $termid);
        }

        if (!empty($options['orConditionGroups'])) {
            foreach ($options['orConditionGroups'] as $conditions) {
                if (!empty($conditions)) {
                    $group = $query->orConditionGroup();
                    foreach ($conditions as $key => $value) {
                        $group->condition($key, $value);
                    }
                    $query->condition($group);
                }
            }
        }

        if (!empty($options['multiValueGroups'])) {
            foreach ($options['multiValueGroups'] as $key => $values) {
                if (!empty($values)) {
                    $group = $query->orConditionGroup();
                    foreach ($values as $value) {
                        $group->condition($key, $value);
                    }
                    $query->condition($group);
                }
            }
        }

        if (!empty($options['conditions'])) {
            foreach ($options['conditions'] as $key => $value) {
                $query->condition($key, $value);
            }
        }

        if (!empty($options['sort'])) {
            foreach ($options['sort'] as $field => $direction) {
                $query->sort($field, $direction);
            }
        } else {
            $query->sort('changed', 'DESC');
        }

        return $query;
    }

    /**
     * Get an entity query for taxonomy lookup.
     * @param  string $vocabulary the vocabulary
     * @return Drupal\Core\Entity\Query\QueryInterface EntityQuery, with some conditions
     *  preset for the content type.
     */
    protected function newTermQuery($vocabulary) {
        $query = \Drupal::entityQuery('taxonomy_term');
        $query->condition('vid', $vocabulary);

        return $query;
    }

    /**
     * Process list of nodes.
     * @param  array $nodes array of Node objects
     * @param array $options
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     * @return array of arrays representing a node in clean REST format
     */
    protected function processNodes($nodes = [], $options = []) {
        $results = [];
        foreach ($nodes as $node) {
            $results[] = $this->processNode($node, $options);
        }

        return $results;
    }

    /**
     * Process all fields in a node.
     * @param  Node   $node the node object.
     * @param array $options
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     * @return array node information in clean format for REST
     */
    protected function processNode(Node $node, $options = [], $actions) {

        $current_path = \Drupal::service('path.current')->getPath();
        $laststring = explode('/', $current_path);
        $lastparameter = trim($laststring[count($laststring) - 1], '/');

        if ($node->getType() == 'bricky' && ($lastparameter != "" && $lastparameter != "bricky")) {
            $id = $node->id();
            $response = $this->fetchAllBricky($options, $id, $actions);
            return $response;
        }

        $view = [];
        $fieldDefinitions = \Drupal::service('entity.manager')->getFieldDefinitions('node', $node->getType());
        $storageDefinitions = \Drupal::service('entity.manager')->getFieldStorageDefinitions('node');

        foreach ($fieldDefinitions as $name => $fieldDefinition) {
            if ($options['isReferencedContentBySKU'] == 'yes') {
                if (!in_array($name, $options['referencedContentBySKUField']))
                    continue;
            }
            $options['fieldDefinition'] = $fieldDefinition;
            $options['storageDefinition'] = $storageDefinitions[$name];
            $options['multiValue'] = method_exists($options['storageDefinition'], 'isMultiple') && ($options['storageDefinition']->isMultiple() || $options['storageDefinition']->getCardinality() > 1);

            if (!$fieldDefinition->getType())
                continue;

            $supported = in_array($fieldDefinition->getType(), array_keys(self::supportedFieldTypes()));
            $ignored = in_array($name, self::ignoredFieldNames());

            if ($supported && !$ignored) {
                // no value
                if (!$node->$name) {
                    if ($options['isReferencedContentBySKU'] != 'yes') {
                        $view[$name] = NULL;
                    }

                    continue;
                }


                $view[$name] = $this->processField($node->{$name}, $options);
            }
        }

        return $view;
    }

    /**
     * Process list of taxonomy terms.
     * @param  array $terms array of Term objects
     * @param array $options
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     * @return array of arrays representing a node in clean REST format
     */
    protected function processTerms($terms, $options = []) {
        $results = [];
        foreach ($terms as $term) {
            $results[] = $this->processTerm($term, $options);
        }

        return $results;
    }

    /**
     * Process all fields in a term.
     * @param  Term   $term the $term object.
     * @return array term information in clean format for REST
     */
    protected function processTerm(Term $term, $options = []) {
        $parents = \Drupal::service('entity_type.manager')->getStorage('taxonomy_term')->loadParents($term->tid->value);
        $parent = '';
        if ($parents) {
            $parentTerm = Term::load(current($parents)->tid->value);
            if ($parentTerm) {
                $parent = $parentTerm->uuid->value;
            }
        }

        $view = [
            'parent' => $parent,
            'type' => $term->getVocabularyId(),
        ];

        $fieldDefinitions = \Drupal::service('entity.manager')->getFieldDefinitions('taxonomy_term', $term->getVocabularyId());
        $storageDefinitions = \Drupal::service('entity.manager')->getFieldStorageDefinitions('taxonomy_term');

        foreach ($fieldDefinitions as $name => $fieldDefinition) {
            $options['fieldDefinition'] = $fieldDefinition;
            $options['storageDefinition'] = $storageDefinitions[$name];
            $options['multiValue'] = method_exists($options['storageDefinition'], 'isMultiple') && ($options['storageDefinition']->isMultiple() || $options['storageDefinition']->getCardinality() > 1);

            if (!$fieldDefinition->getType())
                continue;

            $supported = in_array($fieldDefinition->getType(), array_keys(self::supportedFieldTypes()));
            $ignored = in_array($name, self::ignoredFieldNames());

            if ($supported && !$ignored) {
                // no value
                if (!$term->$name) {
                    $view[$name] = '';
                    continue;
                }

                $view[$name] = $this->processField($term->{$name}, $options);
            }
        }

        return $view;
    }

    /**
     * General case: process a field value. Will automatically choose correct
     *  "formatter" method.
     * @see self::supportedFieldTypes()
     *
     * @param  FieldItemListInterface   $field the field item list
     * @param  array options
     *   - FieldDefinitionInterface $fieldDefinition field instance info
     *     used to get field instance information.
     * @return mixed "formatted" value of the field
     */
    protected function processField(FieldItemListInterface $field, $options = []) {
        $method = self::supportedFieldTypes()[$options['fieldDefinition']->getType()];
        return $this->{$method}($field, $options);
    }

    /**
     * Get simple value.
     * @param  FieldItemListInterface   $field field item list
     * @return string simple string value
     */
    protected function getFieldValue(FieldItemListInterface $field, $options = []) {
        if ($options['multiValue']) {
            $return = [];
            foreach ($field->getValue() as $item) {
                $return[] = $item['value'];
            }
            return $return;
        }

        return $field->value;
    }

    /**
     * Get path alias field value.
     * @param  FieldItemListInterface   $field field item list
     * @param array $options
     * - boolean $recurse references are recursively dereferenced
     * - integer $maxDepth levels of recursion
     * @return string path alias
     */
    protected function getPathFieldValue(FieldItemListInterface $field, $options = []) {
        $entity = $field->getEntity();
        $source = $entity->toUrl()->getInternalPath();
        $lang = $entity->language()->getId();
        $path = \Drupal::service('path.alias_storage')->lookupPathAlias('/' . $source, $lang);
        return preg_replace('/^\//', '', $path);
    }

    /**
     * Get simple integer value.
     * @param  FieldItemListInterface   $field field item list
     * @return int
     */
    protected function getIntFieldValue(FieldItemListInterface $field, $options = []) {
        if ($options['multiValue']) {
            $return = [];
            foreach ($field->getValue() as $item) {
                $return[] = intval($item['value']);
            }
            return $return;
        }

        return intval($field->value);
    }

    /**
     * Get simple float value.
     * @param  FieldItemListInterface   $field field item list
     * @return float
     */
    protected function getFloatFieldValue(FieldItemListInterface $field, $options = []) {
        if ($options['multiValue']) {
            $return = [];
            foreach ($field->getValue() as $item) {
                $return[] = floatval($item['value']);
            }
            return $return;
        }

        return floatval($field->value);
    }

    /**
     * Get link value.
     * @param FieldItemListInterface   $field field item list
     * @param array options
     *   - FieldDefinitionInterface $fieldDefinition field instance info
     *     used to get field instance information.
     *   - includes FieldStorageDefinitionInterface $fieldStorage field storage information
     *     to get field cardinality.
     * @return string simple string value
     */
    protected function getLinkFieldValue(FieldItemListInterface $field, $options = []) {
        $values = $field->getValue();
        $return = ($options['multiValue'] ? [] : NULL);

        if ($values) {
            if ($options['multiValue']) {
                foreach ($values as $linkData) {
                    $return[] = [
                        'url' => $linkData['uri'],
                        'title' => $linkData['title'],
                    ];
                }
                return $return;
            } else {
                $linkData = current($values);
                return [
                    'url' => $linkData['uri'],
                    'title' => $linkData['title'],
                ];
            }
        }

        return $return;
    }

    /**
     * Get simple date value.
     * @param  FieldItemListInterface   $field field item list
     * @return string simple string value
     */
    protected function getDateFieldValue(FieldItemListInterface $field, $options = []) {
        if ($options['multiValue']) {
            $return = [];
            foreach ($field->getValue() as $item) {
                $return[] = \Drupal::service('date.formatter')->format($item['value'], 'html_datetime');
            }
            return $return;
        }

        return \Drupal::service('date.formatter')->format($field->value, 'html_datetime');
    }

    /**
     * Get true/false value from boolean
     * @param  FieldItemListInterface   $field           the field item list
     * @return boolean
     */
    protected function getFieldBoolean(FieldItemListInterface $field, $options = []) {
        // If for some reason a multi-value boolean field is selected, which is
        // non-sense.
        if ($options['multiValue']) {
            $items = $field->getValue();
            if ($items) {
                return current($items)['value'] === "1";
            }
            return false;
        }

        return $field->value === "1";
    }

    /**
     * Get one or more entity reference object arrays.
     * @param  FieldItemListInterface   $field the field items
     * @param  array options
     *   - FieldDefinitionInterface $fieldDefinition field instance info
     *     used to get field instance information.
     *   - FieldStorageDefinitionInterface $storageDefinition field storage information
     *     to get field cardinality.
     *   - int referenceDepth to prevent infinite recursion
     * @return array of arrays representing referenced node
     */
    protected function getReferencedFieldValue(FieldItemListInterface $field, $options = []) {
        $referenceType = $options['fieldDefinition']->getSettings()['target_type'];

        switch ($referenceType) {
            case 'node':
                return $this->getReferencedNode($field, $options);
                break;
            case 'node_type':
                return $this->getNodeType($field);
                break;
            case 'taxonomy_term':
                return $this->getReferencedTerm($field, $options);
                break;
            default:
                return NULL;
        }
    }

    /**
     * Get one or more entity reference object arrays.
     * @param  FieldItemListInterface   $field the field items
     * @param  array options
     *   - FieldDefinitionInterface $fieldDefinition field instance info
     *     used to get field instance information.
     *   - FieldStorageDefinitionInterface $storageDefinition field storage information
     *     to get field cardinality.
     *   - int referenceDepth to prevent infinite recursion
     * @return array of arrays representing referenced node
     */
    protected function getReferencedNode(FieldItemListInterface $field, $options = []) {
        $referenceData = $field->getValue();

        // Reference Field
        $recurse = $options['currentDepth'] < $options['maxDepth'] && $options['recurse'];
        $options['currentDepth'] = ($recurse ? $options['currentDepth'] + 1 : $options['currentDepth']);

        $return = ($options['multiValue'] ? [] : NULL);
        if ($referenceData) {
            if ($options['multiValue']) {
                foreach ($referenceData as $index => $target) {
                    $node = Node::load($target['target_id']);
                    if ($node) {
                        $return[] = ($recurse ? $this->processNode($node, $options) : $this->shallowEntity($node));
                    }
                }
                return $return;
            } else {
                $node = Node::load(current($referenceData)['target_id']);
                if ($node) {
                    return ($recurse ? $this->processNode($node, $options) : $this->shallowEntity($node));
                }
            }
        }

        return $return;
    }

    /**
     * Dereference a term reference field.
     * @param  FieldItemListInterface $field term reference field list
     * @param  array $options options array
     * @return mixes term object or array of terms
     */
    protected function getReferencedTerm(FieldItemListInterface $field, $options = []) {
        $referenceData = $field->getValue();

        $recurse = $options['currentDepth'] < $options['maxDepth'] && $options['recurse'];
        $options['currentDepth'] = ($recurse ? $options['currentDepth'] + 1 : $options['currentDepth']);

        $return = ($options['multiValue'] ? [] : NULL);
        if ($referenceData) {
            if ($options['multiValue']) {
                foreach ($referenceData as $index => $target) {
                    $term = Term::load($target['target_id']);
                    if ($term) {
                        $return[] = ($recurse ? $this->processTerm($term, $options) : $this->shallowEntity($term));
                    }
                }
                return $return;
            } else {
                $term = Term::load(current($referenceData)['target_id']);
                if ($term) {
                    return ($recurse ? $this->processTerm($term, $options) : $this->shallowEntity($term));
                }
            }
        }

        return $return;
    }

    /**
     * Get simple object with type and uuid for referenced entity.
     * @param  mixed $entity node or taxonomy_term
     * @return array representing simple type and uuid object.
     */
    protected function shallowEntity($entity) {
        $type = '';
        if (!empty($entity->type)) {
            $type = $this->getNodeType($entity->type);
        } else if (!empty($entity->vid)) {
            $type = current($entity->vid->getValue())['target_id'];
        }

        return [
            'uuid' => $entity->uuid->value,
            'type' => $type,
        ];
    }

    /**
     * Get one or more file object arrays.
     * @param  FieldItemListInterface   $field the field items
     * @param  array options
     *   - FieldDefinitionInterface $fieldDefinition field instance info
     *     used to get field instance information.
     *   - FieldStorageDefinitionInterface $storageDefinition field storage information
     *     to get field cardinality.
     * @return array of arrays of file urls.
     */
    protected function getFileFieldValue(FieldItemListInterface $field, $options = []) {
        $fileData = $field->getValue();

        $return = ($options['multiValue'] ? [] : NULL);
        if ($fileData) {
            if ($options['multiValue']) {
                foreach ($fileData as $target) {
                    $file = File::load($target['target_id']);
                    if ($file) {
                        $return[] = $file->url();
                    }
                }
                return $return;
            }

            // single
            $file = File::load(current($fileData)['target_id']);
            if ($file) {
                return $file->url();
            }
        }

        return $return;
    }

    /**
     * Get one or more entity reference object arrays.
     * @param  FieldItemListInterface   $field the field items
     * @return string node type
     */
    protected function getNodeType(FieldItemListInterface $field) {
        $value = $field->getValue();
        if ($value) {
            return current($value)['target_id'];
        }

        return '';
    }

    /**
     * Get one or more image object arrays.
     * @param  FieldItemListInterface   $field the field items
     * @param  array options
     *   - FieldDefinitionInterface $fieldDefinition field instance info
     *     used to get image resolution constraints.
     *   - FieldStorageDefinitionInterface $storageDefinition field storage information
     *     to get field cardinality.
     * @return array of arrays of image urls.
     */
    protected function getImageFieldValue(FieldItemListInterface $field, $options = []) {
        $imageData = $field->getValue();
        $resolution = $options['fieldDefinition']->getSettings()['max_resolution'];

        $resolutions = $this->imageStyles($resolution);

        $return = ($options['multiValue'] ? [] : NULL);
        if ($imageData) {
            if ($options['multiValue']) {
                foreach ($imageData as $image) {
                    $return[] = $this->processImage($image['target_id'], $resolutions);
                }
                return $return;
            }
            if ($options['full_image_style'] == 'yes') {
                return $this->processImage(current($imageData)['target_id'], []);
            }
            // single
            return $this->processImage(current($imageData)['target_id'], $resolutions);
        }

        return $return;
    }

    /**
     * Process an image field.
     * @param  int $target_id file entity id
     * @param  array $resolutions image style names that might apply to this image.
     * @return array of image urls
     */
    protected function processImage($target_id, $resolutions = []) {
        $streamWrapper = \Drupal::service('stream_wrapper_manager');
        $baseFile = \Drupal::service('entity.manager')
                ->getStorage('file')
                ->load($target_id);

        $internalUri = $baseFile->getFileUri();

        $result = [
            'full' => $streamWrapper->getViaUri($internalUri)->getExternalUrl()
        ];

        foreach ($resolutions as $resolution) {
            $styleName = $resolution;
            $style = ImageStyle::load($resolution);
            if ($style) {
                $result[$styleName] = $style->buildUrl($internalUri);
            }
        }

        return $result;
    }

    /**
     * Based on string max resolution from image field configuration, get the
     * list of image styles that share the same aspect ratio.
     * @param  string $resolution [width]x[height] string
     * @return array list of image styles
     */
    protected function imageStyles($resolution) {
        $resolutions = self::resolutions();

        preg_match('/(\d+)x(\d+)/', $resolution, $matches);

        if (!$matches || !$matches[2]) {
            return [];
        }

        $aspectRatio = number_format(round($matches[1] / $matches[2], 2), 2);
        if (!in_array($aspectRatio, array_keys($resolutions))) {
            return [];
        }

        return $resolutions[$aspectRatio];
    }

    /**
     * Is the argument a uuid?
     * @param  string  $uuid string to test
     * @return boolean
     */
    protected static function isUuid($uuid = '') {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $uuid) === 1;
    }

    /**
     * Get image styles for each aspect ratio.
     * @return array list of resolutions/image styles per aspect ratio
     */
    protected static function resolutions() {
        return [
            '0.75' => [
                '465x620_img',
            ],
            '1.00' => [
                '400x400_img',
                '414x414_img',
                '448x448_img',
            ],
            '1.33' => [
                '414x312_img',
                '544x409_img',
                '640x481_img',
                '828x623_img',
                '912x686_img',
            ],
            '1.75' => [
                '414x237_img',
                '533x305_img',
                '828x473_img',
                '929x531_img',
            ],
            '2.30' => [
                '533x232_img',
                '1600x696_img',
            ],
        ];
    }

    /**
     * Methods for processing different field types.
     * @return array methods for handling differnent field types.
     */
    protected static function supportedFieldTypes() {
        return [
            'string' => 'getFieldValue',
            'string_long' => 'getFieldValue',
            'text' => 'getFieldValue',
            'text_long' => 'getFieldValue',
            'created' => 'getDateFieldValue',
            'changed' => 'getDateFieldValue',
            'path' => 'getPathFieldValue',
            'float' => 'getFloatFieldValue',
            'boolean' => 'getFieldBoolean',
            'uuid' => 'getFieldValue',
            'integer' => 'getIntFieldValue',
            'image' => 'getImageFieldValue',
            'file' => 'getFileFieldValue',
            'entity_reference' => 'getReferencedFieldValue',
            'link' => 'getLinkFieldValue',
        ];
    }

    /**
     * Ignored fields used processing nodes.
     * @return array list of ignored field names.
     */
    protected static function ignoredFieldNames() {
        return [
            'parent',
            'tid',
            'vid',
            'title',
            'langcode',
            'uid',
            'promote',
            'sticky',
            'revision_timestamp',
            'revision_uid',
            'revision_log',
            'revision_translation_affected',
            'default_langcode',
            'publish_on',
            'unpublish_on',
        ];
    }

    public function fetchAllBricky($options = [], $id, $actions) {

        // $id = \Drupal::request()->query->get('id');

        $node = \Drupal\node\Entity\Node::load($id);
        $iscount = 0;
        if (!empty($node)) {

            $field_brand = $node->get('field_brand')->getValue();

            if ($actions != "") {
                $termid = $this->getTidByName($actions);
            }


            if (isset($field_brand[0]['target_id'])) {
                $field_brand = $field_brand[0]['target_id'];
            }


            if ($field_brand == $termid) {
                $iscount = 1;

                $field_body = $node->get('field_body')->getValue();

                $field_title = $node->getTitle();
                $field_id = $id;
                $paragrah_reference_id = "";
                $node_paragraph = array();
                $resutlArray = [];
                $index = [];
                $flag = 0;
                $i = 0;

                $resutlArray['field_id'] = $field_id;
                $resutlArray['nid'] = $field_id;

                $resutlArray['field_title'] = $field_title;

                foreach ($field_body as $key => $value) {

                    $brick_id_depth = $value['depth'];
                    if ($brick_id_depth == 0) {

                        $resutlArray[$brick_id_depth] = $value;
                        $i++;
                    } elseif ($brick_id_depth == 1) {
                        $resutlArray[0][$brick_id_depth] = $value;
                    } elseif ($brick_id_depth == 2 && !in_array($brick_id_depth, $index)) {
                        $resutlArray[0][1]['left'] = $value;
                        $flag = 0;
                    } else if ($brick_id_depth == 2 && in_array($brick_id_depth, $index)) {
                        $resutlArray[0][1]['right'] = $value;
                        $flag = 1;
                        $i = 0;
                    } elseif ($brick_id_depth == 3 && $flag == 0) {
                        $resutlArray[0][1]['left'][$i] = $value;
                        $paragrah_reference_id = $value['target_id'];
                        if ($paragrah_reference_id != "") {

                            $paragrah_id = $this->getBricksParagraphId($paragrah_reference_id);
                            $p = 0;
                            foreach ($paragrah_id as $paragrah_id_details) {
                                $node_paragraph = Paragraph::load($paragrah_id_details)->toArray();
                                foreach ($node_paragraph as $key => $node_paragraph_details) {
                                    if ($key == 'field_menu_links') {
                                        $l = 0;
                                        foreach ($node_paragraph_details as $node_paragraph_details_val) {
                                            $resutlArray[0][1]['left'][$i][$p][$key]['uri'] = $node_paragraph_details_val['uri'];
                                            $resutlArray[0][1]['left'][$i][$p][$key]['title'] = $node_paragraph_details_val['title'];
                                            $l++;
                                        }
                                    }
                                    if ($key == 'field_menu_without_category') {
                                        $l = 0;
                                        foreach ($node_paragraph_details as $node_paragraph_details_val) {
                                            $resutlArray[0][1]['left'][$i][$p][$key]['uri'] = $node_paragraph_details_val['uri'];
                                            $resutlArray[0][1]['left'][$i][$p][$key]['title'] = $node_paragraph_details_val['title'];
                                            $l++;
                                        }
                                    }
                                }
                                $p++;
                            }
                        }
                        $i++;
                    } elseif ($brick_id_depth == 3 && $flag == 1) {

                        $paragrah_reference_id = $value['target_id'];

                        if ($paragrah_reference_id != "") {

                            $paragrah_id = $this->getBricksParagraphId($paragrah_reference_id);

                            $brick_field_text = $this->getBricksText($paragrah_reference_id);

                            $p = 0;
                            $cartridgeid = "";
                            foreach ($paragrah_id as $paragrah_id_details) {
                                $node_paragraph = Paragraph::load($paragrah_id_details)->toArray();
                                $cartridgeid = $node_paragraph['type'][0]['target_id'];
                                $m = 0;

                                if (isset($resutlArray[0][1]['right'][$i][$p][$cartridgeid]['title'])) {
                                    $brick_field_text = $resutlArray[0][1]['right'][$i][$p][$cartridgeid]['title'];
                                }

                                foreach ($node_paragraph as $key => $node_paragraph_details) {

                                    if ($cartridgeid == 'candy_cartridge_a' || $cartridgeid == 'candy_cartridge_b' || $cartridgeid == 'candy_cartridge_c' ||
                                            $cartridgeid == 'candy_cartridge_d' || $cartridgeid == 'candy_cartridge_e' || $cartridgeid == 'candy_cartridge_f' ||
                                            $cartridgeid == 'candy_cartridge_g') {
                                        if ($key == "field_cartridge_common_title") {
                                            $resutlArray[0][1]['right'][$i][$p][$cartridgeid]['field_cartridge_common_title'] = $node_paragraph_details['0']['value'];
                                        }
                                        if ($key == "field_cartridge_common_image") {
                                            $resutlArray[0][1]['right'][$i][$p][$cartridgeid]['field_cartridge_common_image'] = $node_paragraph_details['0']['value'];
                                        }
                                        if ($key == "field_cartridge_common_url") {
                                            $resutlArray[0][1]['right'][$i][$p][$cartridgeid]['field_cartridge_common_url'] = $node_paragraph_details['0']['value'];
                                        }
                                        if ($key == "field_cartridge_common_desc") {
                                            $resutlArray[0][1]['right'][$i][$p][$cartridgeid]['field_cartridge_common_desc'] = $node_paragraph_details['0']['value'];
                                        }
                                    }
                                    $m++;
                                }
                                $p++;
                            }
                            if ($brick_field_text != "") {
                                $resutlArray[0][1]['right'][$i][0]['field_text'] = $brick_field_text;
                            }
                        }
                        $i++;
                    }
                    $index[] = $value['depth'];
                }
            }
        }


        $counts = array_count_values($index);
        if ($counts['0'] > 1) {

            $resutlArray = [];

            $resutlArray['field_id'] = $field_id;
            $resutlArray['nid'] = $field_id;

            $resutlArray['field_title'] = $field_title;
            $i = 0;
            foreach ($field_body as $key => $value) {

                $brick_id_depth = $value['depth'];
                if ($brick_id_depth == 0) {

                    $paragrah_reference_id = $value['target_id'];

                    if ($paragrah_reference_id != "") {

                        $paragrah_id = $this->getBricksParagraphId($paragrah_reference_id);

                        $brick_field_text = $this->getBricksText($paragrah_reference_id);


                        $p = 0;
                        $cartridgeid = "";
                        foreach ($paragrah_id as $paragrah_id_details) {
                            $node_paragraph = Paragraph::load($paragrah_id_details)->toArray();
                            $cartridgeid = $node_paragraph['type'][0]['target_id'];
                            $m = 0;

                            if (isset($resutlArray[$i][0][$p][$cartridgeid]['title'])) {
                                $brick_field_text = $resutlArray[$i][0][$p][$cartridgeid]['title'];
                            }

                            foreach ($node_paragraph as $key => $node_paragraph_details) {

                                if ($cartridgeid == 'candy_cartridge_a' || $cartridgeid == 'candy_cartridge_b' || $cartridgeid == 'candy_cartridge_c' ||
                                        $cartridgeid == 'candy_cartridge_d' || $cartridgeid == 'candy_cartridge_e' || $cartridgeid == 'candy_cartridge_f' ||
                                        $cartridgeid == 'candy_cartridge_g') {
                                    if ($key == "field_cartridge_common_title") {
                                        $resutlArray[$i][0][$p][$cartridgeid]['field_cartridge_common_title'] = $node_paragraph_details['0']['value'];
                                    }
                                    if ($key == "field_cartridge_common_image") {
                                        $resutlArray[$i][0][$p][$cartridgeid]['field_cartridge_common_image'] = $node_paragraph_details['0']['value'];
                                    }
                                    if ($key == "field_cartridge_common_url") {
                                        $resutlArray[$i][0][$p][$cartridgeid]['field_cartridge_common_url'] = $node_paragraph_details['0']['value'];
                                    }
                                    if ($key == "field_cartridge_common_desc") {
                                        $resutlArray[$i][0][$p][$cartridgeid]['field_cartridge_common_desc'] = $node_paragraph_details['0']['value'];
                                    }
                                }
                                $m++;
                            }
                            $p++;
                        }

                        if ($brick_field_text != "") {
                            $resutlArray[$i][0][$p]['field_text'] = $brick_field_text;
                        }
                    }
                }
                $i++;
            }
        }


        $defaults = [
            'page' => 0,
            'published' => true,
            'limit' => $id ? 1 : 1, // result limit
            'recurse' => true, // toggle off recursion
            'maxDepth' => 2, // deepest level of recursion
            'currentDepth' => 0, // current depth of recursion
            'multiValueGroups' => [],
            'sort' => [
                'field_sort_by_date' => 'DESC',
                'changed' => 'DESC',
            ],
        ];
        $options = array_merge($defaults, $options);

        $limit = $options['limit'];
        $response = [
            'limit' => $id ? 1 : $limit,
            'page' => $options['page'],
            'published' => $options['published']
        ];

        if ($iscount == 1) {
            $response['count'] = $id ? 1 : intval($this->newNodeQuery($options)->count()->execute());
        } else {
            $response['count'] = $id ? 0 : intval($this->newNodeQuery($options)->count()->execute());
        }


        $response['results'] = $resutlArray;

        return $response;
    }

    /**
     * Is the argument a id?
     * @param  string  $id string to test
     * @return boolean
     */
    protected static function getBricksParagraphId($id = '') {
        $query = \Drupal::database()->select('brick__field_paragraph_brick', 'bfpb');
        $query->fields('bfpb', ['field_paragraph_brick_target_id', 'entity_id']);
        $query->condition('bfpb.entity_id', $id);
        $query->condition('bfpb.bundle', 'paragraph_reference');
        $z_results = $query->execute()->fetchAll();
        $field_paragraph_brick_target_id = array();
        if (!empty($z_results)) {
            foreach ($z_results as $z_results_value) {
                $field_paragraph_brick_target_id[] = $z_results_value->field_paragraph_brick_target_id;
            }
        }
        return $field_paragraph_brick_target_id ? $field_paragraph_brick_target_id : "";
    }

    /**
     * Is the argument a id?
     * @param  string  $id string to test
     * @return boolean
     */
    protected static function getBricksText($id = '') {
        $query = \Drupal::database()->select('brick__field_text', 'ftv');
        $query->fields('ftv', ['field_text_value']);
        $query->condition('ftv.entity_id', $id);
        $z_results = $query->execute()->fetchAll();

        $field_text = array();
        if (!empty($z_results)) {
            foreach ($z_results as $z_results_value) {
                $field_text_value = $z_results_value->field_text_value;
            }
        }

        return (isset($field_text_value)) ? $field_text_value : "";
    }

}
