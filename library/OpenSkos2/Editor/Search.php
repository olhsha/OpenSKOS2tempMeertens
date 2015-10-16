<?php

/* 
 * OpenSKOS
 * 
 * LICENSE
 * 
 * This source file is subject to the GPLv3 license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.txt
 * 
 * @category   OpenSKOS
 * @package    OpenSKOS
 * @copyright  Copyright (c) 2015 Picturae (http://www.picturae.com)
 * @author     Picturae
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GPLv3
 */

namespace OpenSkos2\Editor;

class Search
{
    
    /**
     * @var \OpenSkos2\ConceptManager
     */
    private $manager;
    
    /**
     *
     * @param \OpenSkos2\ConceptManager $manager
     */
    public function __construct(\OpenSkos2\ConceptManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Get JSON Response for editor search
     *
     * @param string $term
     * @param array  $searchOptions
     * @param int $rows amount of rows to return
     * @param int $offset start offset
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getResponse($term, $searchOptions, $rows, $offset)
    {
        $data = [
            'status' => 'ok',
            //'numFound' => 100, // not possible with full text search result cannot go higher then 10000
            'concepts' => $this->manager->search($term, $searchOptions, $rows, $offset),
            'conceptSchemeOptions' => [
                [
                    'id' => 'http://data.cultureelerfgoed.nl/semnet/abstractebegrippen',
                    'name' => 'abstracte begrippen'
                ]
            ],
            'profileOptions' => [
                [
                    'id' => null,
                    'name' => 'Default',
                    'selected' => false
                ],
                [
                    'id' => 'custom',
                    'name' => 'Custom',
                    'selected' => true
                ],
            ]
        ];
        
        return new \Zend\Diactoros\Response\JsonResponse($data);
    }
}
