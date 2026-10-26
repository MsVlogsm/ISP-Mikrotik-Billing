<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Applies the same definition of Online Customers to dashboards and customer lists.
 * A customer is online only when its billing record and PPP secret are active and
 * its exact router/username pair appears in a successful live session read.
 */
class OnlineCustomerSessionFilter
{
    /**
     * Normalize usernames from the dashboard's cached, read-only router telemetry.
     *
     * @return array{users_by_router: array<string, array<string, bool>>, successful_routers: int}
     */
    public function fromTelemetry(array $telemetry): array
    {
        $usersByRouter = [];

        foreach (($telemetry['routers'] ?? []) as $routerName => $router) {
            if (($router['status'] ?? null) !== 'ok') {
                continue;
            }

            foreach (($router['usernames'] ?? []) as $username) {
                $username = mb_strtolower(trim((string) $username));
                if ($username !== '') {
                    $usersByRouter[(string) $routerName][$username] = true;
                }
            }
        }

        return [
            'users_by_router' => $usersByRouter,
            'successful_routers' => max(0, (int) ($telemetry['successful_routers'] ?? 0)),
        ];
    }

    /**
     * Add the shared live-session predicate to an existing CustomersInfo query.
     * The supplied users map can be built from fresh sessions or cached telemetry.
     */
    public function apply(Builder $query, array $usersByRouter, int $successfulRouters): Builder
    {
        $pairs = [];
        foreach ($usersByRouter as $routerName => $usernames) {
            foreach ($usernames as $username => $present) {
                // PHP converts numeric-string map keys to integers. Preserve those
                // usernames, while still supporting a simple list of username strings.
                if (is_int($username)) {
                    if (is_bool($present)) {
                        if (! $present) {
                            continue;
                        }
                        $username = (string) $username;
                    } else {
                        $username = $present;
                    }
                } elseif (! $present) {
                    continue;
                }

                $username = mb_strtolower(trim((string) $username));
                if ($username === '') {
                    continue;
                }

                $key = (string) $routerName.'|'.$username;
                $pairs[$key] = ['router' => (string) $routerName, 'username' => $username];
            }
        }

        if ($successfulRouters < 1 || $pairs === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('status', 'active')
            ->whereHas('pppUser', function ($ppp) use ($pairs): void {
                $ppp->where('status', 'active')
                    ->where(function ($match) use ($pairs): void {
                        foreach ($pairs as $session) {
                            $match->orWhere(function ($pair) use ($session): void {
                                $pair->where('router_name', $session['router'])
                                    ->whereRaw('LOWER(username) = ?', [$session['username']]);
                            });
                        }
                    });
            });
    }
}
