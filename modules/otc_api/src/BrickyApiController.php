<?php

namespace Drupal\otc_api;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Cache\CacheableJsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

class BrickyApiController extends ControllerBase {


  /**
   * @var Drupal\otc_api\RestHelper
   */
  protected $restHelper;

  /**
   * Constructor.
   */
  public function __construct(RestHelper $restHelper) {
      
  
    $this->restHelper = $restHelper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
      return new static(
        $container->get('otc_api.rest_helper')
      );
  }

 /**
  * @apiDescription Idea gallery API.
  *
  * @api {get} /api/fun365/idea Get all article, recipe, look, project, download nodes.
  * @apiName Gallery
  * @apiGroup Idea
  *
  * @apiParam {Number} page GET param  the result page (default 0)
  * @apiParam {Number} limit GET param limit number of results per page (default 10)
  * @apiParam {Number} recurse GET param 1 to recurse content (default 0)
  * @apiParam {Number} depth GET param levels deep to limit recursion (default 2)
  * @apiParam {String} category[] GET param (multiple) category uuid or path alias
  * @apiParam {String} tag[] GET param (multiple) tag uuid or path alias
  * @apiParam {String} type[] GET param (multiple) content type
  *
  * @apiParamExample {url} Request Example:
  * // first page of all ideas
  *  /api/fun365/idea
  *
  * // second page of 15 articles only in either parent or child category, tagged christmas.
  * // recursively load content up to 4 levels deep
  *  /api/fun365/idea?page=1&limit=15&recurse=1&depth=4&category[]=parent-category&category[]=child-category&tag[]=christmas&type[]=article
  *
  */
  public function brickycontent(Request $request) {
    $options = [
      'category' => $request->get('category') ? $request->get('category') : [],
      'tag' => $request->get('tag') ? $request->get('tag') : [],
      'type' => $request->get('type') ? $request->get('type') : [],
      'limit' => ($request->get('limit') ? intval($request->get('limit')) : 10),
      'published' => $request->get('published') !== '0',
      'page' => $request->get('page') * 1,
      'recurse' => (false || $request->get('recurse')),
      'maxDepth' => ($request->get('depth') ? intval($request->get('depth')) : 2),
    ];

    try {
      $results = $this->restHelper->fetchAllBricky($options);
      //$response = new CacheableJsonResponse($results);
      //$response->addCacheableDependency($this->restHelper->cacheMetaData($results, 'node'));
      
      return new JsonResponse($results);

      //return $response;
    } catch (Exception $e) {
      return $this->handleException($e);
    }
  }
  
    protected function handleException(Exception $e) {
    if ( $e instanceof Rest404Exception ) {
      return new CacheableJsonResponse(['error' => $e->getMessage()], 404);
    } elseif ($e instanceof Rest403Exception) {
      return new CacheableJsonResponse(['error' => $e->getMessage()], 403);
    }

    return new CacheableJsonResponse(['error' => 'Internal server error.'], 500);
  }
}

  