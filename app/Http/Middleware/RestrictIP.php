<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RestrictIP
{
    /**
     * List of allowed IPs.
     *
     * @var array
     */
    protected $allowedDomains = [
        'getyourticket.in',
        'www.getyourticket.in',
        'mercury-t2.phonepe.com',
        'testpay.easebuzz.in',
        'https://www.instamojo.com',
        'https://www.instamojo.com',
        'pay.easebuzz.in',
        'razorpay.com',
        'mercury-t2.phonepe.com',
        'api.razorpay.com',
        'ssgarba.com',
        'gyt.tieconvadodara.com',
        'ticket.tieconvadodara.com',
        'www.cashfree.com',
        'api.cashfree.com',
        'admin.getyourticket.in',
        'new.getyourticket.in',
        't.getyourticket.in',
        't1.getyourticket.in',
        'tbz.getyourticket.in',
        '192.168.0.113',
        '192.168.0.126',
        '10.87.158.93',
        '10.87.158.1'

    ];
    protected $allowedIps = [
        '111.125.194.83',
    ];
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $referer = $request->headers->get('referer');
        $refererDomain = $this->parseDomainFromReferer($referer);


        // Fix the syntax error and logic
        $ipAllowed = in_array($ip, $this->allowedIps);
        $domainAllowed = empty($refererDomain) || in_array($refererDomain, $this->allowedDomains);

        if (!$ipAllowed && !$domainAllowed) {
            // \Log::warning("Access denied - IP allowed: " . ($ipAllowed ? 'yes' : 'no') . ", Domain allowed: " . ($domainAllowed ? 'yes' : 'no'));
            return response()->json(['error' => 'Unauthorized Access'], 403);
        }

        return $next($request);
    }
    protected function parseDomainFromReferer(?string $referer): ?string
    {
        if (!$referer) return null;
        $parsed = parse_url($referer);
        return $parsed['host'] ?? null;
    }
}
