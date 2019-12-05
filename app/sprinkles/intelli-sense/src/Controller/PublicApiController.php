<?php

namespace UserFrosting\Sprinkle\IntelliSense\Controller;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use UserFrosting\Fortress\RequestDataTransformer;
use UserFrosting\Fortress\RequestSchema;
use UserFrosting\Fortress\ServerSideValidator;
use UserFrosting\Fortress\Adapter\JqueryValidationAdapter;
use UserFrosting\Sprinkle\Account\Controller\Exception\SpammyRequestException;
use UserFrosting\Sprinkle\Account\Facades\Password;
use UserFrosting\Sprinkle\Account\Util\Util as AccountUtil;
use UserFrosting\Sprinkle\Core\Controller\SimpleController;
use UserFrosting\Sprinkle\Core\Mail\EmailRecipient;
use UserFrosting\Sprinkle\Core\Mail\TwigMailMessage;
use UserFrosting\Sprinkle\Core\Util\Captcha;
use UserFrosting\Support\Exception\BadRequestException;
use UserFrosting\Support\Exception\ForbiddenException;
use UserFrosting\Support\Exception\NotFoundException;

use UserFrosting\Sprinkle\GeoSense\Database\Models\ApiKey;

/**
 * PublicApiController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class PublicApiController extends SimpleController 
{
    public function showAPIKeyMaintenancePage(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_public_api')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * assign some defaults to start off with
         */
        $api_key_expired = false;
        $api_key_value   = '';

        var_dump($args['refresh']);

        /**
         * check if user is requesting an API key refresh
         */
        $refresh = isset($args['refresh']) ? true : false;

        if ($refresh) {
            /**
             * generate an API key and update the api_key value for the user
             */
            $new_api_key_value = $this->generateAPIKey();

            $current_api_key = ApiKey::where('user_id', $currentUser->id)->first();;

            $current_api_key->value = $new_api_key_value;
            $current_api_key->issued_at = time();
            $current_api_key->expires_at = (time() + (60*60*24*365));

            $current_api_key->save();

            $api_key_value = $new_api_key_value;
        } else {
            /**
             * here we can check whether an API key exists:
             * if api_key is not already set, we generate one for the requesting user
             */
            if (!isset($currentUser->api_key->value)) {
                /**
                 * generate new API key value and attach new object to user
                 */

                $new_api_key = new ApiKey;

                $new_api_key->user_id = $currentUser->id;
                $new_api_key->issued_at = time();
                $new_api_key->expires_at = (time() + (60*60*24*365));
                $new_api_key->value = $this->generateAPIKey();

                $new_api_key->save();

                $api_key_value = $new_api_key['value'];
            } else {
                /**
                 * user has an API key so we check whether it has expired or not
                 */
                $current_api_key = $currentUser->api_key;
                if ($currentUser->api_key->expires_at < time()) {
                    $api_key_expired = true;
                }

                $api_key_value = $current_api_key->value;
            }
        }

        return $this->ci->view->render($response, 'pages/public_api/manage_api_key.html.twig', [
            'api_key_expired' => $api_key_expired,
            'api_key_value' => $api_key_value
        ]);
    }

    /**
     * Generate new random API key string
     *
     * For PHP 7, random_int is a PHP core function
     * For PHP 5.x, depends on https://github.com/paragonie/random_compat
     *
     * source:
     * http://stackoverflow.com/questions/4356289/php-random-string-generator/31107425#31107425
     */
    private function generateAPIKey() {
        $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $length = 32;

        $new_api_key = '';
        $max = mb_strlen($keyspace, '8bit') - 1;
        for ($i = 0; $i < $length; ++$i) {
            $new_api_key .= $keyspace[random_int(0, $max)];
        }

        return $new_api_key;
    }
}