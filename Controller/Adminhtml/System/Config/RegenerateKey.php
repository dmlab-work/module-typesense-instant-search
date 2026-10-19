<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Controller\Adminhtml\System\Config;

use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * Adminhtml endpoint behind the "Regenerate key" button.
 *
 * Rotates every store's keys ({@see SearchKeyProvisioner::regenerate()}) and answers JSON the
 * button uses to report success or the failure reason. A missing admin key or a Typesense error is
 * caught and returned as a translated message, never a 500.
 */
class RegenerateKey extends Action implements HttpPostActionInterface
{
    /** Same ACL resource as the config section the button lives on. */
    public const ADMIN_RESOURCE = 'DmLab_TypesenseIndexer::config';

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param SearchKeyProvisioner $provisioner
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly SearchKeyProvisioner $provisioner,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Rotate all stores' keys and report the outcome.
     */
    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();

        try {
            $this->provisioner->regenerate();
        } catch (\Throwable $e) {
            $this->logger->error('Typesense instant-search key regeneration failed.', ['exception' => $e]);

            return $result->setData([
                'success' => false,
                'message' => (string)__(
                    'Could not regenerate the search-only keys: %1 Check the Typesense admin API key.',
                    $e->getMessage()
                ),
            ]);
        }

        return $result->setData([
            'success' => true,
            'message' => (string)__('Search-only keys regenerated for all store views.'),
        ]);
    }
}
