<?php

namespace App\Services;

use App\Models\Param;
use App\Models\VehicleRange;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AutocomParser
{
    private $filter;
    /**
     * @var mixed
     */
    private $url;
    /**
     * @var array
     */
    private $headers;
    /**
     * @var Repository|Application|mixed
     */
    private $threads;

    private $rangesTree;
    /**
     * @var Builder[]|Collection
     */
    private $params;
    /**
     * @var false|string
     */
    private $proxies;
    /**
     * @var string
     */
    private $proxy;

    public function __construct()
    {
        $this->headers = [
            'Content-Type: application/json',
            'x-api-key: ' . config('parser.key'),
        ];

        $this->filter = [
            'page' => config('parser.page'),
            'pageSize' => config('parser.page_size'),
            'listPriceMax' => config('parser.price_max'),
            'listPriceMin' => config('parser.price_min'),
            'yearMax' => config('parser.year_max'),
            'yearMin' => config('parser.year_min'),
        ];

        $this->url = config('parser.url');

        $this->threads = config('parser.threads', 1);

        $this->proxies = json_decode(file_get_contents(public_path('proxies.json')), true);
    }

    public function setProxy()
    {
        if (!config('parser.proxy_enabled')) {
            return false;
        }

        $key = array_rand($this->proxies);

        $proxy = $this->proxies[$key];

        $encoded = $proxy['host'] . ':' . $proxy['port'];

        if ($this->__proxyChecker($encoded)) {
            $this->proxy = $encoded;
        } else {
            $this->setProxy();
        }

        return $this->proxy;
    }

    public function freshTables()
    {
        Schema::disableForeignKeyConstraints();

        DB::table('vehicle_images')->truncate();
        DB::table('param_values')->truncate();
        DB::table('params')->truncate();
        DB::table('vehicles')->truncate();
        DB::table('seller_contacts')->truncate();
        DB::table('sellers')->truncate();

        Schema::enableForeignKeyConstraints();
    }

    public function multiCurlWithRecursiveRangeV2($params, $parentId)
    {
        $dividedParams = $this->getParamsWithPriceRange($params);

        $multiHandler = curl_multi_init();

        $leftParams = [
            'year_min' => $params['year_min'],
            'year_max' => $params['year_max'],
            'price_min' => $dividedParams['lefts']['price_min'],
            'price_max' => $dividedParams['lefts']['price_max'],
        ];

        $rightParams = [
            'year_min' => $params['year_min'],
            'year_max' => $params['year_max'],
            'price_min' => $dividedParams['rights']['price_min'],
            'price_max' => $dividedParams['rights']['price_max'],
        ];

        $ch1 = $this->initializeCurlWithParams($leftParams);
        $ch2 = $this->initializeCurlWithParams($rightParams);

        curl_multi_add_handle($multiHandler, $ch1);
        curl_multi_add_handle($multiHandler, $ch2);

        $running = null;

        do {
            curl_multi_exec($multiHandler, $running);
        } while ($running);

        curl_multi_remove_handle($multiHandler, $ch1);
        curl_multi_remove_handle($multiHandler, $ch2);

        curl_multi_close($multiHandler);

        $response_1 = curl_multi_getcontent($ch1);
        $response_2 = curl_multi_getcontent($ch2);

        $response_1 = json_decode($response_1, true);
        $response_2 = json_decode($response_2, true);

        $leftParams['count'] = $this->parseCountResponse($response_1);
        $rightParams['count'] = $this->parseCountResponse($response_2);

        $leftParamsSaving = $this->saveRangeResult($leftParams, $parentId);
        $rightParamsSaving = $this->saveRangeResult($rightParams, $parentId);
        $leftParentId = $leftParamsSaving->getKey();
        $rightParentId = $rightParamsSaving->getKey();

        if ($leftParams['count'] >= 10000) {
            $this->multiCurlWithRecursiveRangeV2($leftParams, $leftParentId);
        }

        if ($rightParams['count'] >= 10000) {
            $this->multiCurlWithRecursiveRangeV2($rightParams, $rightParentId);
        }

        return [];
    }

    public function checkPriceRanges(): void
    {
        $ranges = VehicleRange::query()
            ->whereRaw('year_min = year_max')
            ->where('count', '>=', config('parser.threshold'))
            ->get();

        foreach ($ranges as $range) {
            $params = [
                'price_min' => config('parser.price_min'),
                'price_max' => config('parser.price_max'),
            ];

            $params['year_min'] = $range->year_min;
            $params['year_max'] = $range->year_max;

            $this->multiCurlWithRecursiveRangeV2($params, $range->id);
        }
    }

    public function checkRanges(): bool
    {
        $ranges = VehicleRange::query()->count();

        if ($ranges) {
            return false;
        }

        $this->multiCurlWithRecursiveRange($this->rangesTree, $this->rangesTree);

        return true;
    }

    public function setPageSize(int $pageSize): AutocomParser
    {
        $this->filter['pageSize'] = $pageSize;

        return $this;
    }

    public function setPage(int $page): AutocomParser
    {
        $this->filter['page'] = $page;

        return $this;
    }

    public function setPriceMax(?int $priceMax): AutocomParser
    {
        $this->filter['listPriceMax'] = $priceMax;

        return $this;
    }

    public function setPriceMin(?int $priceMin): AutocomParser
    {
        $this->filter['listPriceMin'] = $priceMin;

        return $this;
    }

    public function setYearMax(?int $yearMax): AutocomParser
    {
        $this->filter['yearMax'] = $yearMax;

        return $this;
    }

    public function setYearMin(?int $yearMin): AutocomParser
    {
        $this->filter['yearMin'] = $yearMin;

        return $this;
    }

    public function setSort(?string $sort = null): AutocomParser
    {
        if ($sort) {
            $this->filter['sort'] = $sort;
        }

        return $this;
    }

    public function getYearMin()
    {
        return $this->filter['yearMin'];
    }

    public function getYearMax()
    {
        return $this->filter['yearMax'];
    }

    public function getPriceMin()
    {
        return $this->filter['listPriceMin'];
    }

    public function getPriceMax()
    {
        return $this->filter['listPriceMax'];
    }

    private function getQueryForRanges(): string
    {
        return 'query ($filter: SearchFilterInput!) {listingSearch(filter: $filter) {totalPages totalEntries pageNumber pageSize}}';
    }

    /**
     * @param mixed $rangesTree
     */
    public function setRangesTree($rangesTree): void
    {
        $this->rangesTree = $rangesTree;
    }

    private function getQueryForData(): string
    {
        return 'query ($filter: SearchFilterInput!) {listingSearch(filter: $filter) {totalPages totalEntries pageNumber pageSize entries {inventory {vin inventoryDisplay {id drivetrainDescription transmissionDescription awardSlug virtualAppointments cabType adDescription financingEligible seatCount imageUrls dealerVehicleUrl make bodyStyle certifiedPreOwned cylinderCount priceDropInCents priceBadge stockNumber stockType vehicleHistoryUrl doorCount providedFeatures modelYear videoUrls engineDescription homeDelivery exteriorColor milesFromDealer fuelType sellerType interiorColor mileage spinProvider spinUrl listPrice model msrp oneOwner trim features {seating}} dealer {name customerId address {streetAddress1 city state zipCode} phones {phoneType areaCode localNumber}} vin} id priceBadge predictedPrice listedAt}}}';
    }

    public function fetchAllFromRanges(): void
    {
        ini_set('memory_limit', -1);

        $ranges = VehicleRange::query()
            ->where('count', '!=', 0)
            ->where('count', '!=', config('parser.threshold'))
            ->where('is_completed', '=', 0)
            ->get();

        $this->params = Param::all();

        foreach ($ranges as $range) {
            $pages = (int)ceil($range['count'] / config('parser.page_size'));
            $pagesArray = [];

            for ($i = 1; $i <= $pages; $i += $this->threads) {
                $pagesArray = $this->multiCurlFetch($range, $i, $pages);

                $this->parse($pagesArray);
            }

            $range->update([
                'is_completed' => true,
                'fetched_pages' => count($pagesArray),
            ]);
        }
    }

    private function parseVehicle($entry, $sellerId): array
    {
        return [
            'vehicle_id' => $entry['id'],
            'seller_id' => $sellerId,
            'vin' => $entry['inventory']['vin'],
            'url' => $this->makeVehicleOriginUrl($entry),
            'add_date' => $entry['listedAt'],
        ];
    }

    private function makeVehicleOriginUrl($entry): string
    {
        $maker =  Str::slug($entry['inventory']['inventoryDisplay']['make'] ?? null);
        $model =  Str::slug($entry['inventory']['inventoryDisplay']['model'] ?? null);
        $modelYear =  Str::slug($entry['inventory']['inventoryDisplay']['modelYear'] ?? null);
        $trim =  Str::slug($entry['inventory']['inventoryDisplay']['trim'] ?? null);
        $vin = Str::slug($entry['inventory']['vin'] ?? null);
        $id = $entry['id'];

        $uri = (!$maker ?: ($maker . '-')) . ( !$model ?: ($model . '-')) . (!$modelYear ?: ($modelYear . '-'))
            . (!$trim ?: ($trim . '-')) . (!$vin ?: $vin) . '?listingId=' . $id;

        return config('parser.origin_url') . '/' . $uri;
    }

    private function parseVehicleImages(array $items): array
    {
        $images = [];

        foreach ($items as $item) {
            $images[] = [
                'url' => $item,
            ];
        }

        return $images;
    }

    private function parseVehicleParams(array $items): array
    {
        $vehicleParams = [];

        foreach ($this->params as $param) {
            if ($items[$param->name] ?? false) {
                $vehicleParams[] = [
                    'param_id' => $param->id,
                    'data' => is_array($items[$param->name]) ? json_encode($items[$param->name]) : $items[$param->name],
                ];
            }
        }

        return $vehicleParams;
    }

    private function parseForUpdate(array $pages): void
    {
        foreach ($pages as $page) {
            $uuids = [];
            $vehicles = [];
            $vehicleParams = [];
            $params = [];

            $entries = $page['data']['listingSearch']['entries'] ?? [];

            if (!count($this->params)) {
                $paramsArray = array_keys($entries[0]['inventory']['inventoryDisplay']);
                $insertParamArray = [];

                foreach ($paramsArray as $param) {
                    $insertParamArray[] = [
                        'name' => $param
                    ];
                }

                DB::table('params')->insertOrIgnore($insertParamArray);
                $this->params = Param::all();
            }

            foreach ($entries as $entry) {
                $uuids[] = $entry['id'];
                $vehicles[] = $this->parseVehicle($entry, $entry['inventory']['dealer']['customerId']);
                $params[$entry['id']] = $this->parseVehicleParams($entry['inventory']['inventoryDisplay']);
            }

            DB::table('vehicles')->insertOrIgnore($vehicles);
            $vehicleIds = DB::table('vehicles')->whereIn('vehicle_id', $uuids)->get(['id', 'vehicle_id']);

            foreach ($vehicleIds as $vehicle) {
                if (isset($params[$vehicle->vehicle_id])) {
                    foreach ($params[$vehicle->vehicle_id] as &$vehicleParam) {
                        $vehicleParam['vehicle_id'] = $vehicle->id;
                        $vehicleParams[] = $vehicleParam;
                    }
                }
            }

            DB::table('param_values')->insertOrIgnore($vehicleParams);
        }
    }

    private function parse(array $pages): void
    {
        foreach ($pages as $page) {
            $uuids = [];
            $sellers = [];
            $sellerContacts = [];
            $vehicles = [];
            $vehicleImages = [];
            $vehicleParams = [];
            $images = [];
            $params = [];

            $entries = $page['data']['listingSearch']['entries'] ?? [];

            if (!count($this->params)) {
                $paramsArray = array_keys($entries[0]['inventory']['inventoryDisplay']);
                $insertParamArray = [];

                foreach ($paramsArray as $param) {
                    $insertParamArray[] = [
                        'name' => $param
                    ];
                }

                Param::query()->insert($insertParamArray);
                $this->params = Param::all();
            }

            foreach ($entries as $entry) {
                $uuids[] = $vehicleId = $entry['id'];
                $sellers[] = $this->parseSeller($entry['inventory']['dealer']);
                $sellerContacts = array_merge($sellerContacts, $this->parseSellerContacts($entry['inventory']['dealer']));

                $vehicles[] = $this->parseVehicle($entry, $entry['inventory']['dealer']['customerId']);

                $images[$vehicleId] = array_map(function ($value) use ($vehicleId) {
                    return [
                        'url' => $value
                    ];
                }, $entry['inventory']['inventoryDisplay']['imageUrls'] ?? []);

                $params[$vehicleId] = $this->parseVehicleParams($entry['inventory']['inventoryDisplay']);
            }

            DB::table('sellers')->insertOrIgnore($sellers);
            DB::table('seller_contacts')->insertOrIgnore($sellerContacts);
            DB::table('vehicles')->insertOrIgnore($vehicles);

            $vehicleIds = DB::table('vehicles')->whereIn('vehicle_id', $uuids)->get(['id', 'vehicle_id']);

            foreach ($vehicleIds as $vehicle) {
                if (isset($images[$vehicle->vehicle_id])) {
                    foreach ($images[$vehicle->vehicle_id] as &$vehicleImage) {
                        $vehicleImage['vehicle_id'] = $vehicle->id;
                        $vehicleImages[] = $vehicleImage;
                    }
                }

                if (isset($params[$vehicle->vehicle_id])) {
                    foreach ($params[$vehicle->vehicle_id] as &$vehicleParam) {
                        $vehicleParam['vehicle_id'] = $vehicle->id;
                        $vehicleParams[] = $vehicleParam;
                    }
                }
            }

            DB::table('vehicle_images')->insertOrIgnore($vehicleImages);
            DB::table('param_values')->insertOrIgnore($vehicleParams);
        }
    }

    private function parseSeller(array $dealer): array
    {
        return [
            'id' => $dealer['customerId'],
            'name' => $dealer['name'],
            'city' => $dealer['address']['city'],
            'state' => $dealer['address']['state'],
            'address' => $dealer['address']['streetAddress1'],
            'zip_code' => $dealer['address']['zipCode'],
        ];
    }

    private function parseSellerContacts(array $dealer): array
    {
        $sellerContacts = [];

        foreach ($dealer['phones'] as $phone) {
            $sellerContacts[] = [
                'seller_id' => $dealer['customerId'],
                'area_code' => $phone['areaCode'],
                'local_number' => $phone['localNumber'],
                'phone_type' => $phone['phoneType'],
            ];
        }

        return $sellerContacts;
    }

    private function multiCurlFetch($range, $fromPage, $total): array
    {
        $responses = [];

        $multiHandler = curl_multi_init();

        $channels = [];

        for ($i = 0; $i < $this->threads; $i++) {
            if ($fromPage > $total) {
                break;
            }
            $this->setPage($fromPage++);

            $params = [
                'year_min' => $range['year_min'],
                'year_max' => $range['year_max'],
                'price_min' => $range['price_min'],
                'price_max' => $range['price_max'],
            ];

            if (isset($range['sort'])) {
                $params['sort'] = $range['sort'];
            }

            $channels[$i] = $this->initializeCurlWithParams($params, false);

            curl_multi_add_handle($multiHandler, $channels[$i]);
        }

       if (count($channels)) {
           $running = null;

           do {
               curl_multi_exec($multiHandler, $running);
           } while ($running);

           for ($i = 0; $i < count($channels); $i++) {
               curl_multi_remove_handle($multiHandler, $channels[$i]);
           }

           curl_multi_close($multiHandler);

           for ($i = 0; $i < count($channels); $i++) {
               $responses[] = json_decode(curl_multi_getcontent($channels[$i]), true);
           }
       }

        return $responses;
    }

    private function multiCurlWithRecursiveRange(array $lefts, array $rights, int $parentId = null)
    {
        $multiHandler = curl_multi_init();

        $leftParams = [
            'year_min' => $lefts['left']['value'] ?? $lefts['value'],
            'year_max' => $lefts['right']['value'] ?? $lefts['value'],
            'price_min' => $lefts['price_min'] ?? $this->filter['listPriceMin'],
            'price_max' => $lefts['price_max'] ?? $this->filter['listPriceMax'],
        ];

        $rightParams = [
            'year_min' => $rights['left']['value'] ?? $rights['value'],
            'year_max' => $rights['right']['value'] ?? $rights['value'],
            'price_min' => $rights['price_min'] ?? $this->filter['listPriceMin'],
            'price_max' => $rights['price_max'] ?? $this->filter['listPriceMax'],
        ];

        $ch1 = $this->initializeCurlWithParams($leftParams);
        $ch2 = $this->initializeCurlWithParams($rightParams);

        curl_multi_add_handle($multiHandler, $ch1);
        curl_multi_add_handle($multiHandler, $ch2);

        $running = null;

        do {
            curl_multi_exec($multiHandler, $running);
        } while ($running);

        curl_multi_remove_handle($multiHandler, $ch1);
        curl_multi_remove_handle($multiHandler, $ch2);

        curl_multi_close($multiHandler);

        $response_1 = curl_multi_getcontent($ch1);
        $response_2 = curl_multi_getcontent($ch2);

        $response_1 = json_decode($response_1, true);
        $response_2 = json_decode($response_2, true);

        $leftParams['count'] = $this->parseCountResponse($response_1);
        $rightParams['count'] = $this->parseCountResponse($response_2);

        if ($leftParams == $rightParams) {
            $saved = $this->saveRangeResult($leftParams, $parentId);
            $newRecord = $saved->wasRecentlyCreated ?? false;
            $leftParentId = $rightParentId = $saved->getKey();
        } else {
            $newRecord = true;
            $leftParamsSaving = $this->saveRangeResult($leftParams, $parentId);
            $rightParamsSaving = $this->saveRangeResult($rightParams, $parentId);
            $leftParentId = $leftParamsSaving->getKey();
            $rightParentId = $rightParamsSaving->getKey();
        }

        if ($leftParams['count'] >= 10000 && $newRecord ) {
            $this->multiCurlWithRecursiveRange($lefts['left'] ?? $lefts, $lefts['right'] ?? $lefts, $leftParentId);
        }

        if ($rightParams['count'] >= 10000 && $newRecord) {
            $this->multiCurlWithRecursiveRange($rights['left'] ?? $rights, $rights['right'] ?? $rights, $rightParentId);
        }

        return 1;
    }

    private function initializeCurlWithParams(array $params, bool $isRangeQuery = true)
    {
        $this->setYearMax($params['year_max']);
        $this->setYearMin($params['year_min']);
        $this->setPriceMax($params['price_max']);
        $this->setPriceMin($params['price_min']);
        $this->setSort($params['sort'] ?? null);

        $body = [
            'variables' => [
                'filter' => $this->filter,
            ],
            'query' => $isRangeQuery ? $this->getQueryForRanges() : $this->getQueryForData()
        ];

        $channel = curl_init();

        curl_setopt($channel, CURLOPT_URL, $this->url);
        curl_setopt($channel, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($channel, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($channel, CURLOPT_POST, true);
        curl_setopt($channel, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($channel, CURLOPT_PROXY, $this->setProxy());

        return $channel;
    }

    private function parseCountResponse($response)
    {
        return $response['data']['listingSearch']['totalEntries'] ?? 0;
    }

    private function saveRangeResult(array $params, int $parentId = null)
    {
        $range = VehicleRange::query()->where([
            'year_min' => $params['year_min'],
            'year_max' => $params['year_max'],
            'price_max' => $params['price_max'],
            'price_min' => $params['price_min'],
        ])->first();

        if ($range) {
            return $range;
        }

        return VehicleRange::query()->updateOrCreate(
            [
                'year_min' => $params['year_min'],
                'year_max' => $params['year_max'],
                'price_max' => $params['price_max'],
                'price_min' => $params['price_min'],
            ],
            [
                'parent_id' => $parentId,
                'count' => $params['count'],
            ],
        );
    }

    private function getParamsWithPriceRange(array $params): array
    {
        $price_min = $params['price_min'];
        $price_max = $params['price_max'];

        $arrange = (int)floor(($price_max - $price_min) / 2);

        $lefts['price_min'] = $price_min;
        $lefts['price_max'] = $price_min + $arrange;

        $rights['price_min'] = $price_min + $arrange + 1;
        $rights['price_max'] = $price_max;

        return [
            'lefts' => $lefts,
            'rights' => $rights,
        ];
    }

    private function __proxyChecker($proxy)
    {
        $ch = curl_init("http://google.com");

        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        curl_setopt($ch, CURLOPT_NOBODY, true);

        $handle = curl_exec($ch);

        curl_close($ch);

        return $handle;
    }

    public function fetchNewestCars()
    {
        $pages = (int)ceil(config('parser.threshold') / config('parser.page_size'));
        $pagesArray = [];

        $range = [
            'year_min' => null,
            'year_max' => null,
            'price_min' => null,
            'price_max' => null,
            'sort' => 'LISTED_AT_DESC'
        ];

        for ($i = 1; $i <= $pages; $i += $this->threads) {
            $pagesArray = array_merge($this->multiCurlFetch($range, $i, $pages), $pagesArray);

            $this->parseForUpdate($pagesArray);
        }
    }
}
