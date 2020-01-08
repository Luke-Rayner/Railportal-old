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

use UserFrosting\Sprinkle\IntelliSense\Database\Models\AlertNotification;

/**
 * DashboardController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class DashboardController extends SimpleController 
{
    public function pageLanding(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $alert_notifications = AlertNotification::with('users')->where('status', 1)->whereHas('venues', function($venue) {
            $venue->where('venue_id', $currentUser->primaryVenue->id);
        })->whereHas('users', function($user) {
            $user->where('user_id', $currentUser->id);
        })->get();

        foreach ($alert_notifications as $notification) {
            if ($notification->display == 1) {
                $users = [];
                foreach($notification->users as $user) {
                    if ($user->id != $currentUser->id) {
                        array_push($users, $user->id);
                    }
                }

                $notification->users()->sync($users);
            }
        }

        return $this->ci->view->render($response, 'pages/landing_page.html.twig', [
            'alert_notifications' => count($alert_notifications)
        ]);
    }
}