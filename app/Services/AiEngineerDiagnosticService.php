<?php

namespace App\Services;

use App\Models\CustomersInfo;
use App\Models\OltOnuCustomerMapping;
use App\Models\NetworkInventoryDevice;
use App\Models\RouterList;
use App\Models\NetworkEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\MikrotikController;
use Illuminate\Support\Facades\Schema;

class AiEngineerDiagnosticService
{
    public function __construct(private ?MikrotikController $mikrotik = null) {}

    public function overview(): array
    {
        $customerCount = CustomersInfo::count();
        $active = CustomersInfo::whereIn(DB::raw('LOWER(status)'), ['active', 'free'])->count();
        $pending = CustomersInfo::whereRaw("LOWER(status) = 'pending'")->count();
        $disabled = CustomersInfo::whereIn(DB::raw('LOWER(status)'), ['disable', 'disabled', 'inactive'])->count();
        $routerTotal = RouterList::count();
        $routerConnected = RouterList::where('action', 'connected')->count();
        $oltMapped = Schema::hasTable('olt_onu_customer_mappings') ? OltOnuCustomerMapping::count() : 0;
        $tickets = Schema::hasTable('support_tickets') ? DB::table('support_tickets')->count() : 0;

        $network = $this->networkSnapshot();
        $incidents = $this->incidentSummary($network);
        $insights = $this->networkInsights($network);
        return [
            'customers' => ['total'=>$customerCount,'active'=>$active,'pending'=>$pending,'disabled'=>$disabled],
            'routers' => ['total'=>$routerTotal,'connected'=>$routerConnected,'disconnected'=>max(0,$routerTotal-$routerConnected)],
            'olt_onu' => ['mapped'=>$oltMapped,'total'=>$network['onu_total'],'online'=>$network['onu_online'],'unmapped'=>$network['unmapped_onu']],
            'support' => ['tickets'=>$tickets],
            'network' => $network,
            'incidents' => $incidents,
            'insights' => $insights,
            'upstream_correlation' => $this->upstreamCorrelation(100),
            'matching_candidates' => $this->matchingCandidates(100),
            'network_trend' => $this->networkTrend(),
            'ai_provider' => (string) config('services.ai.provider', 'gemini'),
            'ai_configured' => (string) config('services.'.config('services.ai.provider', 'gemini').'.api_key') !== '',
            'openai_configured' => (string) config('services.openai.api_key') !== '',
            'read_only' => true,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function diagnoseCustomer(string $customerId): array
    {
        $customer = CustomersInfo::with(['pppUser', 'package', 'billing'])
            ->where('customer_unique_id', $customerId)->first();
        if (! $customer) return ['ok'=>false,'message'=>'গ্রাহক পাওয়া যায়নি.'];

        $ppp = $customer->pppUser;
        $billing = $customer->billing;
        $mapping = Schema::hasTable('olt_onu_customer_mappings')
            ? OltOnuCustomerMapping::where('customer_id', $customer->id)->latest('id')->first() : null;
        $router = $ppp?->router_name ? RouterList::where('router_name', $ppp->router_name)->first() : null;
        $livePpp = $this->readLivePppSession($router, $ppp?->username);
        $routerAge = $router?->last_checked_at ? max(0, now()->diffInSeconds($router->last_checked_at, false) * -1) : null;
        $olt = $mapping ? NetworkInventoryDevice::find($mapping->olt_device_id) : null;
        $oltHealth = $olt ? $olt->healthChecks()->latest('checked_at')->first() : null;
        $tickets = $this->customerTickets($customer->customer_unique_id);
        $path = [
            'customer' => ['status'=>$customer->status, 'state'=>$this->stateForCustomer($customer->status)],
            'pppoe' => $ppp ? [
                'username'=>$ppp->username,
                'status'=>$ppp->status,
                'state'=>$this->stateForPpp($ppp->status),
                'router'=>$ppp->router_name,
                'remote_ip'=>$ppp->ppp_remote_ip,
                'uptime'=>$ppp->uptime,
                'downtime'=>$ppp->downtime,
                'last_logged_out'=>$ppp->last_logged_out,
                'last_disconnect_reason'=>$ppp->last_disconnect_reason,
                'live_session'=>$livePpp,
            ] : ['state'=>'missing'],
            'router' => $router ? ['name'=>$router->router_name,'ip'=>$router->ip_address,'state'=>$router->action ?: 'অজানা','latency_ms'=>$router->last_latency_ms,'last_checked_at'=>$router->last_checked_at,'check_age_seconds'=>$routerAge,'check_freshness'=>$routerAge === null ? 'unknown' : ($routerAge <= 900 ? 'recent' : 'stale')] : ['state'=>$ppp?->router_name ? 'missing' : 'not_assigned'],
            'onu' => $mapping ? ['olt_device_id'=>$mapping->olt_device_id,'olt_name'=>$olt?->name,'olt_ip'=>$olt?->ip_address,'olt_state'=>$olt?->health_status ?: $olt?->status ?: 'অজানা','olt_latency_ms'=>$olt?->last_latency_ms,'olt_last_checked_at'=>$olt?->last_checked_at,'health_check'=>$oltHealth ? ['status'=>$oltHealth->status,'latency_ms'=>$oltHealth->latency_ms,'checked_at'=>$oltHealth->checked_at] : null,'pon'=>$mapping->pon_port,'onu_id'=>$mapping->onu_id,'serial'=>$mapping->onu_serial,'mac'=>$mapping->onu_mac,'state'=>$mapping->status ?: 'অজানা','rx_power'=>$mapping->rx_power,'tx_power'=>$mapping->tx_power,'opm_source'=>$mapping->opm_source,'opm_last_seen_at'=>$mapping->opm_last_seen_at?->toIso8601String(),'ip'=>$mapping->onu_ip,'last_seen_at'=>$mapping->last_seen_at,'last_seen_age_seconds'=>$mapping->last_seen_at ? max(0, now()->diffInSeconds($mapping->last_seen_at, false) * -1) : null] : ['state'=>'not_mapped'],
            'billing' => $billing ? ['state'=>$this->billingState($billing),'due'=>$this->billingDue($billing)] : ['state'=>'not_found'],
        ];
        $findings=[]; $causes=[]; $checks=[]; $severity='info';

        $status = strtolower((string)$customer->status);
        if (in_array($status,['disable','disabled','inactive'],true)) { $severity='critical'; $causes[]='বিলিং সিস্টেমে গ্রাহকের অ্যাকাউন্ট নিষ্ক্রিয়।'; $checks[]='Review customer status before restoring service.'; }
        elseif ($status==='pending') { $severity='warning'; $causes[]='গ্রাহকের সংযোগ এখনো অপেক্ষমাণ অবস্থায় আছে।'; $checks[]='Verify service activation workflow and router status.'; }

        if (! $ppp) { $severity='critical'; $causes[]='এই গ্রাহকের সঙ্গে কোনো PPP ব্যবহারকারী যুক্ত নেই।'; $checks[]='PPPoE সংযোগ নির্ধারণ পরীক্ষা করুন।'; }
        else {
            if (! $ppp->username) { $severity='critical'; $causes[]='PPPoE ব্যবহারকারীর নাম খালি।'; }
            if (strtolower((string)$ppp->status)!=='active') { $severity=$severity==='critical'?'critical':'warning'; $causes[]='যুক্ত PPP সংযোগটি সক্রিয় নয়।'; $checks[]='নির্ধারিত রাউটারে PPP গোপনীয় সংযোগের অবস্থা পরীক্ষা করুন।'; }
            if ($ppp->last_disconnect_reason) $findings[]='সর্বশেষ বিচ্ছিন্ন হওয়ার কারণ: '.$ppp->last_disconnect_reason;
            if ($ppp->last_logged_out) $findings[]='সর্বশেষ লগআউট: '.$ppp->last_logged_out;
            if ($ppp->username) $findings[]='PPPoE ব্যবহারকারী: '.$ppp->username;
            if ($ppp->ppp_remote_ip) $findings[]='PPP remote IP: '.$ppp->ppp_remote_ip;
            if ($ppp->uptime) $findings[]='PPP uptime: '.$ppp->uptime;
            if ($ppp->last_disconnect_reason) $findings[]='বিচ্ছিন্নতার প্রমাণ: '.$ppp->last_disconnect_reason;
            if (($livePpp['state'] ?? null) === 'online') {
                $findings[]='এই PPPoE ব্যবহারকারীর জন্য সক্রিয় MikroTik PPPoE সেশন পাওয়া গেছে।';
            } elseif (($livePpp['state'] ?? null) === 'offline') {
                $findings[]='এই ব্যবহারকারীর কোনো সক্রিয় MikroTik PPPoE সেশন পাওয়া যায়নি।';
                if (strtolower((string)$ppp->status) === 'active') {
                    $severity=$severity==='critical'?'critical':'warning';
                    $causes[]='PPP secret is active in billing data, but no live PPPoE session was found on the assigned router.';
                    $checks[]='রাউটার লগ, PPPoE অনুমোদন ত্রুটি এবং গ্রাহকের CPE-তে পৌঁছানো যাচ্ছে কি না পরীক্ষা করুন।';
                }
            }
        }
        if ($router) {
            $rs=strtolower((string)($router->action??''));
            $findings[]='Assigned router: '.$router->router_name.' ('.$router->ip_address.') · state '.($router->action?:'অজানা');
            if ($router->last_latency_ms !== null) $findings[]='Last recorded router latency: '.$router->last_latency_ms.' ms.';
            if ($router->last_checked_at) $findings[]='Router health last checked: '.$router->last_checked_at.' ('.(($routerAge ?? 0) > 900 ? 'পুরোনো, over 15 minutes old' : 'within 15 minutes').').';
            else $findings[]='Router health has no recorded last-চেক timestamp.';
            if (in_array($rs,['offline','down','disabled'],true)) { $severity='critical'; $causes[]='Assigned MikroTik/router is marked '.$rs.'.'; $checks[]='রাউটারে পৌঁছানো এবং মনিটরিং অবস্থা পরীক্ষা করুন।'; }
        } elseif ($ppp?->router_name) { $severity=$severity==='critical'?'critical':'warning'; $causes[]='Assigned router "'.$ppp->router_name.'" was not found in router inventory.'; $checks[]='Router List-এর সংযোগ পরীক্ষা করুন।'; }

        if ($mapping) {
            $findings[]='ONU mapping: PON '.($mapping->pon_port?:'অজানা').', ONU '.($mapping->onu_id?:'অজানা').', status '.($mapping->status?:'অজানা').'.';
            if ($mapping->rx_power !== null) $findings[]='ONU RX power: '.$mapping->rx_power.' dBm (interpret against this OLT/ONU vendor thresholds).';
            if ($mapping->tx_power !== null) $findings[]='ONU TX power: '.$mapping->tx_power.' dBm.';
            if ($mapping->last_seen_at) $findings[]='ONU last seen: '.$mapping->last_seen_at.'.';
            else $findings[]='ONU mapping has no last-seen timestamp.';
            if ($olt) {
                $findings[]='OLT '.$olt->name.' ('.$olt->ip_address.') health state: '.($olt->health_status ?: $olt->status ?: 'অজানা').'.';
                if ($oltHealth) $findings[]='Latest OLT health check: '.$oltHealth->status.($oltHealth->latency_ms !== null ? ' · '.$oltHealth->latency_ms.' ms' : '').' at '.$oltHealth->checked_at.'.';
                $os = strtolower((string)($olt->health_status ?: $olt->status ?: 'অজানা'));
                if (in_array($os, ['offline','down','critical','unreachable'], true)) {
                    $severity = $os === 'critical' ? 'critical' : ($severity === 'critical' ? 'critical' : 'warning');
                    $causes[] = 'Linked OLT inventory is marked '.$os.'.';
                    $checks[] = 'OLT-তে পৌঁছানো যাচ্ছে কি না এবং সাম্প্রতিক OLT/PON অ্যালার্ম পরীক্ষা করুন; কোনো provisioning পরিবর্তন করা হয়নি।';
                }
            }
            $ms=strtolower((string)$mapping->status);
            if (in_array($ms,['los','offline','down'],true)) { $severity=$ms==='los'?'critical':($severity==='critical'?'critical':'warning'); $causes[]='ONU is reported as '.strtoupper($ms).'.'; $checks[]='ফাইবার/ONU পাওয়ার এবং OLT PON অ্যালার্ম পরীক্ষা করুন।'; }
        } else $findings[]='No discovered OLT/ONU mapping is currently linked to this customer.';

        if ($billing) {
            $due=(float)($billing->total_due_amount ?? $billing->due_amount ?? 0);
            if ($due>0) { $findings[]='Outstanding billing: '.number_format($due,2); if ((bool)($billing->auto_disable??false)) { $severity=$severity==='critical'?'critical':'warning'; $causes[]='Billing has auto-disable enabled with outstanding dues.'; $checks[]='বকেয়া বিল এবং auto-disable নীতি পরীক্ষা করুন।'; } }
        }
        if ($tickets['count']>0) { $findings[]='Support tickets linked: '.$tickets['count'].($tickets['open']!==null?' · open: '.$tickets['open']:''); if (($tickets['open']??0)>0) $checks[]='সেবা অবস্থা পরিবর্তনের আগে সর্বশেষ খোলা support ticket পরীক্ষা করুন।'; }
        if (!$causes) { $causes[]='উপলভ্য বিলিং ও নেটওয়ার্ক তথ্য থেকে অফলাইনের নির্দিষ্ট কারণ নিশ্চিত করা যায়নি।'; $checks[]='লাইভ রাউটার সেশন, সর্বশেষ দেখা সময় এবং আপস্ট্রিম OLT/ONU অ্যালার্ম পরীক্ষা করুন।'; }

        // একই OLT/PON পথে একাধিক ONU আক্রান্ত হলে সম্ভাব্য যৌথ আপস্ট্রিম প্রভাব দেখানো হবে।
        $upstreamImpact = ['found'=>false,'confidence'=>'low','affected_onus'=>0,'affected_customers'=>0,'olt_device_id'=>null,'pon'=>null,'evidence'=>[],'read_only'=>true];
        if ($mapping) {
            $groups = $this->upstreamCorrelation(100)['groups'] ?? [];
            foreach ($groups as $group) {
                if ((string)($group['olt_device_id'] ?? '') === (string)$mapping->olt_device_id &&
                    strtolower(trim((string)($group['pon'] ?? ''))) === strtolower(trim((string)$mapping->pon_port ?? ''))) {
                    $upstreamImpact = [
                        'found'=>($group['affected_onus'] ?? 0) >= 2,
                        'confidence'=>$group['confidence'] ?? 'low',
                        'affected_onus'=>$group['affected_onus'] ?? 0,
                        'affected_customers'=>$group['affected_customers'] ?? 0,
                        'olt_device_id'=>$group['olt_device_id'] ?? null,
                        'pon'=>$group['pon'] ?? null,
                        'evidence'=>['একই OLT/PON পথে '.$group['affected_onus'].'টি সমস্যাগ্রস্ত ONU পাওয়া গেছে; এটি যৌথ আপস্ট্রিম সমস্যার সম্ভাবনা দেখায়, তবে মূল কারণ নিশ্চিত করে না।'],
                        'read_only'=>true,
                    ];
                    if ($upstreamImpact['found']) {
                        $severity = $severity === 'critical' ? 'critical' : 'warning';
                        $causes[] = 'একই OLT/PON পথে একাধিক ONU আক্রান্ত; সম্ভাব্য যৌথ আপস্ট্রিম সমস্যা।';
                        $checks[] = 'একই PON-এর অন্যান্য ONU এবং OLT/PON অ্যালার্ম পরীক্ষা করুন।';
                    }
                    break;
                }
            }
        }

        return ['ok'=>true,'customer'=>['id'=>$customer->customer_unique_id,'name'=>$customer->customer_name,'status'=>$customer->status,'mobile'=>$customer->mobile,'package'=>$customer->package?->package], 'severity'=>$severity,'summary'=>$this->summary($severity,$customer,$causes),'likely_causes'=>array_values(array_unique($causes)),'evidence'=>array_values(array_unique($findings)),'support'=>$tickets,'recommended_checks'=>array_values(array_unique($checks)),'service_path'=>$path,'upstream_impact'=>$upstreamImpact,'read_only'=>true,'generated_at'=>now()->toIso8601String()];
    }

    private function stateForCustomer(?string $status): string
    {
        $s=strtolower((string)$status);
        return in_array($s,['active','free'],true)?'active':(in_array($s,['disable','disabled','inactive'],true)?'disabled':($s==='pending'?'pending':'অজানা'));
    }

    private function stateForPpp(?string $status): string
    {
        $s=strtolower((string)$status);
        return $s==='active'?'active':($s===''?'অজানা':'inactive');
    }

    private function billingDue($billing): float
    {
        return (float)($billing->total_due_amount ?? $billing->due_amount ?? 0);
    }

    private function billingState($billing): string
    {
        $due=$this->billingDue($billing);
        return $due>0?'due':'clear';
    }

    /**
     * Correlate affected customers/ONUs by shared upstream path.
     * Read-only: this only reports evidence-based groups and never changes mappings.
     */
    public function upstreamCorrelation(int $limit = 100): array
    {
        $rows = [];
        if (!Schema::hasTable('olt_onu_customer_mappings')) {
            return ['groups'=>[], 'affected_customers'=>0, 'read_only'=>true];
        }

        $mappings = OltOnuCustomerMapping::query()->latest('id')->limit($limit)->get();
        foreach ($mappings as $m) {
            $status = strtolower((string) ($m->status ?? 'অজানা'));
            $affected = in_array($status, ['offline','down','los','critical'], true);
            if (!$affected) continue;
            $key = implode('|', [
                (string) ($m->olt_device_id ?? 'অজানা'),
                strtolower(trim((string) ($m->pon_port ?? 'অজানা'))),
            ]);
            $rows[$key] ??= [
                'olt_device_id' => $m->olt_device_id,
                'pon' => $m->pon_port,
                'affected_customers' => 0,
                'affected_onus' => 0,
                'statuses' => [],
                'customer_ids' => [],
                'evidence' => [],
            ];
            $rows[$key]['affected_onus']++;
            $rows[$key]['statuses'][] = strtoupper($status);
            if ($m->customer_id) {
                $rows[$key]['affected_customers']++;
                $rows[$key]['customer_ids'][] = (string) $m->customer_id;
            }
        }

        $groups = array_values(array_map(function ($g) {
            $g['statuses'] = array_values(array_unique($g['statuses']));
            $g['customer_ids'] = array_values(array_unique($g['customer_ids']));
            $g['confidence'] = $g['affected_onus'] >= 3 ? 'high' : ($g['affected_onus'] >= 2 ? 'medium' : 'low');
            $g['evidence'][] = $g['affected_onus'].' affected ONU(s) share the same OLT/PON path.';
            $g['read_only'] = true;
            return $g;
        }, $rows));

        usort($groups, fn($a,$b) => $b['affected_onus'] <=> $a['affected_onus']);
        return [
            'groups' => array_slice($groups, 0, 25),
            'affected_customers' => count(array_unique(array_merge([], ...array_map(fn($g) => $g['customer_ids'] ?? [], $groups)))),
            'read_only' => true,
        ];
    }

    /**
     * Read-only customer/ONU candidate matching using normalized identifiers.
     */
    public function matchingCandidates(int $limit = 100): array
    {
        if (!Schema::hasTable('olt_onu_customer_mappings')) return ['matches'=>[], 'read_only'=>true];
        $customers = CustomersInfo::with('pppUser')->limit($limit)->get();
        $maps = OltOnuCustomerMapping::query()->whereNull('customer_id')->latest('id')->limit($limit)->get();
        $matches = [];
        foreach ($maps as $map) {
            $candidates = [];
            $mapMac = $this->normalizeMac($map->onu_mac);
            $mapIp = $this->normalizeIdentifier($map->onu_ip);
            $mapSerial = $this->normalizeIdentifier($map->onu_serial);
            foreach ($customers as $customer) {
                $score = 0; $reasons = [];
                $ppp = $customer->pppUser;
                $pppMacs = $ppp ? [$ppp->caller_id ?? null, $ppp->last_caller_id ?? null] : [];
                foreach ([['mac',$mapMac,$pppMacs,60],['ip',$mapIp,[$ppp->ppp_remote_ip ?? null],25],['serial',$mapSerial,[],30]] as $item) {
                    [$field,$a,$b,$weight] = $item;
                    $normalizer = $field === 'mac'
                        ? fn($value) => $this->normalizeMac($value)
                        : fn($value) => $this->normalizeIdentifier($value);
                    $matched = $a !== '' && collect((array) $b)->contains(fn($candidate) => $normalizer($candidate) !== '' && $a === $normalizer($candidate));
                    if ($matched) { $score += $weight; $reasons[] = strtoupper($field).' exact match'; }
                }
                if ($ppp && $mapIp !== '' && $this->normalizeIdentifier($ppp->ppp_remote_ip ?? null) === $mapIp) { $score += 20; $reasons[] = 'PPPoE remote IP match'; }
                if ($score > 0) $candidates[] = ['customer_id'=>$customer->customer_unique_id,'customer_name'=>$customer->customer_name,'customer_db_id'=>$customer->id,'score'=>min(100,$score),'reasons'=>$reasons];
            }

            // Only expose a suggestion when the highest-scoring evidence is unique.
            usort($candidates, fn($a,$b) => $b['score'] <=> $a['score']);
            $best = $candidates[0] ?? null;
            $secondScore = $candidates[1]['score'] ?? null;
            $sameBestCount = $best ? count(array_filter($candidates, fn($c) => $c['score'] === $best['score'])) : 0;
            if ($best && $sameBestCount === 1) {
                $matches[] = ['mapping_id'=>$map->id,'olt_device_id'=>$map->olt_device_id,'pon'=>$map->pon_port,'onu_id'=>$map->onu_id,'onu_mac'=>$map->onu_mac,'candidate'=>$best,'read_only'=>true];
            }
        }
        usort($matches, fn($a,$b)=>$b['candidate']['score'] <=> $a['candidate']['score']);
        return ['matches'=>array_slice($matches,0,50),'read_only'=>true];
    }


    /**
     * Save only a unique, exact ONU MAC ↔ PPPoE caller-ID match.
     * This writes the mapping record only; no OLT provisioning command is sent.
     */
    public function mapExactCandidate(int $mappingId, int $customerDbId, ?int $actorId = null): array
    {
        if (! Schema::hasTable('olt_onu_customer_mappings')) {
            return ['ok' => false, 'message' => 'ONU mapping storage is not available.'];
        }

        return DB::transaction(function () use ($mappingId, $customerDbId, $actorId): array {
            $mapping = OltOnuCustomerMapping::query()->lockForUpdate()->find($mappingId);
            $customer = CustomersInfo::query()->with('pppUser')->lockForUpdate()->find($customerDbId);

            if (! $mapping || ! $customer) {
                return ['ok' => false, 'message' => 'ONU or customer record was not found. Refresh the AI Engineer page and try again.'];
            }

            if ($mapping->customer_id !== null) {
                return ['ok' => false, 'message' => 'This ONU is already mapped. No existing mapping was changed.'];
            }

            if (! $customer->ppp_user_id || ! $customer->pppUser) {
                return ['ok' => false, 'message' => 'The selected customer has no linked PPPoE source record.'];
            }

            $onuMac = $this->normalizeMac($mapping->onu_mac);
            if ($onuMac === '') {
                return ['ok' => false, 'message' => 'A valid ONU MAC address is required for exact-match mapping.'];
            }

            $exactMatches = CustomersInfo::query()
                ->with('pppUser')
                ->whereNotNull('ppp_user_id')
                ->get(['id', 'customer_unique_id', 'customer_name', 'ppp_user_id'])
                ->filter(function ($candidate) use ($onuMac): bool {
                    $ppp = $candidate->pppUser;
                    if (! $ppp) {
                        return false;
                    }

                    return $onuMac === $this->normalizeMac($ppp->caller_id)
                        || $onuMac === $this->normalizeMac($ppp->last_caller_id);
                })
                ->values();

            if ($exactMatches->count() !== 1) {
                return ['ok' => false, 'message' => 'The ONU MAC does not identify exactly one PPPoE customer. No mapping was saved.'];
            }

            $exactCustomer = $exactMatches->first();
            if ((int) $exactCustomer->id !== $customerDbId) {
                return ['ok' => false, 'message' => 'The suggested customer no longer matches the exact ONU MAC evidence. Refresh suggestions before mapping.'];
            }

            $pppId = (int) $customer->ppp_user_id;
            if ($mapping->ppp_user_id !== null && (int) $mapping->ppp_user_id !== $pppId) {
                return ['ok' => false, 'message' => 'This ONU record already contains a different PPPoE assignment. Manual review is required.'];
            }

            $reuseExists = OltOnuCustomerMapping::query()
                ->where('olt_device_id', $mapping->olt_device_id)
                ->where('id', '<>', $mapping->id)
                ->where(function ($query) use ($customerDbId, $pppId): void {
                    $query->where('customer_id', $customerDbId)
                        ->orWhere('ppp_user_id', $pppId);
                })
                ->exists();

            if ($reuseExists) {
                return ['ok' => false, 'message' => 'This customer or PPPoE record is already assigned to another ONU on the same OLT. Manual review is required.'];
            }

            $mapping->forceFill([
                'customer_id' => $customerDbId,
                'ppp_user_id' => $pppId,
            ])->save();

            \Log::info('AI Engineer saved exact ONU/PPPoE MAC customer mapping', [
                'actor_user_id' => $actorId,
                'mapping_id' => $mapping->id,
                'olt_device_id' => $mapping->olt_device_id,
                'customer_id' => $customerDbId,
                'ppp_user_id' => $pppId,
                'onu_mac' => $mapping->onu_mac,
                'evidence' => 'normalized exact ONU MAC equals PPPoE caller_id or last_caller_id',
            ]);

            return [
                'ok' => true,
                'message' => 'Exact ONU MAC and PPPoE caller MAC matched. Customer ↔ ONU mapping was saved.',
                'mapping_id' => $mapping->id,
            ];
        });
    }

    private function normalizeIdentifier($value): string
    {
        return strtolower(preg_replace('/[^a-z0-9:.@_-]/i', '', trim((string) $value)));
    }

    private function normalizeMac($value): string
    {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/[^a-f0-9:.\- ]/i', $raw)) {
            return '';
        }

        $mac = strtolower((string) preg_replace('/[^a-f0-9]/i', '', $raw));

        return preg_match('/^[a-f0-9]{12}$/', $mac) ? $mac : '';
    }

    public function chat(string $question, ?string $customerId=null, array $history=[]): array
    {
        $diagnosis=$customerId?$this->diagnoseCustomer($customerId):null;
        $provider=strtolower((string)config('services.ai.provider','gemini'));
        if(!in_array($provider,['gemini','openai'],true))$provider='gemini';
        $apiKey=(string)config("services.{$provider}.api_key");
        $contextData=$diagnosis&&($diagnosis['ok']??false)?$diagnosis:$this->overview();
        if(!$diagnosis){
            // overview() already includes network and incident summaries; avoid repeating লাইভ OLT reads.
            $contextData['unmapped_onus']=$this->unmappedOnus(12);
            $contextData['optical_power']=$this->opticalPowerList(50);
        }
        $context=json_encode($contextData,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        // Local diagnostics remain available if the selected provider has no key.
        if ($apiKey==='') {
            return ['ok'=>true,'configured'=>true,'provider'=>'local','message'=>$this->localChatResponse($question,$diagnosis),'read_only'=>true];
        }
        $safeHistory=array_slice(array_map(fn($m)=>['role'=>in_array($m['role']??'', ['user','assistant'],true)?$m['role']:'user','content'=>mb_substr((string)($m['content']??''),0,4000)],$history),-8);
        $systemPrompt='আপনি একজন ISP AI Engineer। শুধুমাত্র পড়ার/বিশ্লেষণের কাজ করবেন। কোনো কিছু পরিবর্তন, প্রভিশন, রিবুট, সক্রিয়, নিষ্ক্রিয়, মুছে ফেলা বা কনফিগার করা হয়েছে বলে কখনো দাবি করবেন না। শুধু সরবরাহ করা তথ্য ব্যবহার করুন। প্রমাণ ও সম্ভাব্য কারণ আলাদা করে বলুন। লাইভ স্ট্যাটাস বানিয়ে বলবেন না। ব্যবহারকারী যে ভাষায় প্রশ্ন করবেন, সেই ভাষাতেই উত্তর দিন; বাংলা প্রশ্ন হলে সম্পূর্ণ উত্তর বাংলায় দিন।';
        if($provider==='gemini') {
            $messages=[['role'=>'system','content'=>$systemPrompt],['role'=>'user','content'=>'Diagnostic context: '.$context]];
            foreach($safeHistory as $m)$messages[]=$m;
            $messages[]=['role'=>'user','content'=>mb_substr($question,0,4000)];
            $baseUrl=rtrim((string)config('services.gemini.base_url','https://generativelanguage.googleapis.com/v1beta/openai'),'/');
            $payload=['model'=>config('services.gemini.model','gemini-3.8-flash'),'messages'=>$messages,'reasoning_effort'=>'low'];
            try {
                $response=Http::withToken($apiKey)->acceptJson()->timeout(30)->retry(2,500,fn($exception,$request)=>true,false)->post($baseUrl.'/chat/completions',$payload);
            } catch (\Throwable $e) {
                \Log::error('AI Engineer Gemini request exception',['error'=>$e->getMessage()]);
                return ['ok'=>true,'configured'=>true,'provider'=>'local','message'=>$this->localChatResponse($question,$diagnosis),'read_only'=>true,'fallback'=>true];
            }
            if(!$response->successful()){
                \Log::error('AI Engineer Gemini request failed',['status'=>$response->status(),'body'=>mb_substr($response->body(),0,1000)]);
                return ['ok'=>true,'configured'=>true,'provider'=>'local','message'=>$this->localChatResponse($question,$diagnosis),'read_only'=>true,'fallback'=>true];
            }
            $body=$response->json();
            $text=$body['choices'][0]['message']['content']??'';
            if(is_array($text))$text=implode("\n",array_map(fn($part)=>(string)($part['text']??''),$text));
            return ['ok'=>true,'configured'=>true,'provider'=>'gemini','message'=>trim((string)$text),'read_only'=>true];
        }

        $input=[['role'=>'developer','content'=>$systemPrompt],['role'=>'user','content'=>'Diagnostic context: '.$context]];
        foreach($safeHistory as $m)$input[]=$m;
        $input[]=['role'=>'user','content'=>mb_substr($question,0,4000)];
        try {
            $response=Http::withToken($apiKey)->acceptJson()->timeout(30)->post('https://api.openai.com/v1/responses',['model'=>config('services.openai.model'),'store'=>false,'input'=>$input]);
        } catch (\Throwable $e) {
            \Log::error('AI Engineer OpenAI request exception',['error'=>$e->getMessage()]);
            return ['ok'=>true,'configured'=>true,'provider'=>'local','message'=>$this->localChatResponse($question,$diagnosis),'read_only'=>true,'fallback'=>true];
        }
        if(!$response->successful()){
            \Log::error('AI Engineer OpenAI request failed',['status'=>$response->status(),'body'=>mb_substr($response->body(),0,2000)]);
            return ['ok'=>false,'configured'=>true,'provider'=>'openai','message'=>'OpenAI অনুরোধ ব্যর্থ (HTTP '.$response->status().'). প্রোভাইডার ত্রুটির জন্য Laravel লগ দেখুন।'];
        }
        $body=$response->json(); $text=$body['output_text']??'';
        if($text==='')foreach(($body['output']??[]) as $item)foreach(($item['content']??[]) as $content)if(($content['type']??'')==='output_text')$text.=$content['text']??'';
        return ['ok'=>true,'configured'=>true,'provider'=>'openai','message'=>trim($text),'read_only'=>true];
    }

    private function localChatResponse(string $question, ?array $diagnosis): string
    {
        $q=mb_strtolower(trim($question));
        if (!$diagnosis || !($diagnosis['ok']??false)) {
            if (str_contains($q,'optical') || str_contains($q,'power') || str_contains($q,'rx') || str_contains($q,'tx') || str_contains($q,'অপ্টিকাল') || str_contains($q,'পাওয়ার')) {
                $rows=$this->opticalPowerList(50);
                if (!$rows) return 'No ONU optical-power readings are available in the current mapping data.';
                $parts=array_map(function($r){
                    $measuredAt=!empty($r['opm_measured_at'])
                        ? 'measurement '.\Illuminate\Support\Carbon::parse($r['opm_measured_at'])->format('d M Y H:i:s')
                        : 'measurement time unknown; saved reading is not confirmed live';
                    $rx=is_numeric($r['rx']??null)?$r['rx'].' dBm':'—';
                    $tx=is_numeric($r['tx']??null)?$r['tx'].' dBm':'—';
                    return ($r['olt']?:'OLT').' | ONU '.($r['onu_id']??'—').' | MAC '.($r['mac']??'—').' | PON '.($r['pon']??'—').' | RX '.$rx.' | TX '.$tx.' | '.$measuredAt.' | '.($r['status']??'অজানা');
                },$rows);
                return 'সর্বশেষ সংরক্ষিত ONU optical power তালিকা (পৃথক measurement timestamp না থাকলে live reading হিসেবে নিশ্চিত নয়):'."\n".implode("\n",$parts);
            }
            if (str_contains($q,'billing') || str_contains($q,'bill') || str_contains($q,'due')) return 'For billing, চেক the customer\'s outstanding amount, auto-disable setting, account status, and any open support ticket. Select a customer to get customer-specific evidence.';
            if (str_contains($q,'device') || str_contains($q,'router') || str_contains($q,'mikrotik')) return 'For a device issue, চেক the assigned router inventory state, last-seen/session information, and any linked ONU mapping. Select a customer for a service-path diagnosis.';
            return 'স্থানীয় শুধু-পাঠযোগ্য ডায়াগনস্টিক সক্রিয়। গ্রাহক নির্বাচন করে সেবা পথ, বিলিং, PPPoE, রাউটার, ONU বা বিভ্রাট সম্পর্কে প্রশ্ন করুন।';
        }

        $c=$diagnosis['customer']??[];
        $causes=$diagnosis['likely_causes']??[];
        $evidence=$diagnosis['evidence']??[];
        $checks=$diagnosis['recommended_checks']??[];
        $billingQuestion=str_contains($q,'billing')||str_contains($q,'bill')||str_contains($q,'due')||str_contains($q,'payment');
        $pathQuestion=str_contains($q,'service path')||str_contains($q,'offline')||str_contains($q,'outage')||str_contains($q,'connection')||str_contains($q,'internet');
        $pppQuestion=str_contains($q,'pppoe')||str_contains($q,'ppp');
        $routerQuestion=str_contains($q,'router')||str_contains($q,'mikrotik');
        $onuQuestion=str_contains($q,'onu')||str_contains($q,'olt')||str_contains($q,'fiber');

        if ($billingQuestion) {
            $items=array_values(array_filter($evidence,fn($x)=>str_contains(mb_strtolower($x),'billing')||str_contains(mb_strtolower($x),'due')||str_contains(mb_strtolower($x),'ticket')));
            $answer=$items ? implode(' ',$items) : 'No outstanding billing amount is recorded in the available customer data.';
            if ($diagnosis['severity']==='critical' || $diagnosis['severity']==='warning') $answer.=' Also review: '.implode(' ',$checks);
            return 'Billing চেক for '.($c['id']??'customer').': '.$answer;
        }

        if ($pppQuestion) $focus=array_values(array_filter($causes,fn($x)=>str_contains(mb_strtolower($x),'ppp')));
        elseif ($routerQuestion) $focus=array_values(array_filter($causes,fn($x)=>str_contains(mb_strtolower($x),'router')));
        elseif ($onuQuestion) $focus=array_values(array_filter($causes,fn($x)=>str_contains(mb_strtolower($x),'onu')));
        else $focus=[];

        if ($pathQuestion || $pppQuestion || $routerQuestion || $onuQuestion) {
            $answer=$focus ?: $causes;
            $text=implode(' ',$answer);
            if ($text==='') $text='No specific fault is recorded in the available data.';
            return 'সেবা-পথ বিশ্লেষণ: '.($c['id']??'customer').': '.$text.' প্রস্তাবিত যাচাই: '.implode(' ',$checks);
        }

        return 'For '.($c['id']??'this customer').', বর্তমান গুরুত্বের স্তর '.($diagnosis['severity']??'অজানা').'. '.($diagnosis['summary']??'কোনো সারসংক্ষেপ পাওয়া যায়নি।').' প্রমাণ: '.implode(' ',$evidence). ' প্রস্তাবিত যাচাই: '.implode(' ',$checks);
    }

    public function networkSnapshot(): array
    {
        $deviceTable = (new NetworkInventoryDevice())->getTable();
        $deviceColumns = ['id'];
        if (Schema::hasColumn($deviceTable, 'type')) $deviceColumns[] = 'type';
        foreach (['status', 'health_status', 'onu_total', 'onu_online'] as $optionalColumn) {
            if (Schema::hasColumn($deviceTable, $optionalColumn)) $deviceColumns[] = $optionalColumn;
        }
        $devices = in_array('type', $deviceColumns, true)
            ? NetworkInventoryDevice::whereNotNull('type')->get($deviceColumns)
            : collect();
        $olts=$devices->filter(fn($d)=>in_array(strtolower((string)$d->type),['olt','epon','gpon'],true));
        $oltOnline=0; $oltOffline=0; $onuTotal=0; $onuOnline=0; $liveRead=0;
        foreach($olts as $olt){
            try {
                $live=app(\App\Services\Olt\OltReadOnlyAdapterManager::class)->read($olt);
                if(($live['ok']??false)===true){
                    $liveRead++; $oltOnline++; $onus=$live['onus']??[];
                    $onuTotal+=count($onus);
                    $onuOnline+=count(array_filter($onus,fn($o)=>strtolower((string)($o['status']??''))==='online'));
                    continue;
                }
            } catch (\Throwable $e) { \Log::debug('AI Engineer OLT লাইভ summary failed',['device_id'=>$olt->id,'error'=>$e->getMessage()]); }
            $state=strtolower((string)($olt->health_status?:$olt->status));
            if(in_array($state,['online','up','connected','healthy'],true)) $oltOnline++;
            elseif(in_array($state,['offline','down','unreachable','critical'],true)) $oltOffline++;
            $onuTotal+=(int)($olt->onu_total??0); $onuOnline+=(int)($olt->onu_online??0);
        }
        $mappingTotal=Schema::hasTable('olt_onu_customer_mappings')?OltOnuCustomerMapping::count():0;
        $mapped=Schema::hasTable('olt_onu_customer_mappings')?OltOnuCustomerMapping::whereNotNull('customer_id')->count():0;
        $routers = Schema::hasColumn((new RouterList())->getTable(), 'action') ? RouterList::get(['action']) : collect();
        return ['device_total'=>$devices->count(),'olt_total'=>$olts->count(),'olt_online'=>$oltOnline,'olt_offline'=>$oltOffline,
            'router_total'=>$routers->count(),'router_online'=>$routers->where('action','connected')->count(),
            'onu_total'=>$onuTotal,'onu_online'=>$onuOnline,'onu_offline'=>max(0,$onuTotal-$onuOnline),
            'mapping_total'=>$mappingTotal,'mapped_customers'=>$mapped,'unmapped_onu'=>max(0,$mappingTotal-$mapped),
            'affected_customers'=>Schema::hasTable('olt_onu_customer_mappings')?OltOnuCustomerMapping::whereNotNull('customer_id')->whereIn(DB::raw('LOWER(status)'),['offline','down','los'])->distinct('customer_id')->count('customer_id'):0,
            'live_olt_reads'=>$liveRead,'generated_at'=>now()->toIso8601String()];
    }

    public function networkInsights(array $network): array
    {
        $issues = [];
        $warnings = [];
        $optical = $this->opticalPowerList(100);
        foreach ($optical as $row) {
            $rx = is_numeric($row['rx'] ?? null) ? (float)$row['rx'] : null;
            $status = strtolower((string)($row['status'] ?? ''));
            if ($status === 'los' || $status === 'offline' || ($rx !== null && $rx <= -27)) {
                $issues[] = ['type'=>'optical','severity'=>'critical','olt'=>$row['olt'],'pon'=>$row['pon'],'onu_id'=>$row['onu_id'],'rx'=>$rx,'status'=>$row['status']];
            } elseif ($rx !== null && $rx <= -24) {
                $warnings[] = ['type'=>'optical','severity'=>'warning','olt'=>$row['olt'],'pon'=>$row['pon'],'onu_id'=>$row['onu_id'],'rx'=>$rx,'status'=>$row['status']];
            }
        }
        $routerStale = 0;
        if (Schema::hasColumn((new RouterList())->getTable(), 'last_checked_at')) {
            $routerStale = RouterList::where('action','connected')->where(function($q){$q->whereNull('last_checked_at')->orWhere('last_checked_at','<',now()->subMinutes(15));})->count();
        }
        $health = 100;
        $health -= min(35, (int)$network['olt_offline'] * 15);
        $health -= min(25, (int)$network['router_total'] > 0 ? (int)round(((int)$network['router_total']-(int)$network['router_online']) / max(1,(int)$network['router_total']) * 25) : 0);
        $health -= min(20, count($issues) * 3);
        $health -= min(10, (int)$routerStale);
        $health = max(0, min(100, $health));
        return [
            'health_score'=>$health,
            'health_band'=>$health >= 90 ? 'healthy' : ($health >= 70 ? 'attention' : 'degraded'),
            'optical_critical'=>count($issues),
            'optical_warning'=>count($warnings),
            'router_stale'=>$routerStale,
            'top_findings'=>array_slice(array_merge($issues,$warnings),0,12),
            'read_only'=>true,
        ];
    }

    public function incidentSummary(?array $network = null): array
    {
        $e=['total'=>0,'open'=>0,'critical'=>0,'warning'=>0,'latest'=>[]];
        if(Schema::hasTable('network_events')){
            $q=DB::table('network_events'); $open=(clone $q)->whereNotIn(DB::raw('LOWER(status)'),['resolved','closed']);
            $e['total']=(clone $q)->count(); $e['open']=(clone $open)->count();
            $e['critical']=(clone $open)->whereRaw("LOWER(severity)='critical'")->count();
            $e['warning']=(clone $open)->whereRaw("LOWER(severity)='warning'")->count();
            $e['latest']=(clone $open)->latest('id')->limit(8)->get(['id','device_id','severity','title','message','status','occurrences','last_seen_at'])->map(fn($x)=>(array)$x)->values()->all();
        }
        $n = $network ?? $this->networkSnapshot(); $e['affected_customers']=$n['affected_customers'];
        $e['status']=$e['critical']>0?'critical':($e['open']>0||$n['olt_offline']>0?'attention':'healthy'); return $e;
    }

    public function unmappedOnus(int $limit=20): array
    {
        if(!Schema::hasTable('olt_onu_customer_mappings')) return [];
        return OltOnuCustomerMapping::with('olt')->whereNull('customer_id')->latest('id')->limit($limit)->get()->map(fn($m)=>[
            'id'=>$m->id,'olt'=>$m->olt?->name,'onu_id'=>$m->onu_id,'mac'=>$m->onu_mac,'serial'=>$m->onu_serial,'pon'=>$m->pon_port,
            'status'=>$m->status,'rx'=>$m->rx_power,'tx'=>$m->tx_power,'opm_source'=>$m->opm_source,'opm_measured_at'=>$m->opm_last_seen_at?->toIso8601String(),'last_seen'=>$m->last_seen_at,'reason'=>$m->notes?:'No exact customer/PPPoE match found.'
        ])->values()->all();
    }

    public function opticalPowerList(int $limit=50): array
    {
        if(!Schema::hasTable('olt_onu_customer_mappings')) return [];
        return OltOnuCustomerMapping::with('olt')->latest('id')->limit($limit)->get()->map(fn($m)=>[
            'id'=>$m->id,'olt'=>$m->olt?->name,'onu_id'=>$m->onu_id,'mac'=>$m->onu_mac,'serial'=>$m->onu_serial,
            'pon'=>$m->pon_port,'status'=>$m->status,'rx'=>$m->rx_power,'tx'=>$m->tx_power,'opm_source'=>$m->opm_source,'opm_measured_at'=>$m->opm_last_seen_at?->toIso8601String(),'last_seen'=>$m->last_seen_at,
        ])->values()->all();
    }

    public function networkTrend(): array
    {
        $empty=['hours'=>[],'total_events'=>0,'critical'=>0,'warning'=>0,'top_titles'=>[],'read_only'=>true];
        if (!Schema::hasTable('network_events')) return $empty;
        $table=(new NetworkEvent())->getTable();
        if (!Schema::hasColumn($table,'created_at')) return $empty;
        $hasSeverity=Schema::hasColumn($table,'severity');
        $hasTitle=Schema::hasColumn($table,'title');
        $since=now()->subHours(24);
        $q=DB::table($table)->where('created_at','>=',$since);
        $hours=[];
        for($i=23;$i>=0;$i--){
            $start=now()->subHours($i+1);
            $end=now()->subHours($i);
            $hours[]=['hour'=>$end->format('H:00'),'events'=>(clone $q)->where('created_at','>=',$start)->where('created_at','<',$end)->count()];
        }
        $top=[];
        if($hasTitle) $top=(clone $q)->select('title',DB::raw('COUNT(*) as total'))->groupBy('title')->orderByDesc('total')->limit(5)->get()->map(fn($x)=>['title'=>$x->title,'count'=>(int)$x->total])->values()->all();
        return ['hours'=>$hours,'total_events'=>(clone $q)->count(),'critical'=>$hasSeverity?(clone $q)->whereRaw("LOWER(severity)='critical'")->count():0,'warning'=>$hasSeverity?(clone $q)->whereRaw("LOWER(severity)='warning'")->count():0,'top_titles'=>$top,'read_only'=>true,'generated_at'=>now()->toIso8601String()];
    }

    public function dailySummary(): array
    {
        $o=$this->overview(); return ['status'=>$o['incidents']['status'],'network'=>$o['network'],'incidents'=>$o['incidents'],'customers'=>$o['customers'],'routers'=>$o['routers'],'olt_onu'=>$o['olt_onu'],'trend'=>$o['network_trend']??$this->networkTrend(),'generated_at'=>now()->toIso8601String(),'read_only'=>true];
    }

    public function searchCustomers(string $q): array
    {
        $q=trim($q); if($q==='')return [];
        $customers = CustomersInfo::query()
            ->with('pppUser')
            ->search($q)
            ->limit(12)
            ->get(['id', 'customer_unique_id', 'customer_name', 'mobile', 'status', 'ppp_user_id']);

        return $customers->map(fn ($customer) => [
            'id' => $customer->id,
            'customer_unique_id' => $customer->customer_unique_id,
            'customer_name' => $customer->customer_name,
            'mobile' => $customer->mobile,
            'status' => $customer->status,
            'ppp_username' => $customer->pppUser?->username,
        ])->values()->all();
    }


    private function readLivePppSession(?RouterList $router, ?string $username): array
    {
        if (! $router || ! $username || strtolower((string)$router->action) !== 'connected') {
            return ['state'=>'not_checked','reason'=>'router_not_connected'];
        }
        try {
            $controller = $this->mikrotik ?: app(MikrotikController::class);
            $rows = $controller->singleRead(
                $router->router_name,
                '/ppp/active/print',
                '/ppp active print without-paging terse',
                [],
                false,
                true
            );
            foreach ($rows as $row) {
                $name = $row['name'] ?? $row['user'] ?? $row['username'] ?? null;
                if ((string)$name === (string)$username) {
                    return [
                        'state'=>'online',
                        'address'=>$row['address'] ?? $row['remote-address'] ?? null,
                        'caller_id'=>$row['caller-id'] ?? $row['caller_id'] ?? null,
                        'uptime'=>$row['uptime'] ?? null,
                        'service'=>$row['service'] ?? null,
                    ];
                }
            }
            return ['state'=>'offline','checked_at'=>now()->toIso8601String()];
        } catch (\Throwable $e) {
            \Log::debug('AI Engineer লাইভ PPP read failed', ['router'=>$router->router_name,'error'=>$e->getMessage()]);
            return ['state'=>'not_checked','reason'=>'router_read_failed'];
        }
    }

    private function customerTickets(string $id): array
    {
        if(!Schema::hasTable('support_tickets')||!Schema::hasColumn('support_tickets','customer_unique_id'))return ['count'=>0,'open'=>null,'latest'=>null];
        $q=DB::table('support_tickets')->where('customer_unique_id',$id); $count=(clone $q)->count(); $open=null;
        if(Schema::hasColumn('support_tickets','status'))$open=(clone $q)->whereNotIn(DB::raw('LOWER(status)'),['closed','resolved'])->count();
        $latest=(clone $q)->latest('id')->first();
        return ['count'=>$count,'open'=>$open,'latest'=>$latest?['id'=>$latest->id,'subject'=>$latest->subject??null,'status'=>$latest->status??null]:null];
    }
    private function summary(string $severity,CustomersInfo $customer,array $causes):string{return (match($severity){'critical'=>'গুরুতর সমস্যা শনাক্ত হয়েছে।','warning'=>'সম্ভাব্য সেবা সমস্যা শনাক্ত হয়েছে।',default=>'উপলভ্য তথ্য অনুযায়ী গুরুতর সমস্যা পাওয়া যায়নি.'}).' '.$customer->customer_unique_id.' — '.$causes[0];}
}
