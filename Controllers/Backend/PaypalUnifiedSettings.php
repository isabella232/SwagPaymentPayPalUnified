<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Shopware\Components\HttpClient\RequestException;
use SwagPaymentPayPalUnified\Components\Backend\CredentialsService;
use SwagPaymentPayPalUnified\Components\ExceptionHandlerServiceInterface;
use SwagPaymentPayPalUnified\Components\Services\ExceptionHandlerService;
use SwagPaymentPayPalUnified\Components\Services\OnboardingStatusService;
use SwagPaymentPayPalUnified\Models\Settings\AdvancedCreditDebitCard;
use SwagPaymentPayPalUnified\Models\Settings\General as GeneralSettingsModel;
use SwagPaymentPayPalUnified\Models\Settings\PayUponInvoice;
use SwagPaymentPayPalUnified\PayPalBundle\Components\LoggerServiceInterface;
use SwagPaymentPayPalUnified\PayPalBundle\Components\SettingsTable;
use SwagPaymentPayPalUnified\PayPalBundle\Services\ClientService;
use Symfony\Component\HttpFoundation\Response;

class Shopware_Controllers_Backend_PaypalUnifiedSettings extends Shopware_Controllers_Backend_Application
{
    const HAS_LIMITS_SUFFIX = '_HAS_LIMITS';

    const PAYMENTS_RECEIVABLE_RESPONSE_KEY = 'PAYMENTS_RECEIVABLE';
    const PRIMARY_EMAIL_CONFIRMED_RESPONSE_KEY = 'PRIMARY_EMAIL_CONFIRMED';

    /**
     * {@inheritdoc}
     */
    protected $model = GeneralSettingsModel::class;

    /**
     * {@inheritdoc}
     */
    protected $alias = 'settings';

    /**
     * @var ClientService
     */
    private $clientService;

    /**
     * @var ExceptionHandlerServiceInterface
     */
    private $exceptionHandler;

    /**
     * @var OnboardingStatusService
     */
    private $onboardingStatusService;

    /**
     * @var LoggerServiceInterface
     */
    private $logger;

    /**
     * {@inheritdoc}
     */
    public function preDispatch()
    {
        $this->clientService = $this->get('paypal_unified.client_service');
        $this->exceptionHandler = $this->get('paypal_unified.exception_handler_service');
        $this->onboardingStatusService = $this->container->get('paypal_unified.onboarding_status_service');
        $this->logger = $this->container->get('paypal_unified.logger_service');

        parent::preDispatch();
    }

    /**
     * This action handles the register webhook request.
     * It configures the RestClient to the provided credentials and announces
     * a wildcard webhook to the PayPal API.
     */
    public function registerWebhookAction()
    {
        // Generate URL
        /** @var Enlight_Controller_Router $router */
        $router = $this->get('front')->Router();
        $url = $router->assemble([
            'module' => 'frontend',
            'controller' => 'PaypalUnifiedWebhook',
            'action' => 'execute',
            'forceSecure' => 1,
        ]);
        $url = str_replace('http://', 'https://', $url);

        try {
            $this->configureClient();

            $webhookResource = $this->get('paypal_unified.webhook_resource');
            $webhookResource->create($url, ['*']);
        } catch (Exception $e) {
            $error = $this->exceptionHandler->handle($e, 'register webhooks');

            if ($error->getName() === ExceptionHandlerService::WEBHOOK_ALREADY_EXISTS_ERROR) {
                $this->View()->assign([
                    'success' => true,
                    'url' => $url,
                ]);

                return;
            }

            $this->View()->assign([
                'success' => false,
                'message' => $error->getCompleteMessage(),
            ]);

            return;
        }

        $this->View()->assign([
            'success' => true,
            'url' => $url,
        ]);
    }

    /**
     * Initialize the REST api client to check if the credentials are correct
     */
    public function validateAPIAction()
    {
        try {
            $this->configureClient();
            $this->View()->assign('success', true);
        } catch (Exception $e) {
            $error = $this->exceptionHandler->handle($e, 'validate API credentials');

            $this->View()->assign([
                'success' => false,
                'message' => $error->getCompleteMessage(),
            ]);
        }
    }

    /**
     * @return void
     */
    public function isCapableAction()
    {
        $shopId = (int) $this->Request()->getParam('shopId', 0);
        $sandbox = (bool) $this->Request()->getParam('sandbox', false);
        $payerId = $this->Request()->getParam('payerId');
        $paymentMethodCapabilityNames = $this->Request()->getParam('paymentMethodCapabilityNames');
        $productSubscriptionNames = $this->Request()->getParam('productSubscriptionNames');

        if ($shopId === 0) {
            $this->view->assign([
                'success' => false,
                'message' => 'The parameter "shopId" is required.',
            ]);

            return;
        }

        if ($payerId === null) {
            $this->view->assign([
                'success' => false,
                'message' => 'The parameter "payerId" is required.',
            ]);

            return;
        }

        if (!\is_array($paymentMethodCapabilityNames)) {
            $this->view->assign([
                'success' => false,
                'message' => 'The parameter "paymentMethodCapabilityNames" should be a array.',
            ]);

            return;
        }

        if (!\is_array($productSubscriptionNames)) {
            $this->view->assign([
                'success' => false,
                'message' => 'The parameter "productSubscriptionNames" should be a array.',
            ]);

            return;
        }

        $viewAssign = [];
        try {
            foreach ($paymentMethodCapabilityNames as $paymentMethodCapabilityName) {
                $isCapableResult = $this->onboardingStatusService->getIsCapableResult($payerId, $shopId, $sandbox, $paymentMethodCapabilityName);
                $viewAssign[$paymentMethodCapabilityName] = $isCapableResult->isCapable();
                $viewAssign[$paymentMethodCapabilityName . self::HAS_LIMITS_SUFFIX] = $isCapableResult->hasLimits();
                $viewAssign[self::PAYMENTS_RECEIVABLE_RESPONSE_KEY] = $isCapableResult->getIsPaymentsReceivable();
                $viewAssign[self::PRIMARY_EMAIL_CONFIRMED_RESPONSE_KEY] = $isCapableResult->getIsPrimaryEmailConfirmed();
            }
            foreach ($productSubscriptionNames as $productSubscriptionName) {
                $viewAssign[$productSubscriptionName] = $this->onboardingStatusService->isSubscribed($payerId, $shopId, $sandbox, $productSubscriptionName);
            }
        } catch (RequestException $exception) {
            $this->exceptionHandler->handle($exception, 'validate capability');

            $this->View()->assign([
                'success' => false,
                'message' => $exception->getMessage(),
                'body' => $exception->getBody(),
            ]);

            return;
        }

        $viewAssign['success'] = true;

        $this->view->assign($viewAssign);
    }

    /**
     * @return void
     */
    public function updateCredentialsAction()
    {
        $shopId = (int) $this->Request()->getParam('shopId');
        $partnerId = (string) $this->request->getParam('partnerId');
        $authCode = (string) $this->request->getParam('authCode');
        $sharedId = (string) $this->request->getParam('sharedId');
        $nonce = (string) $this->request->getParam('nonce');
        $sandbox = (bool) $this->request->getParam('sandbox');

        $this->logger->debug(sprintf('%s START', __METHOD__));

        /** @var CredentialsService $credentialsService */
        $credentialsService = $this->get('paypal_unified.backend.credentials_service');

        try {
            $accessToken = $credentialsService->getAccessToken($authCode, $sharedId, $nonce, $sandbox);
            $credentials = $credentialsService->getCredentials($accessToken, $partnerId, $sandbox);

            $credentialsService->updateCredentials($credentials, $shopId, $sandbox);

            $this->updateOnboardingStatus($shopId, $sandbox);
        } catch (Exception $e) {
            $this->response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
            $this->view->assign([
                'exception' => $e->getMessage(),
                'trace' => $e->getTrace(),
            ]);

            return;
        }
    }

    private function configureClient()
    {
        $request = $this->Request();
        $shopId = (int) $request->getParam('shopId');
        $sandbox = $request->getParam('sandbox', 'false') !== 'false';
        $restId = $request->getParam('clientId');
        $restSecret = $request->getParam('clientSecret');
        $restIdSandbox = $request->getParam('sandboxClientId');
        $restSecretSandbox = $request->getParam('sandboxClientSecret');

        $this->clientService->configure([
            'clientId' => $restId,
            'clientSecret' => $restSecret,
            'sandboxClientId' => $restIdSandbox,
            'sandboxClientSecret' => $restSecretSandbox,
            'sandbox' => $sandbox,
            'shopId' => $shopId,
        ]);
    }

    /**
     * @param int  $shopId
     * @param bool $sandbox
     *
     * @return void
     */
    private function updateOnboardingStatus($shopId, $sandbox)
    {
        $this->logger->debug(sprintf('%s START', __METHOD__));

        $entityManager = $this->container->get('models');
        $settingsService = $this->container->get('paypal_unified.settings_service');

        $puiSettings = $settingsService->getSettings($shopId, SettingsTable::PAY_UPON_INVOICE);
        $acdcSettings = $settingsService->getSettings($shopId, SettingsTable::ADVANCED_CREDIT_DEBIT_CARD);

        $defaultSettings = [
            'shopId' => $shopId,
            'onboardingCompleted' => false,
            'sandboxOnboardingCompleted' => false,
            'active' => false,
        ];

        if (!$puiSettings instanceof PayUponInvoice) {
            $this->logger->debug(sprintf('%s CREATE NEW %s SETTINGS OBJECT', __METHOD__, PayUponInvoice::class));

            $puiSettings = (new PayUponInvoice())->fromArray($defaultSettings);
        }

        if (!$acdcSettings instanceof AdvancedCreditDebitCard) {
            $this->logger->debug(sprintf('%s CREATE NEW %s SETTINGS OBJECT', __METHOD__, AdvancedCreditDebitCard::class));

            $acdcSettings = (new AdvancedCreditDebitCard())->fromArray($defaultSettings);
        }

        $this->logger->debug(sprintf('%s IS SANDBOX: %s', __METHOD__, $sandbox ? 'TRUE' : 'FALSE'));

        if ($sandbox) {
            $puiSettings->setSandboxOnboardingCompleted(true);
            $acdcSettings->setSandboxOnboardingCompleted(true);
        } else {
            $puiSettings->setOnboardingCompleted(true);
            $acdcSettings->setOnboardingCompleted(true);
        }

        $entityManager->persist($puiSettings);
        $entityManager->persist($acdcSettings);

        $entityManager->flush();

        $this->logger->debug(sprintf('%s ONBOARDING STATUS SUCCESSFUL UPDATED', __METHOD__));
    }
}
