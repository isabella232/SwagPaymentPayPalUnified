<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagPaymentPayPalUnified\Components;

use Doctrine\DBAL\Connection;
use Shopware\Components\NumberRangeIncrementerInterface;

class OrderNumberService
{
    /**
     * @var NumberRangeIncrementerInterface
     */
    private $numberRangeIncrementer;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var DependencyProvider
     */
    private $dependencyProvider;

    public function __construct(NumberRangeIncrementerInterface $numberRangeIncrementer, Connection $connection, DependencyProvider $dependencyProvider)
    {
        $this->numberRangeIncrementer = $numberRangeIncrementer;
        $this->connection = $connection;
        $this->dependencyProvider = $dependencyProvider;
    }

    /**
     * @return string
     */
    public function getOrderNumber()
    {
        return (string) $this->numberRangeIncrementer->increment(NumberRangeIncrementerDecorator::NAME_INVOICE);
    }

    /**
     * @param string $ordernumber
     *
     * @return void
     */
    public function restoreOrdernumberToPool($ordernumber)
    {
        if (!\is_string($ordernumber)) {
            $this->releaseOrderNumber();

            return;
        }

        $this->connection->createQueryBuilder()
            ->insert(NumberRangeIncrementerDecorator::POOL_DATABASE_TABLE_NAME)
            ->setValue('order_number', ':orderNumber')
            ->setParameter('orderNumber', $ordernumber)
            ->execute();

        $this->releaseOrderNumber();
    }

    /**
     * @return void
     */
    public function releaseOrderNumber()
    {
        $this->dependencyProvider->getSession()->offsetUnset(NumberRangeIncrementerDecorator::ORDERNUMBER_SESSION_KEY);
    }
}