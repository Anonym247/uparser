<?php

namespace App\Services;

use App\Models\Param;
use App\Models\ParamValue;
use App\Models\Seller;
use App\Models\SellerContact;
use App\Models\Vehicle;
use App\Models\VehicleImage;
use App\Models\VehicleRange;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            'x-api-key: ' . env('X_API_KEY'),
        ];

        $this->filter = [
            'page' => config('parser.page'),
            'pageSize' => config('parser.page_size'),
            'listPriceMax' => config('parser.price_max'),
            'listPriceMin' => config('parser.price_min'),
            'yearMax' => config('parser.year_max'),
            'yearMin' => config('parser.year_min'),
        ];

        $this->url = env('GRAPHQL_URL');

        $this->threads = config('parser.threads', 1);

        $this->proxies = json_decode(file_get_contents(public_path('proxies.json')), true);

        $this->setProxy();
    }

    public function setProxy()
    {
        if (!config('parser.proxy_enabled')) {
            return;
        }

        $key = array_rand($this->proxies);

        $proxy = $this->proxies[$key];

        $encoded = $proxy['host'] . ':' . $proxy['port'];

        if ($this->__proxyChecker($encoded)) {
            $this->proxy = $encoded;
        } else {
            $this->setProxy();
        }
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

    public function checkPriceRanges(): void
    {
        $ranges = VehicleRange::query()
            ->whereRaw('year_min = year_max')
            ->where('count', '>=', config('parser.threshold'))
            ->get();

        $params = [
            'price_min' => $this->filter['listPriceMin'],
            'price_max' => $this->filter['listPriceMax'],
        ];

        $priceRanges = $params;

        foreach ($ranges as $range) {
            while (true) {
                $priceRange = $this->getParamsWithPriceRange($params);
                $priceRange['lefts']['value'] = $range->year_min;
                $priceRange['rights']['value'] = $range->year_max;

                $params = $this->multiCurlWithRecursiveRange($priceRange['lefts'], $priceRange['rights'], $range->id, true);

                if ($params === false) {
                    $params = $priceRanges;
                    break;
                }
            }
        }
    }

    public function checkRanges(): bool
    {
        $ranges = VehicleRange::query()->count();

        if ($ranges) {
            return false;
        }

        $lefts = $this->rangesTree['left'];
        $rights = $this->rangesTree['right'];

        $this->multiCurlWithRecursiveRange($lefts, $rights);

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

    public function setPriceMax(int $priceMax): AutocomParser
    {
        $this->filter['listPriceMax'] = $priceMax;

        return $this;
    }

    public function setPriceMin(int $priceMin): AutocomParser
    {
        $this->filter['listPriceMin'] = $priceMin;

        return $this;
    }

    public function setYearMax(int $yearMax): AutocomParser
    {
        $this->filter['yearMax'] = $yearMax;

        return $this;
    }

    public function setYearMin(int $yearMin): AutocomParser
    {
        $this->filter['yearMin'] = $yearMin;

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
        return 'query ($filter: SearchFilterInput!) {listingSearch(filter: $filter) {totalPages totalEntries pageNumber pageSize entries {inventory {vin inventoryDisplay {id drivetrainDescription awardSlug virtualAppointments cabType adDescription financingEligible seatCount imageUrls dealerVehicleUrl make bodyStyle certifiedPreOwned cylinderCount priceDropInCents priceBadge stockNumber stockType vehicleHistoryUrl doorCount providedFeatures modelYear videoUrls engineDescription homeDelivery exteriorColor milesFromDealer fuelType sellerType interiorColor mileage spinProvider spinUrl listPrice model msrp oneOwner trim features {seating}} dealer {name customerId address {streetAddress1 city state zipCode} phones {phoneType areaCode localNumber}} vin} id priceBadge predictedPrice listedAt}}}';
    }

    public function fetchAllFromRanges(): void
    {
        ini_set('memory_limit', -1);

        $ranges = VehicleRange::query()
            ->where('count', '!=', 0)
            ->where('count', '!=', config('parser.threshold'))
            ->get();

        foreach ($ranges as $range) {
            $pages = (int)ceil($range['count'] / config('parser.page_size'));
            $pagesArray = [];

            for ($i = 1; $i <= $pages; $i += $this->threads) {
                $pagesArray = array_merge($this->multiCurlFetch($range, $i, $pages), $pagesArray);
            }

            $noEntries = $this->parse($pagesArray);

            $range->update([
                'is_completed' => true,
                'fetched_pages' => count($pagesArray),
                'empty_entries' => $noEntries
            ]);
        }
    }

    private function updateParams(array $paramsArray)
    {
        if (!Param::query()->count()) {
            $params = [];

            foreach ($paramsArray as $item) {
                if (!in_array($item, ['id', 'imageUrls'])) {
                    $params[] = ['name' => $item, 'created_at' => now()->toDateTimeString()];
                }
            }

            DB::table('params')->insert($params);
        }

        $this->params = Param::query()->get();
    }

    private function saveVehicle($entry, Seller $seller): Vehicle
    {
        $vehicle = Vehicle::where('vehicle_id', $entry['id'])->first();

        if ($vehicle) {
            return $vehicle;
        }

        $vehicle = new Vehicle([
            'vehicle_id' => $entry['id'],
            'seller_id' => $seller->getKey(),
            'vin' => $entry['inventory']['vin'],
            'url' => $entry['inventory']['inventoryDisplay']['dealerVehicleUrl'],
            'add_date' => $entry['listedAt'],
        ]);
        $vehicle->save();

        return $vehicle;
    }

    private function saveVehicleImages(array $items, Vehicle $vehicle)
    {
        $images = [];

        foreach ($items as $item) {
            $images[] = [
                'vehicle_id' => $vehicle->getKey(),
                'url' => $item,
                'created_at' => now()->toDateTimeString()
            ];
        }

        VehicleImage::query()->insert($images);
    }

    private function saveVehicleParams(array $items, Vehicle $vehicle)
    {
        $vehicleParams = [];

        foreach ($this->params as $param) {
            if (array_key_exists($param->name, $items)) {
                $value = is_array($items[$param->name]) ? json_encode($items[$param->name]) : $items[$param->name];

                $vehicleParams[] = [
                    'vehicle_id' => $vehicle->getKey(),
                    'param_id' => $param->id,
                    'data' => $value,
                    'created_at' => now()->toDateTimeString()
                ];
            }
        }

        ParamValue::query()->insert($vehicleParams);
    }

    private function parse(array $pages): int
    {
        $noEntries = 0;

        foreach ($pages as $page) {
            $entries = $page['data']['listingSearch']['entries'] ?? null;

            if (!$entries) {
                $noEntries++;
                continue;
            }

            //DB::transaction(function () use ($entries) {
            $paramsArray = array_keys($entries[0]['inventory']['inventoryDisplay']);
            $this->updateParams($paramsArray);

            foreach ($entries as $entry) {
                $seller = $this->firstOrCreateSeller($entry['inventory']['dealer']);

                $vehicle = $this->saveVehicle($entry, $seller);

                if (!$vehicle->wasRecentlyCreated) {
                    continue;
                }

                $this->saveVehicleImages($entry['inventory']['inventoryDisplay']['imageUrls'] ?? [], $vehicle);

                $this->saveVehicleParams($entry['inventory']['inventoryDisplay'], $vehicle);
            }
            //});
        }

        return $noEntries;
    }

    private function firstOrCreateSeller(array $dealer)
    {
        $seller = Seller::query()->where('customer_id', $dealer['customerId'])->first();

        if (!$seller) {
            $seller = new Seller([
                'customer_id' => $dealer['customerId'],
                'name' => $dealer['name'],
                'city' => $dealer['address']['city'],
                'state' => $dealer['address']['state'],
                'address' => $dealer['address']['streetAddress1'],
                'zip_code' => $dealer['address']['zipCode'],
            ]);
            $seller->save();

            $contacts = [];

            foreach ($dealer['phones'] as $phone) {
                $contacts[] = [
                    'seller_id' => $seller->getKey(),
                    'area_code' => $phone['areaCode'],
                    'local_number' => $phone['localNumber'],
                    'phone_type' => $phone['phoneType'],
                    'created_at' => now()->toDateTimeString(),
                ];
            }

            SellerContact::query()->insert($contacts);
        }

        return $seller;
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

            $channels[$i] = $this->initializeCurlWithParams([
                'year_min' => $range['year_min'],
                'year_max' => $range['year_max'],
                'price_min' => $range['price_min'],
                'price_max' => $range['price_max'],
            ], false);

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

    private function multiCurlWithRecursiveRange(array $lefts, array $rights, int $parentId = null, $priceRangeFilter = false)
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

        if ($priceRangeFilter) {
            if ($leftParams['count'] >= 10000) {
                return $leftParams;
            } else if ($rightParams['count'] >= 10000) {
                return $rightParams;
            } else {
                return false;
            }
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
        $ch = curl_init("https://google.com");

        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        curl_setopt($ch, CURLOPT_NOBODY, true);

        $handle = curl_exec($ch);

        curl_close($ch);

        return $handle;
    }
}
