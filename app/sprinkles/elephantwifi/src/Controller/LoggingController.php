<?php

namespace UserFrosting\Sprinkle\ElephantWifi\Controller;

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

use UserFrosting\Sprinkle\ElephantWifi\Database\Models\LogDownloadVisitorInfo;

/**
 * LoggingController Class
 *
 * @package ElephantWifi
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class LoggingController extends SimpleController 
{
    public function logDownloadPersonalVisitorInformation(Request $request, Response $response, $args)
    {
        $ms = $this->ci->alerts;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;
        
        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * Create the venue
         */
        $log = new LogDownloadVisitorInfo();
        $log->user_id = $currentUser->id;
        $log->ts = Carbon::now()->timestamp;
        $log->save();
    }
}