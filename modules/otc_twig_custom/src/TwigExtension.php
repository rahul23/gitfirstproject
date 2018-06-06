<?php

namespace Drupal\otc_twig_custom;

use Drupal\node\Entity\Node;

/**
 * Class DefaultService.
 *
 * @package Drupal\demo_module
 */
class TwigExtension extends \Twig_Extension {

    /**
     * {@inheritdoc}
     * This function must return the name of the extension. It must be unique.
     */
    public function getName() {
        return 'getParagraphId';
    }

    /**
     * In this function we can declare the extension function.
     */
    public function getFunctions() {
        return array(
            new \Twig_SimpleFunction('getParagraphId', array($this, 'getParagraphId'), array('is_safe' => array('html'))),
        );
    }

    /*
     * This function is used to return alt of an image
     * Set image title as alt.
     */

    function getParagraphId($title) {

        $title = isset($title['#context']['value']) ? strtolower($title['#context']['value']) : NULL;
        $ParagraphId = "";
        if (!is_null($title)) {
            $ParagraphId = str_replace(" ", "_", $title);
            $ParagraphId = str_replace("-", "_", $ParagraphId);
        }
        
        
        return $ParagraphId;
    }

}
