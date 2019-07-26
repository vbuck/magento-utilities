<?php

/**
 * Reset configurable product stock status
 *
 * Use when configurable Stock Availability may be falsely set to "Out of Stock." Works by examining all children for
 * each parent and, when at least 1 child has available inventory, the parent is reset to "In Stock."
 *
 * How to Use
 *
 * php reset-configurable-stock-status.php
 *
 * You must update the `require` statement at the beginning of this script to point to your Magento boostrap file. Also,
 * you may update the `$scopeId` variable in the `launch` method if you want to target a specific scope.
 *
 * Options
 *
 *     --dry-run     Simulates a run but does not apply any stock status changes
 *     --report-mode Operates in dry-run mode, then outputs a CSV report of changes to be applied
 */

require 'app/bootstrap.php';

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Model\Stock\StockStatusRepository;
use Magento\CatalogInventory\Model\StockRegistry;
use Magento\CatalogInventory\Model\StockState;
use Magento\ConfigurableProduct\Model\LinkManagement;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Http;
use Magento\Framework\App\State;
use Magento\Framework\AppInterface;

class ResetConfigurableStockStatusApp extends Http implements AppInterface
{
    private $startTime;

    public function launch()
    {
        $scopeId = 0;
        $report = [['Parent SKU', 'Parent Name', 'Needs Reset']];

        if ($this->isReportMode()) {
            $this->log('--REPORT MODE-- no changes will be applied');
            \sleep(3);
        } elseif ($this->isDryRun()) {
            $this->log('--DRY RUN-- no changes will be applied');
            \sleep(3);
        }

        /** @var State $state */
        $state = $this->_objectManager->get(State::class);
        $state->setAreaCode('adminhtml');

        /** @var Collection $collection */
        $collection = $this->_objectManager->create(Collection::class);
        /** @var LinkManagement $linkManagement */
        $linkManagement = $this->_objectManager->create(LinkManagement::class);
        /** @var StockState $stockState */
        $stockState = $this->_objectManager->create(StockState::class);
        /** @var StockRegistry $stockRegistry */
        $stockRegistry = $this->_objectManager->create(StockRegistry::class);
        /** @var StockStatusRepository $stockStatusRepository */
        $stockStatusRepository = $this->_objectManager->create(StockStatusRepository::class);

        $collection->addFieldToFilter('type_id', 'configurable');

        $this->log('Scanning %d configurables', $collection->getSize());

        /** @var Configurable|Product $product */
        foreach ($collection as $product) {
            $this->log('Checking %s', $product->getSku());
            /** @var \Magento\Catalog\Api\Data\ProductInterface[] $children */
            $children = $linkManagement->getChildren($product->getSku());
            /** @var StockStatusInterface $status */
            $status = $stockRegistry->getStockStatusBySku($product->getSku(), $scopeId);

            if (empty($children) || $status->getStockStatus() === StockStatusInterface::STATUS_IN_STOCK) {
                $report[] = [$product->getSku(), $product->getName(), 'No'];
                continue;
            }

            $stock = [];
            /** @var \Magento\Catalog\Api\Data\ProductInterface $child */
            foreach ($children as $child) {
                $stock[] = $stockState->getStockQty($child->getId(), $scopeId);
            }

            $result = \count(\array_filter($stock)) > 0;
            $report[] = [$product->getSku(), $product->getName(), $result ? 'Yes' : 'No'];

            if ($result) {
                if (!$this->isDryRun()) {
                    $status->setStockStatus(StockStatusInterface::STATUS_IN_STOCK);
                    $stockStatusRepository->save($status);
                }

                $this->log('Updated stock status');
            }
        }

        if ($this->isReportMode()) {
            $path = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . uniqid('reset-configurable-stock-status-') . '.csv';
            $resource = \fopen($path, 'w');

            if (!$resource) {
                $this->log('ERROR: failed to write to report.');
            } else {
                foreach ($report as $row) {
                    \fputcsv($resource, $row);
                }

                \fclose($resource);

                $this->log('Created report: %s', $path);
            }
        }

        $this->log('Done');

        return $this->_response;
    }

    public function catchException(\Magento\Framework\App\Bootstrap $bootstrap, \Exception $exception)
    {
        echo $exception->getMessage();
    }

    private function isDryRun() : bool
    {
        global $argv;

        return $this->isReportMode() || !empty($argv) && \strcasecmp(\end($argv), '--dry-run') === 0;
    }

    private function isReportMode() : bool
    {
        global $argv;

        return !empty($argv) && \strcasecmp(\end($argv), '--report-mode') === 0;
    }

    private function log($message, ...$values) : void
    {
        if (!$this->startTime) {
            $this->startTime = \microtime(true);
        }

        $time = \str_pad(
            \round(\microtime(true) - $this->startTime, 1),
            8,
            ' ',
            STR_PAD_LEFT
        );
        $arguments = \array_merge([$message], $values);
        $output = \call_user_func_array('sprintf', $arguments);

        \fwrite(STDOUT, "[{$time}s]: {$output}" . PHP_EOL);
    }
}

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$app = $bootstrap->createApplication('ResetConfigurableStockStatusApp');

$bootstrap->run($app);

