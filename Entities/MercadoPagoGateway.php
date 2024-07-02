<?php

namespace Modules\MercadoPagoGateway\Entities;

use App\Models\Gateways\Gateway;
use App\Models\Gateways\PaymentGatewayInterface;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Client\Payment\PaymentClient;

/**
 * Class MercadoPagoGateway
 *
 * MercadoPagoGateway implements the PaymentGatewayInterface, defining the contract for payment gateways within the system.
 * It provides methods to handle payments, receive responses from the payment gateway, process refunds, configure the gateway,
 * fetch configuration, and check subscriptions.
 *
 * @package Modules\MercadoPagoGateway\Entities
 */
class MercadoPagoGateway implements PaymentGatewayInterface
{

    /**
     * The method is responsible for preparing and processing the payment get the gateway and payment objects
     * use dd($gateway $payment) for debugging
     *
     * @param Gateway $gateway
     * @param Payment $payment
     */
    public static function processGateway(Gateway $gateway, Payment $payment)
    {
        // Authenticate with the MercadoPago API
        self::authenticate();

        $paymentMethods = [
            "excluded_payment_methods" => [],
            "installments" => 1,
            "default_installments" => 1
        ];
    
        $backUrls = array(
            'success' => route('payment.success', $payment->id),
            'failure' => route('payment.cancel', $payment->id),
        );

        $items = [
            [
                "id" => $payment->id,
                "title" => settings('app_name'),
                "description" => $payment->description,
                "currency_id" => "ARS",
                "quantity" => 1,
                "unit_price" => $payment->amount,
            ],
        ];

        $payer = [
            "name" => $payment->user->first_name,
            "surname" => $payment->user->last_name,
            "email" => $payment->user->email,
        ];
    
        $request = [
            "items" => $items,
            "payer" => $payer,
            "payment_methods" => $paymentMethods,
            "back_urls" => $backUrls,
            "statement_descriptor" => settings('app_name'),
            "external_reference" => $payment->id,
            "expires" => false,
            "auto_return" => 'approved',
            'notification_url' => route('payment.return', ['gateway' => self::endpoint(), 'payment' => $payment->id]),
        ];

        try {
            // Instantiate a new Preference Client
            $client = new PreferenceClient();

            // Send the request that will create the new preference for user's checkout flow
            $preference = $client->create($request);

            return redirect()->to($preference->sandbox_init_point);
        } catch(\Exception $e) {

        }
    }

    protected static function authenticate()
    {
        $gateway = Gateway::query()->where('driver', 'MercadoPagoGateway')->firstOrFail();

        // Getting the access token from .env file (create your own function)
        $mpAccessToken = $gateway->config()['access_token'];
        // Set the token the SDK's config
        MercadoPagoConfig::setAccessToken($mpAccessToken);
    }

    /**
     * Handles the response from the payment gateway. It uses a Request object to receive and handle the response appropriately.
     * endpoint: payment/return/{endpoint_gateway}
     * @param Request $request
     */
    public static function returnGateway(Request $request)
    {
        $gateway = Gateway::query()->where('driver', 'MercadoPagoGateway')->firstOrFail();

        if (!$request->get('action') OR $request->get('action') !== 'payment.created') {
            return response()->json(['error' => 'Invalid action type'], 400);
        }

        if (!$request->get('live_mode')) {
            return response()->json(['error' => 'Transaction is not in live mode'], 400);
        }

        // Get the payment ID from the request
        $paymentId = $request->get('payment');
        $payment = Payment::query()->where('id', $paymentId)->firstOrFail();

        if (!$payment) {
            return response()->json(['error' => 'Payment ID not provided'], 400);
        }

        $payment->completed();
    }

    /**
     * Handles refunds. It takes a Payment object and additional data required for processing a refund.
     * An optional method to add user refund support
     *
     * @param Payment $payment
     * @param array $data
     */
    public static function processRefund(Payment $payment, array $data)
    {

    }

    /**
     * Defines the configuration for the payment gateway. It returns an array with data defining the gateway driver,
     * type, class, endpoint, refund support, etc.
     *
     * @return array
     */
    public static function drivers(): array
    {
        return [
            'MercadoPagoGateway' => [
                'driver' => 'MercadoPagoGateway',
                'type' => 'once', // subscription
                'class' => 'Modules\MercadoPagoGateway\Entities\MercadoPagoGateway',
                'endpoint' => self::endpoint(),
                'refund_support' => false,
            ]
        ];
    }

    /**
     * Defines the endpoint for the payment gateway. This is an ID used to automatically determine which gateway to use.
     *
     * @return string
     */
    public static function endpoint(): string
    {
        return 'mercado-pago-gateway';
    }

    /**
     * Returns an array with the configuration for the payment gateway.
     * These options are displayed for the administrator to configure.
     * You can access them: $gateway->config()
     * @return array
     */
    public static function getConfigMerge(): array
    {
        return [
            'access_token' => '',
        ];
    }

    /**
     * Checks the status of a subscription in the payment gateway. If the subscription is active, it returns true; otherwise, it returns false.
     * Do not change this method if you are not using subscriptions
     * @param Gateway $gateway
     * @param $subscriptionId
     * @return bool
     */
    public static function checkSubscription(Gateway $gateway, $subscriptionId): bool
    {
        return false;
    }
}