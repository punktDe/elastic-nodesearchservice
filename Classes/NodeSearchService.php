<?php
declare(strict_types=1);

namespace PunktDe\Elastic\NodeSearchService;

/*
 * This file is part of the PunktDe.Elastic.NodeSearchService package.
 *
 * This package is open source software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\ElasticSearchClient;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Neos\Domain\Service\NodeSearchServiceInterface;
use Neos\Utility\Arrays;
use Neos\Utility\PositionalArraySorter;
use Psr\Log\LoggerInterface;
use PunktDe\Elastic\NodeSearchService\Service\EelEvaluationService;

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
     * @Flow\Inject
     * @var EelEvaluationService
     */
    protected $eelEvaluationService;

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
     * @Flow\InjectConfiguration(path="fallBackOnEmptyResult", package="PunktDe.Elastic.NodeSearchService")
     * @var bool
     */
    protected $fallBackOnEmptyResult = false;

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Service\NodeSearchService
     */
    protected $datbaseNodeSearchService;

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
        $strategy = $this->determineSearchStrategy($term, $searchNodeTypes, $context, $startingPoint = null);

        if (empty($strategy)) {
            $this->logger->info(sprintf('No searchStrategy could be determined for StartingPoint "%s" and NodeTypes "%s", falling back to database search.', (string)$startingPoint, implode(',', $searchNodeTypes)), LogEnvironment::fromMethodName(__METHOD__));
            return $this->datbaseNodeSearchService->findByProperties($term, $searchNodeTypes, $context, $startingPoint);
        }

        if (!isset($strategy['request']) || !is_array($strategy['request'])) {
            $this->logger->error('No request was defined for the given search strategy.', LogEnvironment::fromMethodName(__METHOD__));
            return [];
        }

        $request = $strategy['request'];

        $replacements = [
            'ARGUMENT_SEARCHNODETYPES' => array_values($searchNodeTypes),
            'ARGUMENT_TERM' => $term,
            'ARGUMENT_STARTINGPOINT' => $startingPoint instanceof NodeInterface ? $startingPoint->getPath() : $context->getRootNode()->getPath(),
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

        $this->logRequests && $this->logger->debug(sprintf('Executed Query: %s -- execution time: %s ms -- Number of results returned: %s', $requestAsJson, (($timeAfterwards - $timeBefore) * 1000), count($hits)), LogEnvironment::fromMethodName(__METHOD__));

        $timeBefore = microtime(true);
        $nodes = $this->convertHitsToNodes($hits);
        $timeAfterwards = microtime(true);

        if (empty($nodes) && $this->fallBackOnEmptyResult) {
            $this->logger->info('Result was empty, falling back to database search.', LogEnvironment::fromMethodName(__METHOD__));
            return $this->datbaseNodeSearchService->findByProperties($term, $searchNodeTypes, $context, $startingPoint);
        }

        $this->logRequests && $this->logger->debug(sprintf('Loaded and dehydrated %s nodes in %s ms', count($nodes), (($timeAfterwards - $timeBefore) * 1000)), LogEnvironment::fromMethodName(__METHOD__));
        return $nodes;
    }

    protected function determineSearchStrategy($term, array $searchNodeTypes, Context $context, NodeInterface $startingPoint = null): array
    {
        $sortedSearchStrategies = new PositionalArraySorter($this->searchStrategies);

        foreach ($sortedSearchStrategies->toArray() as $searchStrategyIdentifier => $searchStrategy) {
            if (!isset($searchStrategy['condition']) || !$this->eelEvaluationService->isValidExpression($searchStrategy['condition'])) {
                $this->logger->warning(sprintf('No search condition was defined for strategy %s', $searchStrategyIdentifier), LogEnvironment::fromMethodName(__METHOD__));
                continue;
            }

            $shouldUse = $this->eelEvaluationService->evaluate($searchStrategy['condition'], [
                'term' => $term,
                'searchNodeTypes' => array_values($searchNodeTypes),
                'context' => $context,
                'startingPoint' => $startingPoint,
            ]);

            if ($shouldUse === true) {
                $this->logger->debug('Using searchStrategy ' . $searchStrategyIdentifier, LogEnvironment::fromMethodName(__METHOD__));
                return $searchStrategy;
            }
        }

        return [];
    }

    /**
     * @param array $hits
     * @return array Array of Node objects
     */
    protected function convertHitsToNodes(array $hits): array
    {
        $nodes = [];

        foreach ($hits as $hit) {
            $nodePath = $hit[isset($hit['fields']['neos_path']) ? 'fields' : '_source']['neos_path'];
            if (is_array($nodePath)) {
                $nodePath = current($nodePath);
            }
            $node = $this->elasticSearchClient->getContextNode()->getNode($nodePath);
            if ($node instanceof NodeInterface && !isset($nodes[$node->getIdentifier()])) {
                $nodes[$node->getIdentifier()] = $node;
            }
        }

        return array_values($nodes);
    }
}
