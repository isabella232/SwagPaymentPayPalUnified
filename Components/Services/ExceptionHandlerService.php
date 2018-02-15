<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagPaymentPayPalUnified\Components\Services;

use Shopware\Components\HttpClient\RequestException;
use SwagPaymentPayPalUnified\Components\ExceptionHandlerServiceInterface;
use SwagPaymentPayPalUnified\Components\PayPalApiException;
use SwagPaymentPayPalUnified\PayPalBundle\Components\LoggerServiceInterface;
use SwagPaymentPayPalUnified\PayPalBundle\Structs\ErrorResponse;
use SwagPaymentPayPalUnified\PayPalBundle\Structs\GenericErrorResponse;

class ExceptionHandlerService implements ExceptionHandlerServiceInterface
{
    const DEFAULT_MESSAGE = 'An error occurred: ';
    const LOG_MESSAGE = 'Could not %s due to a communication failure';

    /**
     * @var LoggerServiceInterface
     */
    private $loggerService;

    /**
     * @param LoggerServiceInterface $loggerService
     */
    public function __construct(LoggerServiceInterface $loggerService)
    {
        $this->loggerService = $loggerService;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(\Exception $e, $currentAction)
    {
        $exceptionMessage = $e->getMessage();

        if (!($e instanceof RequestException)) {
            $this->loggerService->error(sprintf(self::LOG_MESSAGE, $currentAction), [
                'message' => $exceptionMessage,
            ]);

            return new PayPalApiException(
                $e->getCode(),
                self::DEFAULT_MESSAGE . $exceptionMessage
            );
        }

        $requestBody = $e->getBody();

        $this->loggerService->error(sprintf(self::LOG_MESSAGE, $currentAction), [
            'message' => $exceptionMessage,
            'payload' => $requestBody,
        ]);

        if (!$requestBody) {
            return new PayPalApiException(
                $e->getCode(),
                self::DEFAULT_MESSAGE . $exceptionMessage
            );
        }

        $requestBody = json_decode($requestBody, true);

        if (!is_array($requestBody)) {
            return new PayPalApiException(
                $e->getCode(),
                self::DEFAULT_MESSAGE . $exceptionMessage
            );
        }

        if (array_key_exists('error', $requestBody) && array_key_exists('error_description', $requestBody)) {
            $genericErrorStruct = GenericErrorResponse::fromArray($requestBody);

            return new PayPalApiException(
                $genericErrorStruct->getError(),
                self::DEFAULT_MESSAGE . $genericErrorStruct->getErrorDescription()
            );
        }

        $errorStruct = ErrorResponse::fromArray($requestBody);

        if (!$errorStruct) {
            return new PayPalApiException(
                $e->getCode(),
                self::DEFAULT_MESSAGE . $exceptionMessage
            );
        }

        $message = self::DEFAULT_MESSAGE . $errorStruct->getMessage();
        $errorDetail = $errorStruct->getDetails()[0];

        if ($errorDetail) {
            $message .= ': ' . $errorDetail->getField() . ', ' . $errorDetail->getIssue();
        }

        return new PayPalApiException(
            $errorStruct->getName(),
            $message
        );
    }
}