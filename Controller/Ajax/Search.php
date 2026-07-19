<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Controller\Ajax;

use MageDevGroup\TypesenseCore\Model\Client\TypesenseClient;
use MageDevGroup\TypesenseInstantSearch\Model\Config;
use MageDevGroup\TypesenseInstantSearch\Model\RateGuard;
use MageDevGroup\TypesenseInstantSearch\Model\Search\QuerySpec;
use MageDevGroup\TypesenseInstantSearch\Model\Search\ResultMapper;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Same-origin proxy for the storefront instant-search dropdown (the default `proxy` mode).
 *
 * The browser hits this endpoint instead of Typesense; the query runs server-side through core's
 * client with the admin key, so Typesense never faces the internet, there is no CORS/CSP host and
 * no key on the page. The request term drives only `q` — every other search parameter comes from
 * {@see QuerySpec} off admin config, so a caller cannot widen `per_page`, `include_fields` or the
 * visibility/status filter. Hits are mapped to dropdown items by {@see ResultMapper}, the PHP twin
 * of the direct-mode JS mapper, so both modes render the identical row shape.
 *
 * It always answers 200 with a well-formed JSON envelope (`items`, `found`); a short term, no
 * searchable fields, a tripped rate guard or a Typesense error all degrade to an empty result — the
 * widget then falls back to native search rather than seeing a 500 or a raw engine error.
 */
class Search implements HttpGetActionInterface
{
    /** Request parameter carrying the storefront search term. */
    private const PARAM_QUERY = 'q';

    /** Upper bound on the term length; a longer term is treated as no-result, never forwarded. */
    private const MAX_QUERY_LENGTH = 255;

    /**
     * @param Config $config storefront settings (enabled flag, min query length)
     * @param QuerySpec $querySpec builds the server-side Typesense request
     * @param ResultMapper $resultMapper maps hit documents to dropdown items
     * @param TypesenseClient $client core's generic client (admin key, server-side)
     * @param StoreManagerInterface $storeManager resolves the current storefront store
     * @param RequestInterface $request the HTTP request carrying the term
     * @param JsonFactory $resultJsonFactory builds the JSON response
     * @param RemoteAddress $remoteAddress client IP for the rate guard
     * @param RateGuard $rateGuard per-IP request throttle
     * @param LoggerInterface $logger records engine errors (never surfaced to the client)
     */
    public function __construct(
        private readonly Config $config,
        private readonly QuerySpec $querySpec,
        private readonly ResultMapper $resultMapper,
        private readonly TypesenseClient $client,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly RemoteAddress $remoteAddress,
        private readonly RateGuard $rateGuard,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Answer the storefront term with mapped dropdown items, or an empty envelope on any short-circuit.
     */
    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $empty = ['items' => [], 'found' => 0];

        if (!$this->config->isEnabled()) {
            return $result->setData($empty);
        }

        $term = trim((string)$this->request->getParam(self::PARAM_QUERY, ''));
        $termLength = mb_strlen($term);
        if ($termLength < $this->config->getMinQueryLength() || $termLength > self::MAX_QUERY_LENGTH) {
            return $result->setData($empty);
        }

        if (!$this->rateGuard->allow((string)$this->remoteAddress->getRemoteAddress())) {
            return $result->setData($empty);
        }

        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Typesense instant-search proxy: unable to resolve the store scope.',
                ['exception' => $e]
            );

            return $result->setData($empty);
        }

        $spec = $this->querySpec->build($term, $storeId);
        if ($spec === null) {
            return $result->setData($empty);
        }

        try {
            $response = $this->client->request(
                'GET',
                '/collections/' . $spec['collection'] . '/documents/search',
                null,
                $spec['params']
            );
        } catch (\Throwable $e) {
            // Never echo the engine's raw error to the browser; degrade to native search.
            $this->logger->error('Typesense instant-search proxy query failed.', ['exception' => $e]);

            return $result->setData($empty);
        }

        return $result->setData([
            'items' => $this->resultMapper->map($this->documents($response), $storeId),
            'found' => (int)($response['found'] ?? 0),
        ]);
    }

    /**
     * Extract the hit documents from a Typesense search response.
     *
     * @param array<mixed> $response
     * @return array<int,array<string,mixed>>
     */
    private function documents(array $response): array
    {
        $documents = [];
        foreach ($response['hits'] ?? [] as $hit) {
            if (isset($hit['document']) && is_array($hit['document'])) {
                $documents[] = $hit['document'];
            }
        }

        return $documents;
    }
}
