<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Controller\Ajax;

use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseInstantSearch\Model\Client\ProxyClientProvider;
use DmLab\TypesenseInstantSearch\Model\ClientIp;
use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\RateGuard;
use DmLab\TypesenseInstantSearch\Model\Search\Availability;
use DmLab\TypesenseInstantSearch\Model\Search\QuerySpec;
use DmLab\TypesenseInstantSearch\Model\Search\ResultMapper;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Same-origin proxy for the storefront instant-search dropdown (the default `proxy` mode).
 *
 * The browser hits this endpoint instead of Typesense; the query runs server-side with a key derived
 * from the store's proxy parent key ({@see ProxyClientProvider}), never the admin key, so Typesense
 * never faces the internet, there is no CORS/CSP host and no key on the page. The request term
 * drives only `q` — every other search parameter comes from {@see QuerySpec} off admin config and is
 * embedded in the key, so a caller cannot widen `per_page`, `include_fields` or the visibility/status
 * filter. Hits are mapped to dropdown items by {@see ResultMapper}, the PHP twin
 * of the direct-mode JS mapper, so both modes render the identical row shape.
 *
 * It always answers 200 with a well-formed JSON envelope (`items`, `found`); a short term, no
 * searchable fields, a tripped rate guard or a Typesense error all degrade to an empty result — the
 * widget then falls back to native search rather than seeing a 500 or a raw engine error.
 * An unreachable Typesense or a store without a proxy key adds `unavailable: true`. Unreachability is
 * remembered by {@see Availability}, so a hung cluster costs one 1 s attempt per window instead of
 * tying up a PHP worker per keystroke.
 */
class Search implements HttpGetActionInterface
{
    /** Request parameter carrying the storefront search term. */
    private const PARAM_QUERY = 'q';

    /** Upper bound on the term length; a longer term is treated as no-result, never forwarded. */
    private const MAX_QUERY_LENGTH = 255;

    /** Per-call Typesense timeout (seconds) and attempt cap on the storefront request path. */
    private const TIMEOUT = 1;

    private const MAX_ATTEMPTS = 1;

    /**
     * @param Config $config storefront settings (enabled flag, min query length)
     * @param QuerySpec $querySpec builds the server-side Typesense request
     * @param ResultMapper $resultMapper maps hit documents to dropdown items
     * @param ProxyClientProvider $clientProvider client authenticated with the derived proxy key
     * @param StoreManagerInterface $storeManager resolves the current storefront store
     * @param RequestInterface $request the HTTP request carrying the term
     * @param JsonFactory $resultJsonFactory builds the JSON response
     * @param ClientIp $clientIp unspoofable client IP for the rate guard
     * @param RateGuard $rateGuard per-IP request throttle
     * @param LoggerInterface $logger records engine errors (never surfaced to the client)
     * @param Availability $availability short-lived "Typesense is down" flag
     */
    public function __construct(
        private readonly Config $config,
        private readonly QuerySpec $querySpec,
        private readonly ResultMapper $resultMapper,
        private readonly ProxyClientProvider $clientProvider,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ClientIp $clientIp,
        private readonly RateGuard $rateGuard,
        private readonly LoggerInterface $logger,
        private readonly Availability $availability
    ) {
    }

    /**
     * Answer the storefront term with mapped dropdown items, or an empty envelope on any short-circuit.
     */
    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $empty = ['items' => [], 'found' => 0];

        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Typesense instant-search proxy: unable to resolve the store scope.',
                ['exception' => $e]
            );

            return $result->setData($empty);
        }

        if (!$this->config->isEnabled($storeId)) {
            return $result->setData($empty);
        }

        $term = trim((string)$this->request->getParam(self::PARAM_QUERY, ''));
        $termLength = mb_strlen($term);
        if ($termLength < $this->config->getMinQueryLength($storeId) || $termLength > self::MAX_QUERY_LENGTH) {
            return $result->setData($empty);
        }

        if (!$this->rateGuard->allow($this->clientIp->get())) {
            return $result->setData($empty);
        }

        $unavailable = $empty + ['unavailable' => true];
        if ($this->availability->isDown()) {
            return $result->setData($unavailable);
        }

        $spec = $this->querySpec->build($term, $storeId);
        if ($spec === null) {
            return $result->setData($empty);
        }

        try {
            $client = $this->clientProvider->get($storeId, $spec['embedded']);
            if ($client === null) {
                $this->logger->warning(
                    'Typesense instant-search proxy: no proxy search key for store ' . $storeId
                    . '; run bin/magento dmlab:typesense:search-key:ensure.'
                );

                return $result->setData($unavailable);
            }

            $response = $client->request(
                'GET',
                '/collections/' . $spec['collection'] . '/documents/search',
                null,
                $spec['params'],
                timeout: self::TIMEOUT,
                maxAttempts: self::MAX_ATTEMPTS
            );

            return $result->setData([
                'items' => $this->resultMapper->map($this->documents($response), $storeId),
                'found' => (int)($response['found'] ?? 0),
            ]);
        } catch (TransportException $e) {
            $this->availability->markDown();
            $this->logger->error('Typesense instant-search proxy: Typesense unreachable.', ['exception' => $e]);

            return $result->setData($unavailable);
        } catch (\Throwable $e) {
            // Never echo the engine's raw error to the browser; degrade to native search.
            $this->logger->error('Typesense instant-search proxy query failed.', ['exception' => $e]);

            return $result->setData($empty);
        }
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
