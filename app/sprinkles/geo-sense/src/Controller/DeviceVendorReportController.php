<?php

namespace UserFrosting\Sprinkle\GeoSense\Controller;

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

/**
 * DeviceVendorReportController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class DeviceVendorReportController extends SimpleController 
{
    /**
     * Render the GeoSense device vendor report page
     * No AUTH required
     * Request type: GET
     */
    public function pageDeviceVendorReport(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;
        
        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_device_vendor_report')) {
            throw new NotFoundException($request, $response);
        }

        return $this->ci->view->render($response, 'pages/geo-sense/device_vendor_report_venue.html.twig');
    }
}