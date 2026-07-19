<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Controller\Adminhtml\System\Config;

use MageDevGroup\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * Adminhtml endpoint behind the "Regenerate key" button.
 *
 * Re-provisions the search-only key ({@see SearchKeyProvisioner::regenerate()}) and answers JSON the
 * button uses to report success or the failure reason. A missing admin key or a Typesense error is
 * caught and returned as a message, never a 500 or a raw engine error.
 */
class RegenerateKey extends Action implements HttpPostActionInterface
{
    /** Same ACL resource as the config screen the button lives on. */
    public const ADMIN_RESOURCE = 'Magento_Config::config';

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
     * Re-provision the key and report the outcome.
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
                'message' => 'Could not regenerate the search-only key: ' . $e->getMessage()
                    . ' Check that the Typesense admin API key is set under Catalog Search.',
            ]);
        }

        return $result->setData([
            'success' => true,
            'message' => 'Search-only key regenerated.',
        ]);
    }
}
