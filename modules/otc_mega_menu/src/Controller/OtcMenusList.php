<?php

namespace Drupal\otc_mega_menu\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Url;

/**
 *
 */
class OtcMenusList extends ControllerBase {

  /**
   * A list of menu items.
   *
   * @var array
   */
  protected $menuItems = [];

  /**
   * The maximum depth we want to return the tree.
   *
   * @var int
   */
  protected $maxDepth = 0;

  /**
   * The minimum depth we want to return the tree from.
   *
   * @var int
   */
  protected $minDepth = 1;

  /**
   * Constructs a menu.
   */
  public function content($menu_name) {
    // Setup variables.
    $this->setup();

    // Create the parameters.
    $parameters = new MenuTreeParameters();
    $parameters->onlyEnabledLinks();

    if (!empty($this->maxDepth)) {
        $maxdepth = $this->maxDepth + 1;
        $parameters->setMaxDepth($maxdepth);
    }else{
        $parameters->setMaxDepth(1);   
    }

    if (!empty($this->minDepth)) {
      $parameters->setMinDepth($this->minDepth);
    }

    $menu_tree = \Drupal::menuTree();
    $tree = $menu_tree->load($menu_name, $parameters);

    if (empty($tree)) {
      return new JsonResponse([]);
    }

    $manipulators = [
      // Only show links that are accessible for the current user.
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      // Use the default sorting of menu links.
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $menu_tree->transform($tree, $manipulators);

    // Finally, build a renderable array from the transformed tree.
    $menu = $menu_tree->build($tree);

    $this->getMenuItems($menu['#items'], $this->menuItems);

    $output = array_values($this->menuItems);

    // Add Cache settings for Max-age and URL context.
    // You can use any of Drupal's contexts, tags, and time.
    $output['#cache'] = [
      'max-age' => 600,
      'contexts' => [
        'url',
      ],
    ];

    $response = new CacheableJsonResponse($output);
    $response->addCacheableDependency(CacheableMetadata::createFromRenderArray($output));
    return $response;
  }

  /**
   * Constructs a sub menu.
   */
  public function getMenuItems(array $tree, array &$items = []) {
    foreach ($tree as $item_value) {
      /* @var $org_link \Drupal\Core\Menu\MenuLinkDefault */
      $org_link = $item_value['original_link'];
      $options = $org_link->getOptions();

      // Set name to uuid or base id.
      $item_name = $org_link->getDerivativeId();
      if (empty($item_name)) {
        $item_name = $org_link->getBaseId();
      }

      /* @var $url \Drupal\Core\Url */
      $url = $item_value['url'];

      $external = FALSE;
      $uuid = '';
      if ($url->isExternal()) {
        $uri = $url->getUri();
        $external = TRUE;
        $absolute = $uri;
      }
      else {
           $absolute = $uri = '';
      }

      $alias = \Drupal::service('path.alias_manager')->getAliasByPath("/$uri");

      $items[$item_name] = [
        'key'      => $item_name,
        'title'    => $org_link->getTitle(),
        'uri'      => $uri,
        'alias'    => ltrim($alias, '/'),
        'external' => $external,
        'absolute' => $absolute,
        'weight'   => $org_link->getWeight(),
        'expanded' => $org_link->isExpanded(),
        'enabled'  => $org_link->isEnabled(),
        'uuid'     => $uuid,
        'options'     => $options,
      ];

      if (!empty($item_value['below'])) {
        $items[$item_name]['below'] = [];
        $this->getMenuItems($item_value['below'], $items[$item_name]['below']);
      }
    }
  }

  /**
   * This function is used to generate some variables we need to use.
   *
   * These variables are available in the url.
   */
  private function setup() {
    // Get the current request.
    $request = \Drupal::request();

    // Get and set the max depth if available.
    $max = $request->get('depth');
    if (!empty($max)) {
      $this->maxDepth = $max;
    }
  }

}
