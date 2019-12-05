<?php

namespace UserFrosting\Sprinkle\IntelliSense\Controller;

use Carbon\Carbon;
use DateTimeZone;
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
use SendinBlue\Client\Configuration as SendinBlueConfig;
use SendinBlue\Client\Api\AccountApi as SendinBlueAccountApi;
use SendinBlue\Client\Api\ResellerApi as SendinBlueResellerApi;
use SendinBlue\Client\Api\ListsApi as SendinBlueListsApi;
use SendinBlue\Client\Api\AttributesApi as SendinBlueAttributesApi;

/**
 * TestController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class TestController extends SimpleController 
{
    public function sendinBlue(Request $request, Response $response, $args)
    {
        // Configure API key authorization: api-key
        $config = SendinBlueConfig::getDefaultConfiguration()->setApiKey('api-key', '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');

        $apiInstance = new SendinBlueResellerApi(
            // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
            // This is optional, `GuzzleHttp\Client` will be used as default.
            new \GuzzleHttp\Client(),
            $config
        );

        try {
            $child_accounts = $apiInstance->getResellerChilds();
            $child_account_info = $apiInstance->getChildInfo("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");
            // var_dump($child_accounts);
            var_dump($child_account_info);
        } catch (Exception $e) {
            var_dump('Exception when calling AccountApi->getAccount: ' . $e->getMessage());
        }
    }

    public function sendinBlueLists(Request $request, Response $response, $args)
    {
        // Configure API key authorization: api-key
        $config = SendinBlueConfig::getDefaultConfiguration()->setApiKey('api-key', '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');

        $apiInstance = new SendinBlueListsApi(
            // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
            // This is optional, `GuzzleHttp\Client` will be used as default.
            new \GuzzleHttp\Client(),
            $config
        );
        $limit = 10; // int | Number of documents per page
        $offset = 0; // int | Index of the first document of the page

        try {
            $result = $apiInstance->getLists($limit, $offset);
            var_dump($result);
        } catch (Exception $e) {
            echo 'Exception when calling ListsApi->getLists: ', $e->getMessage(), PHP_EOL;
        }
    }

    public function sendinBlueListContacts(Request $request, Response $response, $args)
    {
        // Configure API key authorization: api-key
        $config = SendinBlueConfig::getDefaultConfiguration()->setApiKey('api-key', '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');

        $apiInstance = new SendinBlueListsApi(
            // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
            // This is optional, `GuzzleHttp\Client` will be used as default.
            new \GuzzleHttp\Client(),
            $config
        );
        $listId = 3; // int | Id of the list
        $modifiedSince = new \DateTime("2013-10-20T19:20:30+01:00"); // \DateTime | Filter (urlencoded) the contacts modified after a given UTC date-time (YYYY-MM-DDTHH:mm:ss.SSSZ). Prefer to pass your timezone in date-time format for accurate result.
        $limit = 50; // int | Number of documents per page
        $offset = 0; // int | Index of the first document of the page

        try {
            $result = $apiInstance->getContactsFromList($listId, $modifiedSince, $limit, $offset);
            var_dump($result);
        } catch (Exception $e) {
            echo 'Exception when calling ListsApi->getLists: ', $e->getMessage(), PHP_EOL;
        }
    }

    public function sendinBlueCreateAttribite(Request $request, Response $response, $args)
    {
        // Create the custom attributes
        $config = SendinBlueConfig::getDefaultConfiguration()->setApiKey('api-key', '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');

        $apiAttributesInstance = new SendinBlueAttributesApi(
            new \GuzzleHttp\Client(),
            $config
        );
        $attributeCategory = "normal";
        $attributeName = "age";

        $createAttribute = new \SendinBlue\Client\Model\CreateAttribute([
            'type' => 'text'
        ]);

        try {
            $apiAttributesInstance->createAttribute($attributeCategory, $attributeName, $createAttribute);
        } catch (Exception $e) {
            return $response->withJson([], 400);
        }
    }
}