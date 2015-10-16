<?php

/**
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

namespace OpenSkos2;

use Asparagus\QueryBuilder;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSkos2\Namespaces\Skos;
use OpenSkos2\Rdf\Literal;
use OpenSkos2\Rdf\ResourceManager;
use OpenSkos2\Rdf\Serializer\NTriple;

class ConceptManager extends ResourceManager
{
    /**
     * What is the basic resource for this manager.
     * @var string NULL means any resource.
     */
    protected $resourceType = Concept::TYPE;

    /**
     * String to combine / explode for concat values from sparql
     *
     * @var string
     */
    private $concatSeperator = '^';

    /**
     * String to combine and explode group_concat values from sparql
     *
     * @var string
     */
    private $groupConcatSeperator = '|';

    /**
     * Field seperator, used to add field names in concatted groups e.g
     * title@@this is my title
     *
     * @var string
     */
    private $concatFieldSeperator = '@@';

    /**
     * Perform basic autocomplete search on pref and alt labels
     *
     * @param string $term
     * @return array
     */
    public function autoComplete($term)
    {
        $prefixes = [
            'skos' => Skos::NAME_SPACE,
            'openskos' => OpenSkos::NAME_SPACE
        ];

        $literalKey = new Literal('^' . $term);
        $eTerm = (new NTriple())->serialize($literalKey);

        $q = new QueryBuilder($prefixes);

        // Do a distinct query on pref and alt labels where string starts with $term
        $query = $q->selectDistinct('?label')
            ->union(
                $q->newSubgraph()
                    ->where('?subject', 'openskos:status', '"'. Concept::STATUS_APPROVED.'"')
                    ->also('skos:prefLabel', '?label'),
                $q->newSubgraph()
                    ->where('?subject', 'openskos:status', '"'. Concept::STATUS_APPROVED.'"')
                    ->also('skos:altLabel', '?label')
            )
            ->filter('regex(str(?label), ' . $eTerm . ', "i")')
            ->limit(50);

        $result = $this->query($query);

        $items = [];
        foreach ($result as $literal) {
            $items[] = $literal->label->getValue();
        }
        return $items;
    }

    /**
     * Search from the editor
     *
     * @param string $term
     * @param array $searchOptions
     * @return array
     */
    public function search($term, $searchOptions)
    {
        return $this->fullTextSearch($term);
        
        //$this->searchSpecific($term, $searchOptions);
    }
    
    /**
     * Perform a full text search
     *
     * @param string $term
     * @return array
     */
    private function fullTextSearch($term)
    {
        // Add asparagus support see : https://github.com/Benestar/asparagus/issues/25
        $textQuery = "PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
            prefix rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
            PREFIX text: <http://jena.apache.org/text#>

            SELECT DISTINCT ?subject
            WHERE {
                ?subject text:query (skos:prefLabel '$term' 10);
            }
            LIMIT 10";
        
        //echo $textQuery; exit;
        $results = $this->query($textQuery);
        
        if (!$results->numRows()) {
            return [];
        }
        
        $conceptUri = [];
                
        foreach ($results as $row) {
            $conceptUri[] = $row->subject->getUri();
        }
        
        $conceptUris = Sparql\Escape::escapeUris($conceptUri);
        
        // Add asparagus BIND support see: https://github.com/Benestar/asparagus/issues/26
        $query = '
            PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
            PREFIX openskos: <http://openskos.org/xmlns#>
            PREFIX dcterms: <http://purl.org/dc/terms/>
            PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

            SELECT ?prefLabel ?uuid ?uri ?status 
                (group_concat (
                    distinct concat ("uri", 
                        "'.$this->concatFieldSeperator.'", 
                        str(?scheme), 
                        "'.$this->concatSeperator.'", 
                        "dcterms_title", 
                        "'.$this->concatFieldSeperator.'", 
                        ?schemeTitleb, 
                        "'.$this->concatSeperator.'", 
                        "uuid", 
                        "'.$this->concatFieldSeperator.'", 
                        ?schemeUuidb);separator = "'.$this->groupConcatSeperator.'") AS ?schemes) 
            WHERE {
                    ?uri rdf:type skos:Concept ;
                            skos:prefLabel ?prefLabel ;
                            openskos:uuid ?uuid ;
                            openskos:status ?status ;
                            skos:inScheme ?scheme .
                    OPTIONAL {
                        ?uri skos:inScheme ?scheme .
                            ?scheme dcterms:title ?schemeTitle ;
                            openskos:uuid ?schemeUuid .
                    }
                    FILTER (?uri IN ('.$conceptUris.'))
              BIND ( IF (BOUND (?schemeUuid), ?schemeUuid, \'\')  as ?schemeUuidb) 
              BIND ( IF (BOUND (?schemeTitle), ?schemeTitle, \'\')  as ?schemeTitleb) 
            }
            GROUP BY ?prefLabel ?uuid ?uri ?status
            LIMIT 20';
               
        $result = $this->query($query);
        $items = [];
        foreach ($result as $literal) {
            $arrLit = (array)$literal;
            if (empty($arrLit)) {
                continue;
            }

            $concept = [
                'uri' => (string)$literal->uri,
                'uuid' => (string)$literal->uuid,
                'previewLabel' => (string)$literal->prefLabel,
                'status' => (string)$literal->status
            ];

            if (isset($literal->schemes)) {
                $schemes = $this->decodeConcat((string)$literal->schemes);
                $concept['schemes'] = $this->addIconToScheme($schemes);
            }

            if (isset($literal->scopeNotes)) {
                $concept['scopeNotes'] = explode('|', (string)$literal->scopeNotes);
            }

            $items[] = $concept;
        }
        return $items;
    }
    
    private function searchSpecific($term, $searchOptions)
    {
        $prefixes = [
            'skos' => Skos::NAME_SPACE,
            'openskos' => OpenSkos::NAME_SPACE,
            'dcterms' => Namespaces\DcTerms::NAME_SPACE,
            'rdf' => Namespaces\Rdf::NAME_SPACE
        ];

        $literalKey = new Literal('^' . $term);
        $eTerm = (new NTriple())->serialize($literalKey);

        $q = new QueryBuilder($prefixes);

        $cs = $this->concatSeperator;
        $gcs = $this->groupConcatSeperator;
        $cfs = $this->concatFieldSeperator;
        
        $filter = new \Asparagus\GraphBuilder(new \Asparagus\UsageValidator);
        $filter->where('?uri', 'skos:altLabel', '?altLabel')
                ->filter("lang (?altLabel) = 'nl' || lang (?altLabel) = 'en' || lang (?altLabel) = 'fr'");
        
        $query = $q->select([
                '?prefLabel',
                '?uuid',
                '?uri',
                '?status',
                '(group_concat(distinct '
                .       'concat('
                .           '"uri", "' . $cfs . '", str(?scheme), "' . $cs . '",'
                .           '"dcterms_title", "' . $cfs . '", ?schemeTitle, "' . $cs . '",'
                .           '"uuid", "' . $cfs . '", ?schemeUuid'
                .       ');separator="'.$gcs.'") AS ?schemes)'
            ])
            ->where('?uri', 'rdf:type', 'skos:Concept')
            ->also('skos:prefLabel', '?prefLabel')
            ->also('openskos:uuid', '?uuid')
            ->also('openskos:status', '?status')
            ->also('?uri', 'skos:inScheme', '?scheme')
            ->also('?scheme', 'dcterms:title', '?schemeTitle')
            ->also('?scheme', 'openskos:uuid', '?schemeUuid')
            ->optional($filter)
            ->optional('?uri', 'skos:hiddenLabel', '?hiddenLabel')
            ->optional('?uri', 'skos:altLabel', '?altLabel')
            ->optional('?uri', 'skos:scopeNote', '?scopeNote')
            ->filter('regex(str(?prefLabel), ' . $eTerm . ', "i") || regex(str(?altLabel), ' . $eTerm . ', "i")')
            ->groupBy('?prefLabel', '?uuid', '?uri', '?status')
            ->limit(20);
        
        $this->addFilters($term, $searchOptions, $query);
        $this->addLanguageFilters($searchOptions, $query);
        
        //echo $query->format(); exit;
        $result = $this->query($query);
        $items = [];
        foreach ($result as $literal) {
            $arrLit = (array)$literal;
            if (empty($arrLit)) {
                continue;
            }

            $concept = [
                'uri' => (string)$literal->uri,
                'uuid' => (string)$literal->uuid,
                'previewLabel' => (string)$literal->prefLabel,
                'status' => (string)$literal->status
            ];

            if (isset($literal->schemes)) {
                $schemes = $this->decodeConcat((string)$literal->schemes);
                $concept['schemes'] = $this->addIconToScheme($schemes);
            }

            if (isset($literal->scopeNotes)) {
                $concept['scopeNotes'] = explode('|', (string)$literal->scopeNotes);
            }

            $items[] = $concept;
        }
        return $items;
    }
    
    /**
     * Get search filter
     * @param string $term
     * @param array $searchOptions
     * @param QueryBuilder $qb
     * @return QueryBuilder
     */
    private function addFilters($term, $searchOptions, QueryBuilder $qb)
    {
        $searchTerm = Sparql\Escape::escapeLiteral('^' . $term);
        
        $allowedLabels = [
            'prefLabel',
            'altLabel',
            'hiddenLabel'
        ];

        // Add filter for labels
        $filter = '';
        foreach ($searchOptions['label'] as $label) {
            if (!in_array($label, $allowedLabels)) {
                continue;
            }
            $filter .= 'regex(str(?'.$label.'), ' . $searchTerm . ', "i") || ';
        }

        $cleanFilter = substr($filter, 0, -4);
        $qb->filter($cleanFilter);

        return $qb;
    }
    
    /**
     * Add optional labels and the filter
     * @param type $searchOptions
     * @param QueryBuilder $qb
     */
    private function addLanguageFilters($searchOptions, QueryBuilder $qb)
    {
        $languages = Sparql\Escape::escapeLiterals($searchOptions['languages']);
        foreach ($searchOptions['label'] as $label) {
            $filter = '!bound(?'.$label.') || lang(?'.$label.') IN ('.$languages.')';
            $qb->filter($filter);
        }
    }

    /**
     * Add icon path to schemes
     *
     * @param array $schemes
     * @return array
     */
    private function addIconToScheme($schemes)
    {
        foreach ($schemes as $i => $scheme) {
            $scheme['iconPath'] = ConceptScheme::buildIconPath($scheme['uuid']);
            $schemes[$i] = $scheme;
        }
        return $schemes;
    }

    /**
     * Decode a string that has concat and group_concat values
     *
     * @param string $value
     * @return array
     */
    private function decodeConcat($value)
    {
        $decoded = [];
        $groups = explode($this->groupConcatSeperator, $value);
        foreach ($groups as $group) {
            $values = explode($this->concatSeperator, $group);
            $obj = [];
            foreach ($values as $groupValue) {
                if (empty($groupValue)) {
                    continue;
                }
                $fieldAndValue = explode($this->concatFieldSeperator, $groupValue);
                $fieldName = $fieldAndValue[0];
                $obj[$fieldName] = $fieldAndValue[1];
            }
            $decoded[] = $obj;
        }
        return $decoded;
    }
}
