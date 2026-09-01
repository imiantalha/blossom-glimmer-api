<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\RequestLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stevebauman\Location\Facades\Location;
use Symfony\Component\HttpFoundation\Response;

class LogRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);
        
        $requestIp = $request->ip();
        $staticIp = env('STATIC_IP', '39.37.154.26');
        $ip = $requestIp === '127.0.0.1' ? $staticIp : $requestIp;

        $location = Location::get($ip);

        $location = $location ?: (object) [
            'countryName' => null,
            'countryCode' => null,
            'regionName'  => null,
            'cityName'    => null,
            'latitude'    => null,
            'longitude'   => null,
            'timezone'    => null,
        ];

        Log::channel('requestlog')->info('Incomming Request', [
            'method'    => $request->method(),
            'url'       => $request->fullUrl(),
            'path'      => $request->path(),

            'ip'        => $ip,
            'country'   => $location->countryName,
            'country_code' => $location->countryCode,
            'region'    => $location->regionName,
            'city'      => $location->cityName,
            'latitude'  => $location->latitude,
            'longitude' => $location->longitude,
            'timezone'  => $location->timezone,

            'user_agent'=> $request->userAgent(),
            'headers'   => collect($request->headers->all())
                ->except(['authorization', 'cookie'])
                ->toArray(),
            'query'       => $request->query(),
            'payload' => $request->except([
                'password', 'password_confirmation', 'token',
            ]),
            'user_id'   => optional($request->user())->id,
            'timestamp' => now()->toDateTimeString(),
        ]);

        $log = RequestLog::create([
            'method'        => $request->method(),
            'url'           => $request->fullUrl(),
            'path'          => $request->path(),

            'ip'            => $ip,
            'country'       => $location?->countryName,
            'country_code'  => $location?->countryCode,
            'region'        => $location?->regionName,
            'city'          => $location?->cityName,
            'latitude'      => $location?->latitude,
            'longitude'     => $location?->longitude,
            'timezone'      => $location?->timezone,

            'user_agent'    => $request->userAgent(),

            'headers'       => collect($request->headers->all())
                                    ->except(['authorization', 'cookie'])
                                    ->toArray(),

            'query'         => $request->query(),

            'payload'       => $request->except([
                                    'password',
                                    'password_confirmation',
                                    'token',
                                ]),

            'user_id'       => optional($request->user())->id,

            'timestamp'     => now(),

            'response_status' => null,
            'response_time_ms' => null,
        ]);

        $log->update([
            'status'            => 'completed',
            'response_status'   => $response->getStatusCode(),
            'response_headers'  => $response->headers->all(),
            'response_time_ms'  => round((microtime(true) - $start) * 1000, 2),
        ]);

        return $response;
    }
}
