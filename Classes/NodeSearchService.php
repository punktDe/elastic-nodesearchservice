<?php
declare(strict_types=1);

namespace PunktDe\Elastic\NodeSearchService;

/*
 *  (c) 2020 punkt.de GmbH - Karlsruhe, Germany - http://punkt.de
 *  All rights reserved.
 */

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\ElasticSearchClient;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Neos\Domain\Service\NodeSearchServiceInterface;
use Neos\Utility\Arrays;
use Psr\Log\LoggerInterface;


/**
 * Find nodes based on a elasticsearch query
 *
 * @Flow\Scope("singleton")
 */
class NodeSearchService implements NodeSearchServiceInterface
{

    /**
     * @Flow\Inject
     * @var ElasticSearchClient
     */
    protected $elasticSearchClient;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\InjectConfiguration(path="searchStrategies", package="PunktDe.Elastic.NodeSearchService")
     * @var array
     */
    protected $searchStrategies = [];

    /**
     * @Flow\InjectConfiguration(path="logRequests", package="PunktDe.Elastic.NodeSearchService")
     * @var bool
     */
    protected $logRequests = false;

    /**
     * @param string $term
     * @param array $searchNodeTypes
     * @param Context $context
     * @param NodeInterface|null $startingPoint
     * @return array|void
     * @throws \Flowpack\ElasticSearch\ContentRepositoryAdaptor\Exception
     * @throws \Flowpack\ElasticSearch\ContentRepositoryAdaptor\Exception\ConfigurationException
     * @throws \Flowpack\ElasticSearch\Exception
     * @throws \Neos\Flow\Http\Exception
     * @throws \JsonException
     */
    public function findByProperties($term, array $searchNodeTypes, Context $context, NodeInterface $startingPoint = null)
    {
        $this->elasticSearchClient->setContextNode($startingPoint ?? $context->getRootNode());
        $request = $this->searchStrategies['default']['request'];

        $replacements = [
            'ARGUMENT_SEARCHNODETYPES' => array_values($searchNodeTypes),
            'ARGUMENT_TERM' => $term,
            'ARGUMENT_STARTINGPOINT' => $startingPoint instanceof NodeInterface ? $startingPoint->getPath() : $context->getRootNode(),
        ];

        array_walk_recursive(
            $request,
            static function (&$value) use ($replacements) {
                $value = $replacements[$value] ?? $value;
            }
        );

        $requestAsJson = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE, 512);

        $timeBefore = microtime(true);
        $response = $this->elasticSearchClient->getIndex()->request('GET', '/_search', [], $requestAsJson);
        $timeAfterwards = microtime(true);

        if ($response->getStatusCode() !== 200) {
            $this->logger->error('Error while executing the Elasticsearch query', LogEnvironment::fromMethodName(__METHOD__));
            return [];
        }

        $result = $response->getTreatedContent();
        $hits = Arrays::getValueByPath($result, 'hits.hits');

        $this->logRequests && $this->logger->debug(sprintf('Executed Query: %s -- execution time: %s ms -- Number of results returned: %s -- Total Results: %s', $requestAsJson, (($timeAfterwards - $timeBefore) * 1000), count($hits), 1), LogEnvironment::fromMethodName(__METHOD__));


        return $this->convertHitsToNodes($hits);
    }

    /**
     * @param array $hits
     * @return array Array of Node objects
     */
    protected function convertHitsToNodes(array $hits): array
    {
        $nodes = [];

        foreach ($hits as $hit) {
            $nodePath = $hit[isset($hit['fields']['__path']) ? 'fields' : '_source']['__path'];
            if (is_array($nodePath)) {
                $nodePath = current($nodePath);
            }
            $node = $this->elasticSearchClient->getContextNode()->getNode($nodePath);
            if ($node instanceof NodeInterface && !isset($nodes[$node->getIdentifier()])) {
                $nodes[$node->getIdentifier()] = $node;
            }
        }

        $this->logger->debug('Returned nodes: ' . count($nodes), LogEnvironment::fromMethodName(__METHOD__));
        return array_values($nodes);
    }
}
