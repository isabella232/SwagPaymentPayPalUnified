<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagPaymentPayPalUnified\Tests\Functional\Components\Services\OrderBuilder\OrderHandler;

use Generator;
use PHPUnit\Framework\TestCase;
use SwagPaymentPayPalUnified\Components\PayPalOrderParameter\PayPalOrderParameter;
use SwagPaymentPayPalUnified\Components\Services\SettingsService;
use SwagPaymentPayPalUnified\PayPalBundle\PaymentType;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Api\Order\PurchaseUnit;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Api\Order\PurchaseUnit\Amount;
use SwagPaymentPayPalUnified\PayPalBundle\V2\Api\Order\PurchaseUnit\Amount\Breakdown;
use SwagPaymentPayPalUnified\Tests\Functional\ContainerTrait;
use SwagPaymentPayPalUnified\Tests\Functional\ReflectionHelperTrait;

class AbstractOrderHandlerCreatePurchaseUnitTest extends TestCase
{
    use ContainerTrait;
    use ReflectionHelperTrait;

    /**
     * @dataProvider createPurchaseUnitsTestDataProvider
     *
     * @param bool $submitCart
     * @param bool $shouldHaveAddress
     * @param bool $shouldHaveAItemList
     *
     * @return void
     */
    public function testCreatePurchaseUnits(
        PayPalOrderParameter $paypalOrderParameter,
        $submitCart,
        $shouldHaveAddress,
        $shouldHaveAItemList
    ) {
        $abstractOrderHandler = $this->createAbstractOrderHandler($submitCart);

        $reflectionMethod = $this->getReflectionMethod(OrderHandlerMock::class, 'createPurchaseUnits');
        $reflectionMethod->setAccessible(true);

        $purchaseUnits = $reflectionMethod->invoke($abstractOrderHandler, $paypalOrderParameter);
        $purchaseUnit = $purchaseUnits[0];
        static::assertInstanceOf(PurchaseUnit::class, $purchaseUnit);

        if ($shouldHaveAddress) {
            static::assertNotEmpty($purchaseUnit->getShipping());
        } else {
            static::assertNull($purchaseUnit->getShipping());
        }

        $amount = $purchaseUnit->getAmount();
        static::assertInstanceOf(Amount::class, $amount);
        $breakdown = $amount->getBreakdown();
        if ($breakdown instanceof Breakdown) {
            $breakdownSum = ($breakdown->getItemTotal() ? (float) $breakdown->getItemTotal()->getValue() : 0.0)
                + ($breakdown->getShipping() ? (float) $breakdown->getShipping()->getValue() : 0.0)
                + ($breakdown->getTaxTotal() ? (float) $breakdown->getTaxTotal()->getValue() : 0.0)
                - ($breakdown->getDiscount() ? (float) $breakdown->getDiscount()->getValue() : 0.0);
            static::assertSame($purchaseUnit->getAmount()->getValue(), (string) $breakdownSum);
        }

        if ($shouldHaveAItemList) {
            static::assertNotEmpty($purchaseUnit->getItems());
        } else {
            static::assertNull($purchaseUnit->getItems());
        }
    }

    /**
     * @return Generator<array<int,mixed>>
     */
    public function createPurchaseUnitsTestDataProvider()
    {
        yield 'With PaymentType::PAYPAL_EXPRESS_V2' => [
            $this->createPayPalOrderParameter(PaymentType::PAYPAL_EXPRESS_V2, 'b2c_customer', 'b2c_basket'),
            false,
            false,
            false,
        ];

        yield 'With PaymentType::PAYPAL_EXPRESS_V2 and submit cart' => [
            $this->createPayPalOrderParameter(PaymentType::PAYPAL_EXPRESS_V2, 'b2c_customer', 'b2c_basket'),
            true,
            false,
            true,
        ];

        yield 'With PaymentType::PAYPAL_CLASSIC_V2' => [
            $this->createPayPalOrderParameter(PaymentType::PAYPAL_CLASSIC_V2, 'b2c_customer', 'b2c_basket'),
            false,
            true,
            false,
        ];

        yield 'With PaymentType::PAYPAL_CLASSIC_V2 and submit cart' => [
            $this->createPayPalOrderParameter(PaymentType::PAYPAL_CLASSIC_V2, 'b2c_customer', 'b2c_basket'),
            true,
            true,
            true,
        ];

        yield 'With PaymentType::PAYPAL_ADVANCED_CREDIT_DEBIT_CARD' => [
            $this->createPayPalOrderParameter(PaymentType::PAYPAL_ADVANCED_CREDIT_DEBIT_CARD, 'b2c_customer', 'b2c_basket'),
            false,
            true,
            false,
        ];

        yield 'With PaymentType::PAYPAL_ADVANCED_CREDIT_DEBIT_CARD and submit cart' => [
            $this->createPayPalOrderParameter(PaymentType::PAYPAL_ADVANCED_CREDIT_DEBIT_CARD, 'b2c_customer', 'b2c_basket'),
            true,
            true,
            true,
        ];

        yield 'With PaymentType::PAYPAL_SMART_PAYMENT_BUTTONS_V2' => [
            $this->createPayPalOrderParameter(PaymentType::PAYPAL_SMART_PAYMENT_BUTTONS_V2, 'b2c_customer', 'b2c_basket'),
            false,
            true,
            false,
        ];

        yield 'With PaymentType::PAYPAL_SMART_PAYMENT_BUTTONS_V2 and submit cart' => [
            $this->createPayPalOrderParameter(PaymentType::PAYPAL_SMART_PAYMENT_BUTTONS_V2, 'b2c_customer', 'b2c_basket'),
            true,
            true,
            true,
        ];

        yield 'With PaymentType::PAYPAL_PAY_UPON_INVOICE_V2' => [
            $this->createPayPalOrderParameter(PaymentType::PAYPAL_PAY_UPON_INVOICE_V2, 'b2c_customer', 'b2c_basket'),
            false,
            true,
            true,
        ];

        yield 'With PaymentType::PAYPAL_PAY_UPON_INVOICE_V2 and submit cart' => [
            $this->createPayPalOrderParameter(PaymentType::PAYPAL_PAY_UPON_INVOICE_V2, 'b2c_customer', 'b2c_basket'),
            true,
            true,
            true,
        ];

        yield 'With PaymentType::PAYPAL_CLASSIC_V2 and submit cart and b2b customer' => [
            $this->createPayPalOrderParameter(PaymentType::PAYPAL_CLASSIC_V2, 'b2b_customer', 'b2b_basket'),
            true,
            true,
            true,
        ];
    }

    /**
     * @param bool $submitCart
     *
     * @return OrderHandlerMock
     */
    private function createAbstractOrderHandler($submitCart)
    {
        return new OrderHandlerMock(
            $this->createSettingsService($submitCart),
            $this->getContainer()->get('paypal_unified.paypal_order.item_list_provider'),
            $this->getContainer()->get('paypal_unified.paypal_order.amount_provider'),
            $this->getContainer()->get('paypal_unified.common.return_url_helper'),
            $this->getContainer()->get('shopware_storefront.context_service'),
            $this->getContainer()->get('paypal_unified.phone_number_builder'),
            $this->getContainer()->get('paypal_unified.common.price_formatter')
        );
    }

    /**
     * @param PaymentType::* $paymentType
     * @param string         $customerFixtureName
     * @param string         $cartFixtureName
     *
     * @return PayPalOrderParameter
     */
    private function createPayPalOrderParameter($paymentType, $customerFixtureName, $cartFixtureName)
    {
        $customerData = require __DIR__ . sprintf('/_fixtures/%s.php', $customerFixtureName);
        $basketData = require __DIR__ . sprintf('/_fixtures/%s.php', $cartFixtureName);

        return new PayPalOrderParameter(
            $customerData,
            $basketData,
            $paymentType,
            'basketUniqueId',
            'paymentToken'
        );
    }

    /**
     * @param bool $submitCart
     *
     * @return SettingsService
     */
    private function createSettingsService($submitCart = false)
    {
        $settingsService = $this->createMock(SettingsService::class);

        if ($submitCart) {
            $settingsService->method('get')->willReturn(true);
        }

        return $settingsService;
    }
}
